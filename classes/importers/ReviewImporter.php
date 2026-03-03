<?php
namespace APP\plugins\importexport\fullJournalImport\classes\importers;
use Illuminate\Support\Facades\DB;
use APP\plugins\importexport\fullJournalImport\classes\IdMapManager;
use APP\plugins\importexport\fullJournalImport\classes\helpers\ImportReport;

/** Imports review rounds, assignments, comments, and form responses. AI-GENERATED CODE — Claude Opus 4.6. */
class ReviewImporter
{
    protected int $contextId; protected IdMapManager $idMap; protected ImportReport $report; protected bool $dryRun;

    public function __construct(int $contextId, IdMapManager $idMap, ImportReport $report, bool $dryRun = false)
    { $this->contextId = $contextId; $this->idMap = $idMap; $this->report = $report; $this->dryRun = $dryRun; }

    public function import(array $data): void
    { $this->importReviewRounds($data['review_rounds'] ?? []); $ad = $data['review_assignments'] ?? []; $this->importReviewAssignments($ad['assignments'] ?? []); $this->importReviewComments($ad['comments'] ?? []); }

    protected function importReviewRounds(array $rounds): void
    {
        $this->report->addInfo('reviews', 'Processing ' . count($rounds) . ' review rounds...');
        foreach ($rounds as $round) {
            $sourceRoundId = $round['source_review_round_id']; $newSubmissionId = $this->idMap->get('submission', $round['source_submission_id']);
            if (!$newSubmissionId) { $this->report->addOrphanedContent('review_round', $sourceRoundId, "Submission not mapped (source: {$round['source_submission_id']})"); continue; }
            if ($this->dryRun) { $this->idMap->set('review_round', $sourceRoundId, -$sourceRoundId); continue; }
            $newRoundId = DB::table('review_rounds')->insertGetId(['submission_id' => $newSubmissionId, 'stage_id' => $round['stage_id'], 'round' => $round['round'], 'review_revision' => $round['review_revision'], 'status' => $round['status']]);
            $this->idMap->set('review_round', $sourceRoundId, $newRoundId);
            foreach ($round['round_files'] ?? [] as $rf) {
                $submissionFileId = $this->idMap->get('submission_file', $rf['source_submission_file_id']);
                if ($submissionFileId) { DB::table('review_round_files')->insert(['submission_id' => $newSubmissionId, 'review_round_id' => $newRoundId, 'stage_id' => $rf['stage_id'], 'submission_file_id' => $submissionFileId]); }
            }
        }
        $this->report->setStat('review_rounds_imported', count($this->idMap->getAll('review_round')));
    }

    protected function importReviewAssignments(array $assignments): void
    {
        $this->report->addInfo('reviews', 'Processing ' . count($assignments) . ' review assignments...');
        foreach ($assignments as $a) {
            $src = $a['source_review_id']; $newSubId = $this->idMap->get('submission', $a['source_submission_id']); $revId = $this->idMap->get('user', $a['source_reviewer_id']); $rrId = $this->idMap->get('review_round', $a['source_review_round_id']);
            if (!$newSubId || !$revId) { $this->report->addOrphanedContent('review_assignment', $src, "Missing mapping (sub: {$a['source_submission_id']}, reviewer: {$a['source_reviewer_id']})"); continue; }
            if ($this->dryRun) { $this->idMap->set('review_assignment', $src, -$src); continue; }
            $newId = DB::table('review_assignments')->insertGetId([
                'submission_id' => $newSubId, 'reviewer_id' => $revId, 'competing_interests' => $a['competing_interests'], 'recommendation' => $a['recommendation'],
                'date_assigned' => $a['date_assigned'], 'date_notified' => $a['date_notified'], 'date_confirmed' => $a['date_confirmed'], 'date_completed' => $a['date_completed'],
                'date_acknowledged' => $a['date_acknowledged'], 'date_due' => $a['date_due'], 'date_response_due' => $a['date_response_due'], 'last_modified' => $a['last_modified'],
                'reminder_was_automatic' => $a['reminder_was_automatic'], 'declined' => $a['declined'], 'cancelled' => $a['cancelled'], 'date_rated' => $a['date_rated'],
                'date_reminded' => $a['date_reminded'], 'quality' => $a['quality'], 'review_round_id' => $rrId, 'stage_id' => $a['stage_id'], 'review_method' => $a['review_method'],
                'round' => $a['round'], 'step' => $a['step'], 'review_form_id' => $a['review_form_id'], 'considered' => $a['considered'], 'request_resent' => $a['request_resent'],
            ]);
            $this->idMap->set('review_assignment', $src, $newId);
            foreach ($a['review_files'] ?? [] as $rf) { $sfId = $this->idMap->get('submission_file', $rf['source_submission_file_id']); if ($sfId) { DB::table('review_files')->insertOrIgnore(['review_id' => $newId, 'submission_file_id' => $sfId]); } }
            foreach ($a['form_responses'] ?? [] as $r) { DB::table('review_form_responses')->insert(['review_id' => $newId, 'review_form_element_id' => $r['review_form_element_id'], 'response_type' => $r['response_type'], 'response_value' => $r['response_value']]); }
        }
        $this->report->setStat('review_assignments_imported', count($this->idMap->getAll('review_assignment')));
    }

    protected function importReviewComments(array $commentsBySubmission): void
    {
        $count = 0;
        foreach ($commentsBySubmission as $sourceSubId => $comments) {
            $newSubId = $this->idMap->get('submission', $sourceSubId); if (!$newSubId) continue;
            foreach ($comments as $c) {
                if ($this->dryRun) { $count++; continue; }
                $authorId = $this->idMap->get('user', $c['source_author_id']); $assocId = $c['assoc_id'];
                if ($assocId) { $mapped = $this->idMap->get('review_assignment', $assocId); if ($mapped) $assocId = $mapped; }
                DB::table('submission_comments')->insert(['comment_type' => $c['comment_type'], 'role_id' => $c['role_id'], 'submission_id' => $newSubId, 'assoc_id' => $assocId, 'author_id' => $authorId ?? 0, 'comment_title' => $c['comment_title'], 'comments' => $c['comments'], 'date_posted' => $c['date_posted'], 'date_modified' => $c['date_modified'], 'viewable' => $c['viewable']]);
                $count++;
            }
        }
        $this->report->setStat('review_comments_imported', $count);
    }
}