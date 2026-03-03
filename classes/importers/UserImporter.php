<?php
namespace APP\plugins\importexport\fullJournalImport\classes\importers;
use Illuminate\Support\Facades\DB;
use APP\plugins\importexport\fullJournalImport\classes\IdMapManager;
use APP\plugins\importexport\fullJournalImport\classes\helpers\ImportReport;
use APP\plugins\importexport\fullJournalImport\classes\helpers\SettingsWriter;

/**
 * Imports users with email-based duplicate detection and role merging.
 * CRITICAL FEATURE per spec. AI-GENERATED CODE — Claude Opus 4.6.
 */
class UserImporter
{
    protected int $contextId; protected IdMapManager $idMap; protected ImportReport $report; protected bool $dryRun; protected array $excludedPatterns;

    public function __construct(int $contextId, IdMapManager $idMap, ImportReport $report, bool $dryRun = false, array $excludedPatterns = [])
    { $this->contextId = $contextId; $this->idMap = $idMap; $this->report = $report; $this->dryRun = $dryRun; $this->excludedPatterns = $excludedPatterns; }

    public function import(array $data): void
    { $this->importUserGroups($data['user_groups'] ?? []); $this->importUsers($data['users'] ?? []); }

    protected function importUserGroups(array $exportedGroups): void
    {
        $this->report->addInfo('user_groups', 'Mapping ' . count($exportedGroups) . ' user groups...');
        $existingGroups = DB::table('user_groups')->where('context_id', $this->contextId)->get();
        $existingByRole = $existingGroups->groupBy('role_id');

        foreach ($exportedGroups as $group) {
            $sourceId = $group['source_user_group_id']; $roleId = $group['role_id'];
            $candidates = $existingByRole[$roleId] ?? collect();

            if ($candidates->count() === 1) {
                $this->idMap->set('user_group', $sourceId, $candidates->first()->user_group_id);
            } elseif ($candidates->count() > 1) {
                $sourceName = $group['settings']['name'] ?? []; $matched = false;
                foreach ($candidates as $candidate) {
                    $existingName = DB::table('user_group_settings')->where('user_group_id', $candidate->user_group_id)->where('setting_name', 'name')->pluck('setting_value', 'locale')->toArray();
                    foreach ((is_array($sourceName) ? $sourceName : []) as $locale => $name) {
                        if (isset($existingName[$locale]) && $existingName[$locale] === $name) {
                            $this->idMap->set('user_group', $sourceId, $candidate->user_group_id); $matched = true; break 2;
                        }
                    }
                }
                if (!$matched) { $this->idMap->set('user_group', $sourceId, $candidates->first()->user_group_id); $this->report->addWarning("User group {$sourceId} (role {$roleId}) had multiple matches; defaulted to {$candidates->first()->user_group_id}"); }
            } else {
                if (!$this->dryRun) {
                    $newGroupId = DB::table('user_groups')->insertGetId(['context_id' => $this->contextId, 'role_id' => $roleId, 'is_default' => $group['is_default'], 'show_title' => $group['show_title'], 'permit_self_registration' => $group['permit_self_registration'], 'permit_metadata_edit' => $group['permit_metadata_edit']]);
                    SettingsWriter::writeSettings('user_group_settings', 'user_group_id', $newGroupId, $group['settings'] ?? []);
                    foreach ($group['stage_assignments'] ?? [] as $stageId) { DB::table('user_group_stage')->insert(['user_group_id' => $newGroupId, 'stage_id' => $stageId, 'context_id' => $this->contextId]); }
                    $this->idMap->set('user_group', $sourceId, $newGroupId);
                } else { $this->report->addInfo('user_groups', "[DRY RUN] Would create new user group for source {$sourceId} (role {$roleId})"); }
            }
        }
        $this->report->setStat('user_groups_mapped', count($this->idMap->getAll('user_group')));
    }

    protected function importUsers(array $exportedUsers): void
    {
        $this->report->addInfo('users', 'Processing ' . count($exportedUsers) . ' users...'); $imported = 0; $merged = 0; $excluded = 0;
        foreach ($exportedUsers as $user) {
            $email = strtolower(trim($user['email'])); $sourceUserId = $user['source_user_id'];
            if ($this->isExcluded($email)) {
                $excluded++; $existing = DB::table('users')->where('email', $email)->first();
                if ($existing) { $this->idMap->set('user', $sourceUserId, $existing->user_id); } continue;
            }
            $existingUser = DB::table('users')->where('email', $email)->first();
            if ($existingUser) {
                $this->idMap->set('user', $sourceUserId, $existingUser->user_id); $this->checkNameConflict($user, $existingUser);
                $rolesAdded = $this->mergeRoles($user, $existingUser->user_id); $this->report->addUserMerge($email, $sourceUserId, $existingUser->user_id, $rolesAdded); $merged++;
            } else {
                if (!$this->dryRun) {
                    $newUserId = $this->createUser($user); $this->idMap->set('user', $sourceUserId, $newUserId);
                    $this->assignRoles($user, $newUserId); $this->importUserInterests($user, $newUserId);
                } else { $this->idMap->set('user', $sourceUserId, -$sourceUserId); }
                $imported++;
            }
        }
        $this->report->setStat('users_imported', $imported); $this->report->setStat('users_merged', $merged); $this->report->setStat('users_excluded', $excluded);
    }

    protected function isExcluded(string $email): bool
    {
        foreach ($this->excludedPatterns as $pattern) {
            $pattern = strtolower(trim($pattern)); if (empty($pattern)) continue;
            if ($pattern === $email) return true;
            if (str_contains($pattern, '*')) { $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i'; if (preg_match($regex, $email)) return true; }
        }
        return false;
    }

    protected function checkNameConflict(array $sourceUser, object $existingUser): void
    {
        $sourceSettings = $sourceUser['settings'] ?? []; $sourceGiven = $sourceSettings['givenName'] ?? []; $sourceFamily = $sourceSettings['familyName'] ?? [];
        $existingSettings = DB::table('user_settings')->where('user_id', $existingUser->user_id)->whereIn('setting_name', ['givenName', 'familyName'])->get();
        $existingGiven = []; $existingFamily = [];
        foreach ($existingSettings as $s) { if ($s->setting_name === 'givenName') $existingGiven[$s->locale] = $s->setting_value; if ($s->setting_name === 'familyName') $existingFamily[$s->locale] = $s->setting_value; }
        $hasConflict = false; $allLocales = array_unique(array_merge(is_array($sourceGiven) ? array_keys($sourceGiven) : [], array_keys($existingGiven)));
        foreach ($allLocales as $locale) {
            $srcGiven = is_array($sourceGiven) ? ($sourceGiven[$locale] ?? '') : ''; $srcFamily = is_array($sourceFamily) ? ($sourceFamily[$locale] ?? '') : '';
            $extGiven = $existingGiven[$locale] ?? ''; $extFamily = $existingFamily[$locale] ?? '';
            if (($srcGiven && $extGiven && strtolower($srcGiven) !== strtolower($extGiven)) || ($srcFamily && $extFamily && strtolower($srcFamily) !== strtolower($extFamily))) { $hasConflict = true; break; }
        }
        if ($hasConflict) { $this->report->addNameConflict($sourceUser['email'], ['given' => $sourceGiven, 'family' => $sourceFamily], ['given' => $existingGiven, 'family' => $existingFamily]); }
    }

    protected function mergeRoles(array $sourceUser, int $existingUserId): array
    {
        $rolesAdded = [];
        foreach ($sourceUser['roles'] ?? [] as $role) {
            $targetGroupId = $this->idMap->get('user_group', $role['source_user_group_id']); if (!$targetGroupId) continue;
            $exists = DB::table('user_user_groups')->where('user_id', $existingUserId)->where('user_group_id', $targetGroupId)->exists();
            if (!$exists) { if (!$this->dryRun) { DB::table('user_user_groups')->insert(['user_id' => $existingUserId, 'user_group_id' => $targetGroupId]); } $rolesAdded[] = "group_id:{$targetGroupId} (role:{$role['role_id']})"; }
        }
        return $rolesAdded;
    }

    protected function createUser(array $userData): int
    {
        $username = $this->generateUniqueUsername($userData['username']);
        return DB::table('users')->insertGetId([
            'username' => $username, 'email' => $userData['email'], 'password' => $userData['password'], 'url' => $userData['url'],
            'phone' => $userData['phone'], 'mailing_address' => $userData['mailing_address'], 'billing_address' => $userData['billing_address'],
            'country' => $userData['country'], 'locales' => $userData['locales'], 'date_registered' => $userData['date_registered'],
            'date_validated' => $userData['date_validated'], 'date_last_login' => $userData['date_last_login'], 'disabled' => $userData['disabled'],
            'disabled_reason' => $userData['disabled_reason'], 'inline_help' => $userData['inline_help'], 'must_change_password' => $userData['must_change_password'],
        ]);
    }

    protected function generateUniqueUsername(string $desired): string
    {
        $username = $desired; $suffix = 1;
        while (DB::table('users')->where('username', $username)->exists()) { $username = $desired . '_' . $suffix; $suffix++; }
        if ($username !== $desired) { $this->report->addWarning("Username '{$desired}' was taken; assigned '{$username}' instead."); }
        return $username;
    }

    protected function assignRoles(array $userData, int $userId): void
    {
        foreach ($userData['roles'] ?? [] as $role) {
            $targetGroupId = $this->idMap->get('user_group', $role['source_user_group_id']); if (!$targetGroupId) continue;
            DB::table('user_user_groups')->insert(['user_id' => $userId, 'user_group_id' => $targetGroupId]);
        }
    }

    protected function importUserInterests(array $userData, int $userId): void
    {
        $interests = $userData['interests'] ?? []; if (empty($interests)) return;
        $vocab = DB::table('controlled_vocabs')->where('symbolic', 'interest')->where('assoc_type', 0)->where('assoc_id', 0)->first();
        $vocabId = $vocab ? $vocab->controlled_vocab_id : DB::table('controlled_vocabs')->insertGetId(['symbolic' => 'interest', 'assoc_type' => 0, 'assoc_id' => 0]);
        foreach ($interests as $interest) {
            $existingEntry = DB::table('controlled_vocab_entries as cve')->join('controlled_vocab_entry_settings as cves', 'cves.controlled_vocab_entry_id', '=', 'cve.controlled_vocab_entry_id')
                ->where('cve.controlled_vocab_id', $vocabId)->where('cves.setting_name', 'interest')->where('cves.setting_value', $interest)->select('cve.controlled_vocab_entry_id')->first();
            if ($existingEntry) { $entryId = $existingEntry->controlled_vocab_entry_id; }
            else { $entryId = DB::table('controlled_vocab_entries')->insertGetId(['controlled_vocab_id' => $vocabId, 'seq' => 0]); DB::table('controlled_vocab_entry_settings')->insert(['controlled_vocab_entry_id' => $entryId, 'locale' => '', 'setting_name' => 'interest', 'setting_value' => $interest]); }
            if (!DB::table('user_interests')->where('user_id', $userId)->where('controlled_vocab_entry_id', $entryId)->exists()) { DB::table('user_interests')->insert(['user_id' => $userId, 'controlled_vocab_entry_id' => $entryId]); }
        }
    }
}