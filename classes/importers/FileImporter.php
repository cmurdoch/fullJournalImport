<?php
namespace APP\plugins\importexport\fullJournalImport\classes\importers;
use APP\plugins\importexport\fullJournalImport\classes\helpers\ImportReport;
use PKP\config\Config;

/** Handles copying physical files from the export archive into OJS file storage. AI-GENERATED CODE — Claude Opus 4.6. */
class FileImporter
{
    protected string $filesDir; protected string $importDir; protected ImportReport $report; protected bool $dryRun;
    protected int $copied = 0; protected array $errors = [];

    public function __construct(string $importDir, ImportReport $report, bool $dryRun = false)
    { $this->filesDir = Config::getVar('files', 'files_dir'); $this->importDir = $importDir; $this->report = $report; $this->dryRun = $dryRun; }

    public function importFiles(): void
    {
        $sourceFilesDir = $this->importDir . '/files';
        if (!is_dir($sourceFilesDir)) { $this->report->addInfo('files', 'No files directory found in export archive.'); return; }
        $this->report->addInfo('files', 'Copying files from export archive to OJS file storage...');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceFilesDir, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $relativePath = substr($item->getPathname(), strlen($sourceFilesDir) + 1); $destPath = $this->filesDir . '/' . $relativePath;
                if ($this->dryRun) { $this->copied++; continue; }
                $destDir = dirname($destPath); if (!is_dir($destDir)) { mkdir($destDir, 0775, true); }
                if (copy($item->getPathname(), $destPath)) { $this->copied++; } else { $this->errors[] = "Failed to copy: {$relativePath}"; $this->report->addWarning("Failed to copy file: {$relativePath}"); }
            }
        }
        $prefix = $this->dryRun ? '[DRY RUN] Would copy' : 'Copied';
        $this->report->addInfo('files', "{$prefix} {$this->copied} files."); $this->report->setStat('files_copied', $this->copied);
        if (!empty($this->errors)) { $this->report->setStat('file_copy_errors', count($this->errors)); }
    }
}