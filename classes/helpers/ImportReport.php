<?php
/**
 * @file classes/helpers/ImportReport.php
 *
 * @brief Collects and formats import processing results into human-readable reports.
 *
 * AI-GENERATED CODE — produced by Claude Opus 4.6 with human testing and verification.
 */

namespace APP\plugins\importexport\fullJournalImport\classes\helpers;

class ImportReport
{
    protected array $sections = [];
    protected array $warnings = [];
    protected array $errors = [];
    protected array $userMerges = [];
    protected array $nameConflicts = [];
    protected array $orphanedContent = [];
    protected array $statistics = [];
    protected bool $isDryRun = false;

    public function __construct(bool $isDryRun = false) { $this->isDryRun = $isDryRun; }

    public function addInfo(string $section, string $message): void { $this->sections[$section][] = ['type' => 'info', 'message' => $message]; }
    public function addWarning(string $message): void { $this->warnings[] = $message; }
    public function addError(string $message): void { $this->errors[] = $message; }

    public function addUserMerge(string $email, int $sourceUserId, int $existingUserId, array $rolesAdded): void
    {
        $this->userMerges[] = ['email' => $email, 'source_user_id' => $sourceUserId, 'existing_user_id' => $existingUserId, 'roles_added' => $rolesAdded];
    }

    public function addNameConflict(string $email, array $sourceName, array $existingName): void
    {
        $this->nameConflicts[] = ['email' => $email, 'source_name' => $sourceName, 'existing_name' => $existingName];
    }

    public function addOrphanedContent(string $entityType, int|string $sourceId, string $reason): void
    {
        $this->orphanedContent[] = ['entity_type' => $entityType, 'source_id' => $sourceId, 'reason' => $reason];
    }

    public function setStat(string $key, int|string $value): void { $this->statistics[$key] = $value; }
    public function incrementStat(string $key, int $amount = 1): void { $this->statistics[$key] = ($this->statistics[$key] ?? 0) + $amount; }
    public function hasErrors(): bool { return !empty($this->errors); }

    public function toArray(): array
    {
        return [
            'is_dry_run' => $this->isDryRun, 'generated_at' => date('Y-m-d\TH:i:sP'), 'statistics' => $this->statistics,
            'user_merges' => $this->userMerges, 'name_conflicts' => $this->nameConflicts, 'orphaned_content' => $this->orphanedContent,
            'warnings' => $this->warnings, 'errors' => $this->errors, 'details' => $this->sections,
        ];
    }

    public function toText(): string
    {
        $lines = [];
        $lines[] = $this->isDryRun ? '=== DRY RUN REPORT ===' : '=== IMPORT REPORT ===';
        $lines[] = 'Generated: ' . date('Y-m-d H:i:s');
        $lines[] = '';

        if (!empty($this->statistics)) {
            $lines[] = '--- Statistics ---';
            foreach ($this->statistics as $key => $value) { $lines[] = "  {$key}: {$value}"; }
            $lines[] = '';
        }

        if (!empty($this->userMerges)) {
            $lines[] = '--- User Merges (Duplicates Detected) ---';
            foreach ($this->userMerges as $merge) {
                $roles = implode(', ', $merge['roles_added']);
                $lines[] = "  Email: {$merge['email']} | Source ID: {$merge['source_user_id']} -> Existing ID: {$merge['existing_user_id']} | Roles added: {$roles}";
            }
            $lines[] = '';
        }

        if (!empty($this->nameConflicts)) {
            $lines[] = '--- NAME CONFLICTS (Require Manual Resolution) ---';
            foreach ($this->nameConflicts as $conflict) {
                $srcGiven = is_array($conflict['source_name']['given']) ? implode('/', $conflict['source_name']['given']) : ($conflict['source_name']['given'] ?? '');
                $srcFamily = is_array($conflict['source_name']['family']) ? implode('/', $conflict['source_name']['family']) : ($conflict['source_name']['family'] ?? '');
                $extGiven = is_array($conflict['existing_name']['given']) ? implode('/', $conflict['existing_name']['given']) : ($conflict['existing_name']['given'] ?? '');
                $extFamily = is_array($conflict['existing_name']['family']) ? implode('/', $conflict['existing_name']['family']) : ($conflict['existing_name']['family'] ?? '');
                $lines[] = "  Email: {$conflict['email']}";
                $lines[] = "    Source name: {$srcGiven} {$srcFamily}";
                $lines[] = "    Existing name: {$extGiven} {$extFamily}";
                $lines[] = "    ACTION REQUIRED: Review and resolve manually.";
            }
            $lines[] = '';
        }

        if (!empty($this->orphanedContent)) {
            $lines[] = '--- Orphaned Content ---';
            foreach ($this->orphanedContent as $orphan) { $lines[] = "  {$orphan['entity_type']} (source ID: {$orphan['source_id']}): {$orphan['reason']}"; }
            $lines[] = '';
        }

        if (!empty($this->warnings)) {
            $lines[] = '--- Warnings ---';
            foreach ($this->warnings as $warning) { $lines[] = "  WARNING: {$warning}"; }
            $lines[] = '';
        }

        if (!empty($this->errors)) {
            $lines[] = '--- ERRORS ---';
            foreach ($this->errors as $error) { $lines[] = "  ERROR: {$error}"; }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}