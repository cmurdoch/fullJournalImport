<?php

/**
 * @file classes/importers/AnnouncementImporter.php
 *
 * Import announcements and announcement types into a journal context.
 *
 * NOTE: In OJS 3.4, announcement_types uses context_id directly,
 * NOT assoc_type/assoc_id as in OJS 3.3. The announcements table
 * still uses assoc_type/assoc_id.
 *
 * AI-GENERATED CODE — produced by Claude Opus 4.6 with human testing and verification.
 */

namespace APP\plugins\importexport\fullJournalImport\classes\importers;

use APP\plugins\importexport\fullJournalImport\classes\IdMapManager;
use APP\plugins\importexport\fullJournalImport\classes\helpers\ImportReport;
use APP\plugins\importexport\fullJournalImport\classes\helpers\SettingsWriter;
use Illuminate\Support\Facades\DB;

class AnnouncementImporter
{
    protected int $contextId;
    protected IdMapManager $idMap;
    protected ImportReport $report;
    protected bool $dryRun;

    /** @var int ASSOC_TYPE_JOURNAL constant from OJS */
    private const ASSOC_TYPE_JOURNAL = 256;

    public function __construct(int $contextId, IdMapManager $idMap, ImportReport $report, bool $dryRun)
    {
        $this->contextId = $contextId;
        $this->idMap = $idMap;
        $this->report = $report;
        $this->dryRun = $dryRun;
    }

    public function import(array $data): void
    {
        $this->importAnnouncementTypes($data['announcement_types'] ?? []);
        $this->importAnnouncements($data['announcements'] ?? []);
    }

    /**
     * Import announcement types.
     *
     * OJS 3.4 uses context_id (NOT assoc_type/assoc_id) for announcement_types.
     */
    protected function importAnnouncementTypes(array $types): void
    {
        foreach ($types as $type) {
            if ($this->dryRun) {
                $this->idMap->set('announcement_type', $type['source_type_id'], -$type['source_type_id']);
                continue;
            }
            $newTypeId = DB::table('announcement_types')->insertGetId([
                'context_id' => $this->contextId,
            ]);
            $this->idMap->set('announcement_type', $type['source_type_id'], $newTypeId);
            SettingsWriter::writeSettings('announcement_type_settings', 'type_id', $newTypeId, $type['settings'] ?? []);
        }
        $this->report->setStat('announcement_types_imported', count($this->idMap->getAll('announcement_type')));
    }

    /**
     * Import announcements.
     *
     * The announcements table still uses assoc_type/assoc_id in OJS 3.4.
     */
    protected function importAnnouncements(array $announcements): void
    {
        foreach ($announcements as $ann) {
            if ($this->dryRun) continue;
            $typeId = $ann['source_type_id'] ? $this->idMap->get('announcement_type', $ann['source_type_id']) : null;
            $newAnnId = DB::table('announcements')->insertGetId([
                'assoc_type' => self::ASSOC_TYPE_JOURNAL,
                'assoc_id' => $this->contextId,
                'type_id' => $typeId,
                'date_expire' => $ann['date_expire'],
                'date_posted' => $ann['date_posted'],
            ]);
            SettingsWriter::writeSettings('announcement_settings', 'announcement_id', $newAnnId, $ann['settings'] ?? []);
        }
        $this->report->setStat('announcements_imported', count($announcements));
    }
}