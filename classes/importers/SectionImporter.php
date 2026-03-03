<?php
namespace APP\plugins\importexport\fullJournalImport\classes\importers;

use Illuminate\Support\Facades\DB;
use APP\plugins\importexport\fullJournalImport\classes\IdMapManager;
use APP\plugins\importexport\fullJournalImport\classes\helpers\ImportReport;
use APP\plugins\importexport\fullJournalImport\classes\helpers\SettingsWriter;

/**
 * Imports/maps journal sections from export data.
 * AI-GENERATED CODE — produced by Claude Opus 4.6 with human testing and verification.
 */
class SectionImporter
{
    protected int $contextId;
    protected IdMapManager $idMap;
    protected ImportReport $report;
    protected bool $dryRun;

    public function __construct(int $contextId, IdMapManager $idMap, ImportReport $report, bool $dryRun = false)
    { $this->contextId = $contextId; $this->idMap = $idMap; $this->report = $report; $this->dryRun = $dryRun; }

    public function import(array $sections): void
    {
        $this->report->addInfo('sections', 'Processing ' . count($sections) . ' sections...');
        $existingSections = DB::table('sections')->where('journal_id', $this->contextId)->get();
        $existingByName = [];
        foreach ($existingSections as $section) {
            $names = DB::table('section_settings')->where('section_id', $section->section_id)->where('setting_name', 'title')->pluck('setting_value', 'locale')->toArray();
            foreach ($names as $locale => $name) { $existingByName[strtolower($name)][] = $section->section_id; }
        }

        foreach ($sections as $section) {
            $sourceId = $section['source_section_id'];
            $sourceNames = $section['settings']['title'] ?? [];
            $matched = false;
            if (is_array($sourceNames)) {
                foreach ($sourceNames as $locale => $name) {
                    $key = strtolower($name);
                    if (isset($existingByName[$key])) {
                        $this->idMap->set('section', $sourceId, $existingByName[$key][0]);
                        $this->report->addInfo('sections', "Mapped source section {$sourceId} ('{$name}') -> existing section {$existingByName[$key][0]}");
                        $matched = true; break;
                    }
                }
            }
            if (!$matched) {
                if (!$this->dryRun) {
                    $newSectionId = DB::table('sections')->insertGetId([
                        'journal_id' => $this->contextId, 'review_form_id' => $section['review_form_id'], 'seq' => $section['seq'],
                        'editor_restricted' => $section['editor_restricted'], 'meta_indexed' => $section['meta_indexed'],
                        'meta_reviewed' => $section['meta_reviewed'], 'abstracts_not_required' => $section['abstracts_not_required'],
                        'hide_title' => $section['hide_title'], 'hide_author' => $section['hide_author'],
                        'is_inactive' => $section['is_inactive'], 'abstract_word_count' => $section['abstract_word_count'],
                    ]);
                    SettingsWriter::writeSettings('section_settings', 'section_id', $newSectionId, $section['settings'] ?? []);
                    $this->idMap->set('section', $sourceId, $newSectionId);
                    $this->report->addInfo('sections', "Created new section {$newSectionId} for source {$sourceId}");
                } else {
                    $name = is_array($sourceNames) ? reset($sourceNames) : 'unknown';
                    $this->report->addInfo('sections', "[DRY RUN] Would create section for source {$sourceId} ('{$name}')");
                    $this->idMap->set('section', $sourceId, -$sourceId);
                }
            }
        }
        $this->report->setStat('sections_mapped', count($this->idMap->getAll('section')));
    }
}