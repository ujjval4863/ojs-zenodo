{**
 * Backfill Zenodo issue records for previously-published OJS issues.
 *}
<script>
    $(function() {ldelim}
        $('#zenodoIssueBackfillForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
    {rdelim});
</script>

<form class="pkp_form" id="zenodoIssueBackfillForm" method="post" action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="backfillIssues" save=true}">
    {csrf}
    {include file="controllers/notification/inPlaceNotification.tpl" notificationId="zenodoIssueBackfillNotification"}

    <div id="description">
        <p>{translate key="plugins.generic.zenodo.backfill.description"}</p>
        <p><strong>{translate key="plugins.generic.zenodo.deposit.environment"}</strong> {$environmentLabel|escape}</p>
        <p>{translate key="plugins.generic.zenodo.backfill.summary" published=$publishedIssueCount backfilled=$backfilledIssueCount pending=$pendingIssueCount}</p>
        {if !$hasApiToken}
            <p><strong>{translate key="plugins.generic.zenodo.deposit.tokenWarning"}</strong></p>
        {/if}
    </div>

    <input type="hidden" name="backfillMode" id="zenodoBackfillMode" value="one" />

    {fbvFormArea id="zenodoIssueBackfill"}
        {fbvElement type="select" id="issueId" from=$issueOptions selected=$issueId translate=false label="plugins.generic.zenodo.backfill.issue" description="plugins.generic.zenodo.backfill.issueDescription"}
    {/fbvFormArea}

    {if $backfillStatus}
        <fieldset>
            <legend>{translate key="plugins.generic.zenodo.backfill.status"}</legend>
            <p><strong>{translate key="common.status"}</strong> {$backfillStatus.state|escape}</p>
            {if $backfillStatus.message}<p>{$backfillStatus.message|escape}</p>{/if}
            {if isset($backfillStatus.total)}
                <p class="description">
                    {translate key="plugins.generic.zenodo.backfill.counts" total=$backfillStatus.total completed=$backfillStatus.completed skipped=$backfillStatus.skipped failed=$backfillStatus.failed}
                </p>
            {/if}
            {if isset($backfillStatus.errors) && $backfillStatus.errors}
                <div class="pkp_notification notifyError" style="margin-top: 1em; padding: 1em;">
                    <strong>{translate key="plugins.generic.zenodo.backfill.errors"}</strong>
                    <ul>
                        {foreach from=$backfillStatus.errors item=backfillError}
                            <li>
                                <strong>{translate key="plugins.generic.zenodo.backfill.errorIssue" id=$backfillError.issueId|escape}</strong>
                                {if $backfillError.message}: {$backfillError.message|escape}{/if}
                            </li>
                        {/foreach}
                    </ul>
                </div>
            {/if}
            {if $backfillStatus.updatedAt}<p class="description">{$backfillStatus.updatedAt|escape}</p>{/if}
        </fieldset>
    {/if}

    <div class="pkp_form_buttons">
        <button type="submit" class="pkp_button" onclick="$('#zenodoBackfillMode').val('one');">
            {translate key="plugins.generic.zenodo.backfill.one"}
        </button>
        <button type="submit" class="pkp_button" onclick="$('#zenodoBackfillMode').val('all');">
            {translate key="plugins.generic.zenodo.backfill.all"}
        </button>
    </div>
</form>
