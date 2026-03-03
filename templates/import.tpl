{extends file="layouts/backend.tpl"}

{block name="page"}
<div class="pkp_page_content pkp_page_importexport">

    <h2>{translate key="plugins.importexport.fullJournalImport.name"}</h2>
    <p>{translate key="plugins.importexport.fullJournalImport.description"}</p>

    {if $errorMessage}
        <div class="pkp_notification pkp_notification_error">{$errorMessage|escape}</div>
    {/if}
    {if $successMessage}
        <div class="pkp_notification pkp_notification_success">{$successMessage|escape}</div>
    {/if}

    {if $reportText}
        <div style="margin-bottom: 2em;">
            <h3>{if $isDryRun}{translate key="plugins.importexport.fullJournalImport.dryrun.title"}{else}{translate key="plugins.importexport.fullJournalImport.report.title"}{/if}</h3>
            <pre style="background: #f5f5f5; padding: 1em; border: 1px solid #ddd; max-height: 600px; overflow-y: auto; white-space: pre-wrap; font-size: 0.9em;">{$reportText|escape}</pre>
        </div>
    {/if}

    {* Settings: Excluded Emails *}
    <div style="margin-bottom: 2em; padding: 1em; background: #f9f9f9; border: 1px solid #ddd;">
        <h3>{translate key="plugins.importexport.fullJournalImport.settings.title"}</h3>
        <p>{translate key="plugins.importexport.fullJournalImport.settings.excludedEmails.description"}</p>
        <form method="POST" action="{plugin_url path="saveSettings"}">
            {csrf}
            <div style="margin-bottom: 1em;">
                <label for="excludedEmails"><strong>{translate key="plugins.importexport.fullJournalImport.settings.excludedEmails.label"}</strong></label><br/>
                <textarea id="excludedEmails" name="excludedEmails" rows="5" cols="60" style="font-family: monospace;">{$excludedEmails|escape}</textarea>
                <br/><small>{translate key="plugins.importexport.fullJournalImport.settings.excludedEmails.help"}</small>
            </div>
            <button type="submit" class="pkp_button">{translate key="plugins.importexport.fullJournalImport.settings.save"}</button>
        </form>
    </div>

    {* Import Form *}
    <div style="margin-bottom: 2em;">
        <h3>{translate key="plugins.importexport.fullJournalImport.import.title"}</h3>
        <p>{translate key="plugins.importexport.fullJournalImport.import.description"}</p>

        <form method="POST" enctype="multipart/form-data" id="importForm">
            {csrf}

            <div style="margin-bottom: 1em;">
                <label for="importMode"><strong>{translate key="plugins.importexport.fullJournalImport.import.mode"}</strong></label><br/>
                <select id="importMode" name="importMode">
                    <option value="full">{translate key="plugins.importexport.fullJournalImport.import.mode.full"}</option>
                    <option value="users">{translate key="plugins.importexport.fullJournalImport.import.mode.users"}</option>
                    <option value="content">{translate key="plugins.importexport.fullJournalImport.import.mode.content"}</option>
                </select>
            </div>

            <div style="margin-bottom: 1em;">
                <label><strong>{translate key="plugins.importexport.fullJournalImport.import.file"}</strong></label><br/>
                {* Use OJS temporary file upload *}
                <input type="file" name="uploadedFile" accept=".tar.gz,.tgz" />
                <input type="hidden" name="temporaryFileId" id="temporaryFileId" value="" />
            </div>

            <div style="margin-bottom: 1em;">
                <button type="submit" class="pkp_button" formaction="{plugin_url path="dryrun"}">
                    {translate key="plugins.importexport.fullJournalImport.import.dryrun"}
                </button>
                <button type="submit" class="pkp_button pkp_button_primary" formaction="{plugin_url path="import"}"
                        onclick="return confirm('{translate key="plugins.importexport.fullJournalImport.import.confirm"}');">
                    {translate key="plugins.importexport.fullJournalImport.import.execute"}
                </button>
            </div>
        </form>
    </div>
</div>
{/block}