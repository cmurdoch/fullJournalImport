<?php
namespace APP\plugins\importexport\fullJournalImport\classes;

use APP\plugins\importexport\fullJournalImport\FullJournalImportPlugin;
use APP\plugins\importexport\fullJournalImport\classes\importers\UserImporter;
use APP\plugins\importexport\fullJournalImport\classes\importers\SectionImporter;
use APP\plugins\importexport\fullJournalImport\classes\importers\IssueImporter;
use APP\plugins\importexport\fullJournalImport\classes\importers\SubmissionImporter;
use APP\plugins\importexport\fullJournalImport\classes\importers\ReviewImporter;
use APP\plugins\importexport\fullJournalImport\classes\importers\AnnouncementImporter;
use APP\plugins\importexport\fullJournalImport\classes\importers\FileImporter;
use APP\plugins\importexport\fullJournalImport\classes\helpers\ImportReport;
use Illuminate\Support\Facades\DB;
use PKP\config\Config;

/**
 * Orchestrates the full journal import process.
 * AI-GENERATED CODE — produced by Claude Opus 4.6 with human testing and verification.
 */
class ImportManager
{
    protected \APP\journal\Journal $context; protected FullJournalImportPlugin $plugin;
    protected IdMapManager $idMap; protected ImportReport $report;
    protected bool $dryRun; protected string $importDir; protected array $excludedPatterns;

    public function __construct(\APP\journal\Journal $context, FullJournalImportPlugin $plugin, bool $dryRun = false, array $excludedPatterns = [])
    { $this->context = $context; $this->plugin = $plugin; $this->dryRun = $dryRun; $this->excludedPatterns = $excludedPatterns; $this->idMap = new IdMapManager(); $this->report = new ImportReport($dryRun); }

    public function import(string $archivePath, string $mode = 'full'): ImportReport
    {
        $contextId = $this->context->getId();
        $this->importDir = $this->extractArchive($archivePath);
        $this->report->addInfo('general', "Extracted archive to: {$this->importDir}");
        try {
            $manifest = $this->readJson('manifest.json'); $this->validateManifest($manifest);
            $this->report->addInfo('general', "Import mode: {$mode}"); $this->report->addInfo('general', "Dry run: " . ($this->dryRun ? 'YES' : 'NO'));
            $this->report->addInfo('general', "Source journal: {$manifest['journal_name']} ({$manifest['journal_path']})");

            if (!$this->dryRun) { DB::beginTransaction(); }
            try {
                if ($mode === 'users' || $mode === 'full') {
                    $sd = $this->readJsonIfExists('sections.json'); if ($sd) { (new SectionImporter($contextId, $this->idMap, $this->report, $this->dryRun))->import($sd); }
                    $ud = $this->readJson('users.json'); (new UserImporter($contextId, $this->idMap, $this->report, $this->dryRun, $this->excludedPatterns))->import($ud);
                }
                if ($mode === 'content' || $mode === 'full') {
                    if ($mode === 'content') { $this->buildUserMappingsFromExisting(); }
                    $id = $this->readJsonIfExists('issues.json'); if ($id) { (new IssueImporter($contextId, $this->idMap, $this->report, $this->dryRun))->import($id); }
                    $sd = $this->readJsonIfExists('submissions.json'); if ($sd) { (new SubmissionImporter($contextId, $this->idMap, $this->report, $this->dryRun))->import($sd); }
                    $rd = $this->readJsonIfExists('reviews.json'); if ($rd) { (new ReviewImporter($contextId, $this->idMap, $this->report, $this->dryRun))->import($rd); }
                    $ad = $this->readJsonIfExists('announcements.json'); if ($ad) { (new AnnouncementImporter($contextId, $this->idMap, $this->report, $this->dryRun))->import($ad); }
                    (new FileImporter($this->importDir, $this->report, $this->dryRun))->importFiles();
                }
                if (!$this->dryRun) { DB::commit(); $this->report->addInfo('general', 'Import committed successfully.'); }
            } catch (\Exception $e) {
                if (!$this->dryRun) { DB::rollBack(); $this->report->addError("Import rolled back due to error: {$e->getMessage()}"); }
                else { $this->report->addError("Dry run encountered error: {$e->getMessage()}"); }
                throw $e;
            }
        } finally { $this->removeDir($this->importDir); }
        $this->report->setStat('id_mappings', json_encode($this->idMap->getSummary()));
        return $this->report;
    }

    protected function buildUserMappingsFromExisting(): void
    {
        $ud = $this->readJsonIfExists('users.json'); if (!$ud) return;
        $sd = $this->readJsonIfExists('sections.json'); if ($sd) { (new SectionImporter($this->context->getId(), $this->idMap, $this->report, true))->import($sd); }
        (new UserImporter($this->context->getId(), $this->idMap, $this->report, true, $this->excludedPatterns))->import($ud);
        foreach ($ud['users'] ?? [] as $user) {
            $existing = DB::table('users')->where('email', strtolower(trim($user['email'])))->first();
            if ($existing) { $this->idMap->set('user', $user['source_user_id'], $existing->user_id); }
        }
    }

    protected function validateManifest(array $manifest): void
    {
        if (($manifest['plugin'] ?? '') !== 'fullJournalExport') throw new \Exception("Invalid archive: not a fullJournalExport archive");
        if (($manifest['format_version'] ?? '') !== '1.0') $this->report->addWarning("Export format version differs from expected 1.0");
    }

    protected function extractArchive(string $archivePath): string
    {
        if (!file_exists($archivePath)) throw new \Exception("Archive not found: {$archivePath}");
        $tempDir = Config::getVar('files', 'files_dir') . '/temp/ojs-import-' . uniqid(); mkdir($tempDir, 0775, true);
        $phar = new \PharData($archivePath); $phar->decompress();
        $tarPath = str_replace('.tar.gz', '.tar', $archivePath);
        if (!file_exists($tarPath)) { $tarPath = preg_replace('/\.gz$/', '', $archivePath); }
        if (file_exists($tarPath)) { $tar = new \PharData($tarPath); $tar->extractTo($tempDir); @unlink($tarPath); } else { $phar->extractTo($tempDir); }
        return $tempDir;
    }

    protected function readJson(string $filename): array
    {
        $path = $this->importDir . '/' . $filename; if (!file_exists($path)) throw new \Exception("Required file not found: {$filename}");
        $data = json_decode(file_get_contents($path), true); if (json_last_error() !== JSON_ERROR_NONE) throw new \Exception("Invalid JSON in {$filename}: " . json_last_error_msg());
        return $data;
    }

    protected function readJsonIfExists(string $filename): ?array
    {
        $path = $this->importDir . '/' . $filename; if (!file_exists($path)) return null;
        $data = json_decode(file_get_contents($path), true);
        if (json_last_error() !== JSON_ERROR_NONE) { $this->report->addWarning("Invalid JSON in {$filename}"); return null; }
        return $data;
    }

    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) { if ($item->isDir()) rmdir($item->getRealPath()); else unlink($item->getRealPath()); }
        rmdir($dir);
    }

    public function getIdMap(): IdMapManager { return $this->idMap; }
    public function getReport(): ImportReport { return $this->report; }
}