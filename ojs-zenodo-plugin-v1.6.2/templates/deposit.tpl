{**
 * Zenodo one-click background deposit and publish form.
 *}
<script>
    $(function() {ldelim}
        $('#zenodoDepositForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
    {rdelim});
</script>

<form class="pkp_form" id="zenodoDepositForm" method="post" action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="deposit" save=true}">
    {csrf}
    {include file="controllers/notification/inPlaceNotification.tpl" notificationId="zenodoDepositFormNotification"}

    <div id="description">
        <p>{translate key="plugins.generic.zenodo.deposit.description"}</p>
        <p><strong>{translate key="plugins.generic.zenodo.deposit.environment"}</strong> {$environmentLabel|escape}</p>
        {if !$hasApiToken}
            <p><strong>{translate key="plugins.generic.zenodo.deposit.tokenWarning"}</strong></p>
        {/if}
    </div>

    {fbvFormArea id="zenodoDeposit"}
        {fbvElement type="text" id="submissionId" value=$submissionId required=true label="plugins.generic.zenodo.deposit.submissionId" description="plugins.generic.zenodo.deposit.submissionIdDescription" size=$fbvStyles.size.SMALL}
    {/fbvFormArea}

    {if $jobStatus}
        <fieldset>
            <legend>{translate key="plugins.generic.zenodo.job.currentStatus"}</legend>
            <p><strong>{translate key="common.status"}</strong> {$jobStatus.state|escape}</p>
            {if $jobStatus.message}<p>{$jobStatus.message|escape}</p>{/if}
            {if $jobStatus.updatedAt}<p class="description">{$jobStatus.updatedAt|escape}</p>{/if}
        </fieldset>
    {/if}

    {if $depositStatus}
        <fieldset>
            <legend>{translate key="plugins.generic.zenodo.deposit.currentStatus"}</legend>
            <p><strong>{translate key="plugins.generic.zenodo.deposit.zenodoId"}</strong> {$depositStatus.id|escape}</p>
            <p><strong>{translate key="common.status"}</strong> {$depositStatus.status|escape}</p>
            {if $depositStatus.doi}
                <p><strong>{translate key="plugins.generic.zenodo.deposit.reservedDoi"}</strong> {$depositStatus.doi|escape}</p>
            {/if}
            {if $depositStatus.fileName}
                <p><strong>{translate key="plugins.generic.zenodo.deposit.file"}</strong> {$depositStatus.fileName|escape}</p>
            {/if}
            {if $depositStatus.environment == 'sandbox' && $depositStatus.doi}
                <p class="description">{translate key="plugins.generic.zenodo.deposit.sandboxDoiNote"}</p>
            {elseif $depositStatus.doiStoredInOjs}
                <p class="description">{translate key="plugins.generic.zenodo.deposit.doiStored"}</p>
            {/if}
            {if $depositStatus.url}
                <p><a href="{$depositStatus.url|escape}" target="_blank" rel="noopener">{translate key="plugins.generic.zenodo.deposit.openRecord"}</a></p>
            {/if}
        </fieldset>
    {/if}

    {fbvFormButtons submitText="plugins.generic.zenodo.deposit.run" hideCancel=true}
</form>
