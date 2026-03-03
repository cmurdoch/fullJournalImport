<?php
namespace APP\plugins\importexport\fullJournalImport\classes\importers;
use Illuminate\Support\Facades\DB;
use APP\plugins\importexport\fullJournalImport\classes\IdMapManager;
use APP\plugins\importexport\fullJournalImport\classes\helpers\ImportReport;
use APP\plugins\importexport\fullJournalImport\classes\helpers\SettingsWriter;

/** Imports announcements and announcement types. AI-GENERATED CODE — Claude Opus 4.6. */
class AnnouncementImporter
{
    protected int $contextId; protected IdMapManager $idMap; protected ImportReport $report; protected bool $dryRun;
    protected const ASSOC_TYPE_JOURNAL = 256;

    public function __construct(int $contextId, IdMapManager $idMap, ImportReport $report, bool $dryRun = false)
    { $this->contextId = $contextId; $this->idMap = $idMap; $this->report = $report; $this->dryRun = $dryRun; }

    public function import(array $data): void { $this->importAnnouncementTypes($data['announcement_types'] ?? []); $this->importAnnouncements($data['announcements'] ?? []); }

    protected function importAnnouncementTypes(array $types): void
    {
        foreach ($types as $type) {
            if ($this->dryRun) { $this->idMap->set('announcement_type', $type['source_type_id'], -$type['source_type_id']); continue; }
            $newTypeId = DB::table('announcement_types')->insertGetId(['assoc_type' => self::ASSOC_TYPE_JOURNAL, 'assoc_id' => $this->contextId]);
            $this->idMap->set('announcement_type', $type['source_type_id'], $newTypeId);
            SettingsWriter::writeSettings('announcement_type_settings', 'type_id', $newTypeId, $type['settings'] ?? []);
        }
        $this->report->setStat('announcement_types_imported', count($this->idMap->getAll('announcement_type')));
    }

    protected function importAnnouncements(array $announcements): void
    {
        foreach ($announcements as $ann) {
            if ($this->dryRun) continue;
            $typeId = $ann['source_type_id'] ? $this->idMap->get('announcement_type', $ann['source_type_id']) : null;
            $newAnnId = DB::table('announcements')->insertGetId(['assoc_type' => self::ASSOC_TYPE_JOURNAL, 'assoc_id' => $this->contextId, 'type_id' => $typeId, 'date_expire' => $ann['date_expire'], 'date_posted' => $ann['date_posted']]);
            SettingsWriter::writeSettings('announcement_settings', 'announcement_id', $newAnnId, $ann['settings'] ?? []);
        }
        $this->report->setStat('announcements_imported', count($announcements));
    }
}