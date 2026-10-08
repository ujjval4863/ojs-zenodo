{**
 * Zenodo plugin settings.
 *}
<script>
    $(function() {ldelim}
        $('#zenodoSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
    {rdelim});
</script>

<form class="pkp_form" id="zenodoSettingsForm" method="post" action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
    {csrf}
    {include file="controllers/notification/inPlaceNotification.tpl" notificationId="zenodoSettingsFormNotification"}

    <div id="description">{translate key="plugins.generic.zenodo.settings.description"}</div>
    <p class="description">{translate key="plugins.generic.zenodo.settings.doiPolicy"}</p>

    {fbvFormArea id="zenodoSettings"}
        {fbvElement type="select" id="environment" from=$environmentOptions selected=$environment translate=false label="plugins.generic.zenodo.settings.environment"}

        {fbvFormSection title="plugins.generic.zenodo.settings.apiToken" description="plugins.generic.zenodo.settings.apiTokenDescription"}
            <input type="password" name="apiToken" id="apiToken" value="" autocomplete="new-password" class="textField" />
            {if $hasApiToken}
                <p class="description">{translate key="plugins.generic.zenodo.settings.apiTokenConfigured"}</p>
            {else}
                <p class="description">{translate key="plugins.generic.zenodo.settings.apiTokenMissing"}</p>
            {/if}
        {/fbvFormSection}

        {if $hasApiToken}
            {fbvFormSection list=true}
                {fbvElement type="checkbox" id="clearApiToken" checked=$clearApiToken label="plugins.generic.zenodo.settings.clearApiToken"}
            {/fbvFormSection}
        {/if}

        {fbvElement type="text" id="publisher" value=$publisher label="plugins.generic.zenodo.settings.publisher" description="plugins.generic.zenodo.settings.publisherDescription"}
        {fbvElement type="text" id="journalIssn" value=$journalIssn label="plugins.generic.zenodo.settings.journalIssn" description="plugins.generic.zenodo.settings.journalIssnDescription" size=$fbvStyles.size.MEDIUM}
        {fbvElement type="text" id="resourceVersion" value=$resourceVersion label="plugins.generic.zenodo.settings.resourceVersion" description="plugins.generic.zenodo.settings.resourceVersionDescription" size=$fbvStyles.size.SMALL}
        {fbvElement type="text" id="languageCode" value=$languageCode label="plugins.generic.zenodo.settings.languageCode" description="plugins.generic.zenodo.settings.languageCodeDescription" size=$fbvStyles.size.SMALL}

        {fbvFormSection list=true description="plugins.generic.zenodo.settings.automaticArticlePublishDescription"}
            {fbvElement type="checkbox" id="automaticArticlePublish" checked=$automaticArticlePublish label="plugins.generic.zenodo.settings.automaticArticlePublish"}
        {/fbvFormSection}

        {fbvFormSection list=true description="plugins.generic.zenodo.settings.automaticIssuePublishDescription"}
            {fbvElement type="checkbox" id="automaticIssuePublish" checked=$automaticIssuePublish label="plugins.generic.zenodo.settings.automaticIssuePublish"}
        {/fbvFormSection}

        {fbvFormSection list=true description="plugins.generic.zenodo.settings.storeReservedDoiDescription"}
            {fbvElement type="checkbox" id="storeReservedDoi" checked=$storeReservedDoi label="plugins.generic.zenodo.settings.storeReservedDoi"}
        {/fbvFormSection}
    {/fbvFormArea}

    {fbvFormButtons}
</form>
