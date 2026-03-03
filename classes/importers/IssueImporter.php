<?php
namespace APP\plugins\importexport\fullJournalImport\classes\importers;

use Illuminate\Support\Facades\DB;
use APP\plugins\importexport\fullJournalImport\classes\IdMapManager;
use APP\plugins\importexport\fullJournalImport\classes\helpers\ImportReport;
use APP\plugins\importexport\fullJournalImport\classes\helpers\SettingsWriter;

/**
 * Imports journal issues with metadata, settings, galleys, and ordering.
 * AI-GENERATED CODE — produced by Claude Opus 4.6 with human testing and verification.
 */
class IssueImporter
{
    protected int $contextId; protected IdMapManager $idMap; protected ImportReport $report; protected bool $dryRun;

    public function __construct(int $contextId, IdMapManager $idMap, ImportReport $report, bool $dryRun = false)
    { $this->contextId = $contextId; $this->idMap = $idMap; $this->report = $report; $this->dryRun = $dryRun; }

    public function import(array $issues): void
    {
        $this->report->addInfo('issues', 'Processing ' . count($issues) . ' issues...');
        foreach ($issues as $issue) {
            $sourceId = $issue['source_issue_id'];
            if ($this->dryRun) {
                $this->report->addInfo('issues', "[DRY RUN] Would import issue: Vol {$issue['volume']}, No {$issue['number']} ({$issue['year']})");
                $this->idMap->set('issue', $sourceId, -$sourceId); continue;
            }
            $doiId = null;
            if (!empty($issue['doi'])) { $doiId = $this->createDoi($issue['doi']); }
            $newIssueId = DB::table('issues')->insertGetId([
                'journal_id' => $this->contextId, 'volume' => $issue['volume'], 'number' => $issue['number'], 'year' => $issue['year'],
                'published' => $issue['published'], 'date_published' => $issue['date_published'], 'date_notified' => $issue['date_notified'],
                'last_modified' => $issue['last_modified'], 'access_status' => $issue['access_status'], 'open_access_date' => $issue['open_access_date'],
                'show_volume' => $issue['show_volume'], 'show_number' => $issue['show_number'], 'show_year' => $issue['show_year'],
                'show_title' => $issue['show_title'], 'style_file_name' => $issue['style_file_name'],
                'original_style_file_name' => $issue['original_style_file_name'], 'url_path' => $issue['url_path'], 'doi_id' => $doiId,
            ]);
            $this->idMap->set('issue', $sourceId, $newIssueId);
            SettingsWriter::writeSettings('issue_settings', 'issue_id', $newIssueId, $issue['settings'] ?? []);
            foreach ($issue['custom_section_orders'] ?? [] as $order) {
                $targetSectionId = $this->idMap->get('section', $order['source_section_id']);
                if ($targetSectionId) { DB::table('custom_section_orders')->insert(['issue_id' => $newIssueId, 'section_id' => $targetSectionId, 'seq' => $order['seq']]); }
            }
            $this->report->addInfo('issues', "Imported issue {$newIssueId} (Vol {$issue['volume']}, No {$issue['number']}, {$issue['year']}) from source {$sourceId}");
        }
        $this->report->setStat('issues_imported', count($this->idMap->getAll('issue')));
    }

    protected function createDoi(array $doiData): int
    {
        $existing = DB::table('dois')->where('context_id', $this->contextId)->where('doi', $doiData['doi'])->first();
        if ($existing) { return $existing->doi_id; }
        $doiId = DB::table('dois')->insertGetId(['context_id' => $this->contextId, 'doi' => $doiData['doi'], 'status' => $doiData['status']]);
        SettingsWriter::writeSettings('doi_settings', 'doi_id', $doiId, $doiData['settings'] ?? []);
        return $doiId;
    }
}