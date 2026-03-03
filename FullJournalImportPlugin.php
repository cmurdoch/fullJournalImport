<?php
namespace APP\plugins\importexport\fullJournalImport;

use APP\core\Application;
use APP\template\TemplateManager;
use PKP\config\Config;
use PKP\core\PKPApplication;
use PKP\plugins\ImportExportPlugin;
use APP\plugins\importexport\fullJournalImport\classes\ImportManager;

/**
 * Main plugin class for the OJS 3.4 Full Journal Import Plugin.
 * AI-GENERATED CODE — produced by Claude Opus 4.6 with human testing and verification. Licensed under GPL v3.
 */
class FullJournalImportPlugin extends ImportExportPlugin
{
    public function register($category, $path, $mainContextId = null) { $s = parent::register($category, $path, $mainContextId); if ($s) $this->addLocaleData(); return $s; }
    public function getName() { return 'fullJournalImport'; }
    public function getDisplayName() { return __('plugins.importexport.fullJournalImport.name'); }
    public function getDescription() { return __('plugins.importexport.fullJournalImport.description'); }

    public function display($args, $request)
    {
        parent::display($args, $request);
        $context = $request->getContext(); if (!$context) fatalError('No journal context found.');
        $path = array_shift($args); $templateMgr = TemplateManager::getManager($request);
        switch ($path) {
            case 'saveSettings': $this->saveSettings($request); $this->showImportForm($templateMgr, $context, __('plugins.importexport.fullJournalImport.settings.saved')); break;
            case 'import': $this->handleImport($context, $request, false); break;
            case 'dryrun': $this->handleImport($context, $request, true); break;
            default: $this->showImportForm($templateMgr, $context); break;
        }
    }

    protected function showImportForm($templateMgr, $context, ?string $message = null): void
    {
        $excludedEmails = $this->getSetting($context->getId(), 'excludedEmails') ?? '';
        $templateMgr->assign(['excludedEmails' => $excludedEmails, 'journalName' => $context->getLocalizedName(), 'successMessage' => $message]);
        $templateMgr->display($this->getTemplateResource('import.tpl'));
    }

    protected function saveSettings($request): void
    { $context = $request->getContext(); $this->updateSetting($context->getId(), 'excludedEmails', trim($request->getUserVar('excludedEmails') ?? '')); }

    protected function getExcludedPatterns(int $contextId): array
    { $raw = $this->getSetting($contextId, 'excludedEmails') ?? ''; return empty($raw) ? [] : array_filter(array_map('trim', explode("\n", $raw))); }

    protected function handleImport($context, $request, bool $dryRun): void
    {
        set_time_limit(0); ini_set('memory_limit', '2G');
        $templateMgr = TemplateManager::getManager($request);
        $temporaryFileId = $request->getUserVar('temporaryFileId'); $user = $request->getUser();
        if (!$temporaryFileId) { $templateMgr->assign('errorMessage', __('plugins.importexport.fullJournalImport.error.noFile')); $this->showImportForm($templateMgr, $context); return; }
        $temporaryFileDao = \DAORegistry::getDAO('TemporaryFileDAO'); $temporaryFile = $temporaryFileDao->getTemporaryFile($temporaryFileId, $user->getId());
        if (!$temporaryFile) { $templateMgr->assign('errorMessage', __('plugins.importexport.fullJournalImport.error.fileNotFound')); $this->showImportForm($templateMgr, $context); return; }
        $archivePath = $temporaryFile->getFilePath(); $mode = $request->getUserVar('importMode') ?? 'full'; $excludedPatterns = $this->getExcludedPatterns($context->getId());
        try { $importManager = new ImportManager($context, $this, $dryRun, $excludedPatterns); $report = $importManager->import($archivePath, $mode); }
        catch (\Exception $e) { $templateMgr->assign('errorMessage', __('plugins.importexport.fullJournalImport.error.importFailed') . ': ' . $e->getMessage()); $this->showImportForm($templateMgr, $context); return; }
        $templateMgr->assign(['reportText' => $report->toText(), 'reportData' => $report->toArray(), 'isDryRun' => $dryRun]);
        $templateMgr->display($this->getTemplateResource('import.tpl'));
    }

    public function executeCLI($scriptName, &$args)
    {
        $command = array_shift($args);
        if ($command !== 'import') { $this->usage($scriptName); return; }
        $archivePath = array_shift($args); $journalPath = array_shift($args);
        if (!$archivePath || !$journalPath) { echo "Error: archive path and journal path are required.\n"; $this->usage($scriptName); return; }
        if (!file_exists($archivePath)) { echo "Error: archive not found: {$archivePath}\n"; return; }
        $mode = 'full'; $dryRun = false;
        foreach ($args as $arg) { if (str_starts_with($arg, '--mode=')) $mode = substr($arg, 7); elseif ($arg === '--dry-run') $dryRun = true; }
        if (!in_array($mode, ['full', 'users', 'content'])) { echo "Error: invalid mode. Must be: full, users, content\n"; return; }
        $journalDao = \DAORegistry::getDAO('JournalDAO'); $context = $journalDao->getByPath($journalPath);
        if (!$context) { echo "Error: journal '{$journalPath}' not found.\n"; return; }
        $excludedPatterns = $this->getExcludedPatterns($context->getId());
        echo "Starting import to: {$context->getLocalizedName()} ({$journalPath})\nArchive: {$archivePath}\nMode: {$mode}\nDry run: " . ($dryRun ? 'YES' : 'NO') . "\n\n";
        set_time_limit(0); ini_set('memory_limit', '2G');
        try { $importManager = new ImportManager($context, $this, $dryRun, $excludedPatterns); $report = $importManager->import($archivePath, $mode); }
        catch (\Exception $e) { echo "ERROR: {$e->getMessage()}\n" . $e->getTraceAsString() . "\n"; return; }
        echo "\n" . $report->toText() . "\n";
        $reportPath = dirname($archivePath) . '/import-report-' . date('Y-m-d_His') . '.txt'; file_put_contents($reportPath, $report->toText()); echo "Report saved to: {$reportPath}\n";
        $reportJsonPath = dirname($archivePath) . '/import-report-' . date('Y-m-d_His') . '.json'; file_put_contents($reportJsonPath, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); echo "JSON report saved to: {$reportJsonPath}\n";
    }

    public function usage($scriptName)
    {
        echo "OJS 3.4 Full Journal Import Plugin\n==================================\n\n";
        echo "Usage:\n  php {$scriptName} {$this->getName()} import <archive.tar.gz> <journal_path> [options]\n\n";
        echo "Options:\n  --mode=full|users|content  Import mode (default: full)\n  --dry-run                  Validate without importing\n\n";
        echo "Modes:\n  full     Import users and content (default)\n  users    Import only users and their roles\n  content  Import only content (users must already exist)\n\n";
        echo "Examples:\n  php {$scriptName} {$this->getName()} import export.tar.gz myjournal --dry-run\n  php {$scriptName} {$this->getName()} import export.tar.gz myjournal --mode=users\n  php {$scriptName} {$this->getName()} import export.tar.gz myjournal --mode=content\n\n";
    }
}