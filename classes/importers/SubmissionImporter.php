<?php
namespace APP\plugins\importexport\fullJournalImport\classes\importers;
use Illuminate\Support\Facades\DB;
use APP\plugins\importexport\fullJournalImport\classes\IdMapManager;
use APP\plugins\importexport\fullJournalImport\classes\helpers\ImportReport;
use APP\plugins\importexport\fullJournalImport\classes\helpers\SettingsWriter;

/**
 * Imports submissions with publications, authors, galleys, editorial decisions,
 * stage assignments, files, discussions, and event logs.
 * AI-GENERATED CODE — produced by Claude Opus 4.6 with human testing and verification.
 */
class SubmissionImporter
{
    protected int $contextId; protected IdMapManager $idMap; protected ImportReport $report; protected bool $dryRun;

    public function __construct(int $contextId, IdMapManager $idMap, ImportReport $report, bool $dryRun = false)
    { $this->contextId = $contextId; $this->idMap = $idMap; $this->report = $report; $this->dryRun = $dryRun; }

    public function import(array $submissions): void
    {
        $this->report->addInfo('submissions', 'Processing ' . count($submissions) . ' submissions...');
        foreach ($submissions as $submission) { $this->importSubmission($submission); }
        $this->report->setStat('submissions_imported', count($this->idMap->getAll('submission')));
    }

    protected function importSubmission(array $data): void
    {
        $srcId = $data['source_submission_id'];
        if ($this->dryRun) {
            $this->report->addInfo('submissions', "[DRY RUN] Would import submission (source ID: {$srcId})");
            $this->idMap->set('submission', $srcId, -$srcId);
            foreach ($data['publications'] ?? [] as $pub) { $this->idMap->set('publication', $pub['source_publication_id'], -$pub['source_publication_id']); }
            return;
        }

        $newSubId = DB::table('submissions')->insertGetId([
            'context_id' => $this->contextId, 'current_publication_id' => null, 'date_last_activity' => $data['date_last_activity'],
            'date_submitted' => $data['date_submitted'], 'last_modified' => $data['last_modified'], 'stage_id' => $data['stage_id'],
            'locale' => $data['locale'], 'status' => $data['status'], 'submission_progress' => $data['submission_progress'], 'work_type' => $data['work_type'],
        ]);
        $this->idMap->set('submission', $srcId, $newSubId);
        SettingsWriter::writeSettings('submission_settings', 'submission_id', $newSubId, $data['settings'] ?? []);
        $this->importSubmissionFiles($data['files'] ?? [], $newSubId);

        $currentPubMap = null;
        foreach ($data['publications'] ?? [] as $pub) {
            $newPubId = $this->importPublication($pub, $newSubId);
            if ($pub['source_publication_id'] == $data['current_publication_id']) { $currentPubMap = $newPubId; }
        }
        if ($currentPubMap) { DB::table('submissions')->where('submission_id', $newSubId)->update(['current_publication_id' => $currentPubMap]); }

        $this->importEditDecisions($data['edit_decisions'] ?? [], $newSubId);
        $this->importStageAssignments($data['stage_assignments'] ?? [], $newSubId);
        $this->importDiscussions($data['discussions'] ?? [], $newSubId);
        $this->importEventLogs($data['event_log'] ?? [], $newSubId);
        $this->report->incrementStat('submissions_processed');
    }

    protected function importSubmissionFiles(array $files, int $newSubId): void
    {
        foreach ($files as $file) {
            $srcFileId = $file['source_submission_file_id'];
            $newPhysFileId = DB::table('files')->insertGetId(['path' => $file['file_path'] ?? '', 'mimetype' => $file['file_mimetype'] ?? 'application/octet-stream']);
            $this->idMap->set('file', $file['source_file_id'], $newPhysFileId);
            $uploaderUserId = $this->idMap->get('user', $file['source_uploader_user_id']);
            $srcSrcFileId = $file['source_source_submission_file_id'] ? $this->idMap->get('submission_file', $file['source_source_submission_file_id']) : null;
            $assocId = $file['assoc_id'];
            if ($file['assoc_type'] == 517) { $assocId = $this->idMap->get('review_round', $file['assoc_id']) ?? $file['assoc_id']; }
            $newSfId = DB::table('submission_files')->insertGetId([
                'submission_id' => $newSubId, 'file_id' => $newPhysFileId, 'source_submission_file_id' => $srcSrcFileId, 'genre_id' => $file['source_genre_id'],
                'file_stage' => $file['file_stage'], 'direct_sales_price' => $file['direct_sales_price'], 'sales_type' => $file['sales_type'],
                'viewable' => $file['viewable'], 'created_at' => $file['created_at'], 'updated_at' => $file['updated_at'],
                'uploader_user_id' => $uploaderUserId, 'assoc_type' => $file['assoc_type'], 'assoc_id' => $assocId,
            ]);
            $this->idMap->set('submission_file', $srcFileId, $newSfId);
            SettingsWriter::writeSettings('submission_file_settings', 'submission_file_id', $newSfId, $file['settings'] ?? []);
            foreach ($file['revisions'] ?? [] as $rev) {
                $revPhysId = DB::table('files')->insertGetId(['path' => $rev['file_path'] ?? '', 'mimetype' => $rev['file_mimetype'] ?? 'application/octet-stream']);
                $this->idMap->set('file', $rev['source_file_id'], $revPhysId);
                DB::table('submission_file_revisions')->insert(['submission_file_id' => $newSfId, 'file_id' => $revPhysId]);
            }
        }
    }

    protected function importPublication(array $pub, int $newSubId): int
    {
        $srcPubId = $pub['source_publication_id'];
        $doiId = !empty($pub['doi']) ? $this->createDoi($pub['doi']) : null;
        $sectionId = $this->idMap->get('section', $pub['source_section_id']);
        $newPubId = DB::table('publications')->insertGetId([
            'access_status' => $pub['access_status'], 'date_published' => $pub['date_published'], 'last_modified' => $pub['last_modified'],
            'primary_contact_id' => null, 'section_id' => $sectionId, 'seq' => $pub['seq'], 'submission_id' => $newSubId,
            'status' => $pub['status'], 'url_path' => $pub['url_path'], 'version' => $pub['version'], 'doi_id' => $doiId,
        ]);
        $this->idMap->set('publication', $srcPubId, $newPubId);
        $settings = $pub['settings'] ?? [];
        if (isset($settings['issueId'])) { $tid = $this->idMap->get('issue', $settings['issueId']); if ($tid) $settings['issueId'] = (string)$tid; }
        SettingsWriter::writeSettings('publication_settings', 'publication_id', $newPubId, $settings);

        $primaryContactId = null;
        foreach ($pub['authors'] ?? [] as $author) {
            $newAuthorId = $this->importAuthor($author, $newPubId);
            if ($author['source_author_id'] == $pub['source_primary_contact_id']) { $primaryContactId = $newAuthorId; }
        }
        if ($primaryContactId) { DB::table('publications')->where('publication_id', $newPubId)->update(['primary_contact_id' => $primaryContactId]); }
        foreach ($pub['galleys'] ?? [] as $galley) { $this->importGalley($galley, $newPubId); }
        foreach ($pub['citations'] ?? [] as $citation) { $this->importCitation($citation, $newPubId); }
        foreach ($pub['categories'] ?? [] as $cat) { $tcId = $this->idMap->get('category', $cat['source_category_id']); if ($tcId) DB::table('publication_categories')->insert(['publication_id' => $newPubId, 'category_id' => $tcId]); }
        return $newPubId;
    }

    protected function importAuthor(array $author, int $newPubId): int
    {
        $ugId = $this->idMap->get('user_group', $author['source_user_group_id']);
        $newId = DB::table('authors')->insertGetId(['email' => $author['email'], 'include_in_browse' => $author['include_in_browse'], 'publication_id' => $newPubId, 'seq' => $author['seq'], 'user_group_id' => $ugId]);
        $this->idMap->set('author', $author['source_author_id'], $newId);
        SettingsWriter::writeSettings('author_settings', 'author_id', $newId, $author['settings'] ?? []);
        return $newId;
    }

    protected function importGalley(array $galley, int $newPubId): void
    {
        $sfId = $this->idMap->get('submission_file', $galley['source_submission_file_id']);
        $doiId = !empty($galley['doi']) ? $this->createDoi($galley['doi']) : null;
        $newId = DB::table('publication_galleys')->insertGetId(['locale' => $galley['locale'], 'publication_id' => $newPubId, 'label' => $galley['label'], 'submission_file_id' => $sfId, 'seq' => $galley['seq'], 'remote_url' => $galley['remote_url'], 'is_approved' => $galley['is_approved'], 'url_path' => $galley['url_path'], 'doi_id' => $doiId]);
        $this->idMap->set('galley', $galley['source_galley_id'], $newId);
        SettingsWriter::writeSettings('publication_galley_settings', 'galley_id', $newId, $galley['settings'] ?? []);
    }

    protected function importCitation(array $citation, int $newPubId): void
    {
        $newId = DB::table('citations')->insertGetId(['publication_id' => $newPubId, 'raw_citation' => $citation['raw_citation'], 'seq' => $citation['seq']]);
        SettingsWriter::writeSettings('citation_settings', 'citation_id', $newId, $citation['settings'] ?? []);
    }

    protected function importEditDecisions(array $decisions, int $newSubId): void
    {
        foreach ($decisions as $d) {
            $editorId = $this->idMap->get('user', $d['source_editor_id']); $rrId = $this->idMap->get('review_round', $d['source_review_round_id']);
            if (!$editorId) { $this->report->addOrphanedContent('edit_decision', $d['source_edit_decision_id'], "Editor (source ID: {$d['source_editor_id']}) not found"); }
            DB::table('edit_decisions')->insert(['submission_id' => $newSubId, 'review_round_id' => $rrId, 'stage_id' => $d['stage_id'], 'round' => $d['round'], 'editor_id' => $editorId ?? 0, 'decision' => $d['decision'], 'date_decided' => $d['date_decided']]);
        }
    }

    protected function importStageAssignments(array $assignments, int $newSubId): void
    {
        foreach ($assignments as $a) {
            $userId = $this->idMap->get('user', $a['source_user_id']); $ugId = $this->idMap->get('user_group', $a['source_user_group_id']);
            if (!$userId || !$ugId) { $this->report->addOrphanedContent('stage_assignment', $a['source_stage_assignment_id'], "User or group not mapped"); continue; }
            $exists = DB::table('stage_assignments')->where('submission_id', $newSubId)->where('user_group_id', $ugId)->where('user_id', $userId)->exists();
            if (!$exists) { DB::table('stage_assignments')->insert(['submission_id' => $newSubId, 'user_group_id' => $ugId, 'user_id' => $userId, 'date_assigned' => $a['date_assigned'], 'recommend_only' => $a['recommend_only'], 'can_change_metadata' => $a['can_change_metadata']]); }
        }
    }

    protected function importDiscussions(array $discussions, int $newSubId): void
    {
        foreach ($discussions as $d) {
            $newQId = DB::table('queries')->insertGetId(['assoc_type' => 1048585, 'assoc_id' => $newSubId, 'stage_id' => $d['stage_id'], 'seq' => $d['seq'], 'date_posted' => $d['date_posted'], 'date_modified' => $d['date_modified'], 'closed' => $d['closed']]);
            foreach ($d['participants'] ?? [] as $p) { $uId = $this->idMap->get('user', $p['source_user_id']); if ($uId) DB::table('query_participants')->insert(['query_id' => $newQId, 'user_id' => $uId]); }
            foreach ($d['notes'] ?? [] as $n) { $uId = $this->idMap->get('user', $n['source_user_id']); DB::table('notes')->insert(['assoc_type' => 515, 'assoc_id' => $newQId, 'user_id' => $uId ?? 0, 'date_created' => $n['date_created'], 'date_modified' => $n['date_modified'], 'title' => $n['title'], 'contents' => $n['contents']]); }
        }
    }

    protected function importEventLogs(array $logs, int $newSubId): void
    {
        foreach ($logs as $log) {
            $uId = $this->idMap->get('user', $log['source_user_id']);
            $newId = DB::table('event_log')->insertGetId(['assoc_type' => 1048585, 'assoc_id' => $newSubId, 'user_id' => $uId, 'date_logged' => $log['date_logged'], 'event_type' => $log['event_type'], 'message' => $log['message'], 'is_translated' => $log['is_translated']]);
            SettingsWriter::writeSettings('event_log_settings', 'log_id', $newId, $log['settings'] ?? []);
        }
    }

    protected function createDoi(array $doiData): int
    {
        $existing = DB::table('dois')->where('context_id', $this->contextId)->where('doi', $doiData['doi'])->first();
        if ($existing) return $existing->doi_id;
        $doiId = DB::table('dois')->insertGetId(['context_id' => $this->contextId, 'doi' => $doiData['doi'], 'status' => $doiData['status']]);
        SettingsWriter::writeSettings('doi_settings', 'doi_id', $doiId, $doiData['settings'] ?? []);
        return $doiId;
    }
}