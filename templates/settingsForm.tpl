<script>
	$(function() {ldelim}
		$('#s3StorageSettings').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
		
		// Provider change handler
		$('select[name="s3_provider"]').change(function() {
			var provider = $(this).val();
			
			if (provider === 'custom') {
				$('#s3_custom_endpoint_section').show();
			} else {
				$('#s3_custom_endpoint_section').hide();
			}
		}).trigger('change');
	{rdelim});
</script>

<form class="pkp_form" id="s3StorageSettings" method="post" action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="s3StorageSettingsFormNotification"}

	<div id="description">
		<h3>{translate key="plugins.generic.s3Storage.settings.title"}</h3>
		<p>{translate key="plugins.generic.s3Storage.settings.description"}</p>
	</div>

	{fbvFormArea id="s3StorageSettingsFormArea"}
		<h4>{translate key="plugins.generic.s3Storage.settings.title"}</h4>
		
		{fbvFormSection title="plugins.generic.s3Storage.settings.provider" for="s3_provider" required=true}
			{fbvElement type="select" id="s3_provider" from=$s3Providers selected=$s3_provider translate=false label="plugins.generic.s3Storage.settings.provider.description" required=true}
		{/fbvFormSection}

		{fbvFormSection title="plugins.generic.s3Storage.settings.customEndpoint" for="s3_custom_endpoint" id="s3_custom_endpoint_section"}
			{fbvElement type="text" id="s3_custom_endpoint" value=$s3_custom_endpoint label="plugins.generic.s3Storage.settings.customEndpoint.description"}
		{/fbvFormSection}

		{fbvFormSection title="plugins.generic.s3Storage.settings.bucket" for="s3_bucket" required=true}
			{fbvElement type="text" id="s3_bucket" value=$s3_bucket label="plugins.generic.s3Storage.settings.bucket.description" required=true}
		{/fbvFormSection}

		{fbvFormSection title="plugins.generic.s3Storage.settings.key" for="s3_key" required=true}
			{fbvElement type="text" id="s3_key" value=$s3_key label="plugins.generic.s3Storage.settings.key.description" required=true}
		{/fbvFormSection}

		{fbvFormSection title="plugins.generic.s3Storage.settings.secret" for="s3_secret" required=true}
			{fbvElement type="text" password=true id="s3_secret" value=$s3_secret label="plugins.generic.s3Storage.settings.secret.description" required=true}
		{/fbvFormSection}

		{fbvFormSection title="plugins.generic.s3Storage.settings.region" for="s3_region" required=true}
			{fbvElement type="text" id="s3_region" value=$s3_region label="plugins.generic.s3Storage.settings.region.description" required=true}
		{/fbvFormSection}

		<h4>Advanced Features</h4>

		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="s3_hybrid_mode" checked=$s3_hybrid_mode label="plugins.generic.s3Storage.settings.hybridMode.description"}
		{/fbvFormSection}

		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="s3_fallback_enabled" checked=$s3_fallback_enabled label="plugins.generic.s3Storage.settings.fallbackEnabled.description"}
		{/fbvFormSection}

		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="s3_auto_sync" checked=$s3_auto_sync label="plugins.generic.s3Storage.settings.autoSync.description"}
		{/fbvFormSection}

		<h4>{translate key="plugins.generic.s3Storage.cron.title"}</h4>

		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="s3_cron_enabled" checked=$s3_cron_enabled label="plugins.generic.s3Storage.settings.cronEnabled.description"}
		{/fbvFormSection}

		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="s3_cleanup_orphaned" checked=$s3_cleanup_orphaned label="plugins.generic.s3Storage.settings.cleanupOrphaned.description"}
		{/fbvFormSection}



		{fbvFormSection list=true title="plugins.generic.s3Storage.settings.directServing.title"}
			{fbvElement type="checkbox" id="s3_direct_serving" checked=$s3_direct_serving label="plugins.generic.s3Storage.settings.directServing.description"}
		{/fbvFormSection}

		{fbvFormSection list=true title="plugins.generic.s3Storage.settings.deleteLocal.title"}
			{fbvElement type="checkbox" id="s3_delete_local_after_sync" checked=$s3_delete_local_after_sync label="plugins.generic.s3Storage.settings.deleteLocal.description"}
		{/fbvFormSection}

		{fbvFormButtons}
	{/fbvFormArea}
</form>

<div class="separator"></div>

<div id="s3TestConnection">
	<h4>Connection Test</h4>
	<p>Test connection to your storage service with current settings.</p>
	<button id="testConnectionBtn" type="button" class="pkp_button">
		Test Connection
	</button>
	<div id="connectionResult" style="margin-top: 10px;"></div>
</div>

<div class="separator"></div>

<div id="s3MediaSync">
	<h4>{translate key="plugins.generic.s3Storage.sync.title"}</h4>
	<p>{translate key="plugins.generic.s3Storage.sync.description"}</p>
	<button id="startSyncBtn" type="button" class="pkp_button">
		{translate key="plugins.generic.s3Storage.sync.start"}
	</button>
	<div id="syncResult" style="margin-top: 10px;"></div>
</div>

<div class="separator"></div>

<div id="s3Restore">
	<h4>{translate key="plugins.generic.s3Storage.restore.title"}</h4>
	<p>{translate key="plugins.generic.s3Storage.restore.description"}</p>
	<button id="startRestoreBtn" type="button" class="pkp_button">
		{translate key="plugins.generic.s3Storage.restore.start"}
	</button>
	<div id="restoreResult" style="margin-top: 10px;"></div>
</div>

<script type="text/javascript">
(function($) {
    function testS3Connection() {
        var formData = $('#s3StorageSettings').serializeArray().reduce(function(obj, item) {
            obj[item.name] = item.value;
            return obj;
        }, {});

        $('#connectionResult').html('<div class="pkp_notification pkp_notification_info">Testing connection...</div>');

        $.post('{$actionUrls.testConnection|escape:javascript}', formData, function(response) {
            var content = response.content || {};
            var message = content.message || '{translate key="plugins.generic.s3Storage.settings.connectionTest.failed"}';
            
            // Print to console as requested
            console.log('S3 Connection Test Response:', response);

            if (response.status && content.status) {
                $('#connectionResult').html('<div class="pkp_notification pkp_notification_success">' + message + '</div>');
            } else {
                // Display the detailed error message from the server
                $('#connectionResult').html('<div class="pkp_notification pkp_notification_error">' + message + '</div>');
            }
        }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
            var errorMsg = 'AJAX Error: ' + textStatus + ' - ' + errorThrown;
            console.log(errorMsg, jqXHR.responseText);
            $('#connectionResult').html('<div class="pkp_notification pkp_notification_error">' + errorMsg + '</div>');
        });
    }

    function startMediaSync() {
        $('#syncResult').html('<div class="pkp_notification pkp_notification_info">{translate key="plugins.generic.s3Storage.sync.inProgress"}</div>');
        $.post('{$actionUrls.sync|escape:javascript}', { csrfToken: '{$csrfToken}' }, function(response) {
            var message = response.content || '{translate key="plugins.generic.s3Storage.sync.failed"}';
            if (response.status) {
                $('#syncResult').html('<div class="pkp_notification pkp_notification_success">' + message + '</div>');
            } else {
                $('#syncResult').html('<div class="pkp_notification pkp_notification_error">' + message + '</div>');
            }
        }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
            var errorMsg = 'AJAX Error: ' + textStatus + ' - ' + errorThrown;
            $('#syncResult').html('<div class="pkp_notification pkp_notification_error">' + errorMsg + '</div>');
        });
    }

    function startRestoreFromS3() {
        if (!confirm('{translate key="plugins.generic.s3Storage.restore.confirm"}')) return;
        $('#restoreResult').html('<div class="pkp_notification pkp_notification_info">{translate key="plugins.generic.s3Storage.restore.inProgress"}</div>');
        $.post('{$actionUrls.restore|escape:javascript}', { csrfToken: '{$csrfToken}' }, function(response) {
            var message = response.content || '{translate key="plugins.generic.s3Storage.restore.failed"}';
            if (response.status) {
                $('#restoreResult').html('<div class="pkp_notification pkp_notification_success">' + message + '</div>');
            } else {
                $('#restoreResult').html('<div class="pkp_notification pkp_notification_error">' + message + '</div>');
            }
        }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
            var errorMsg = 'AJAX Error: ' + textStatus + ' - ' + errorThrown;
            $('#restoreResult').html('<div class="pkp_notification pkp_notification_error">' + errorMsg + '</div>');
        });
    }

    // Attach handlers to buttons
    $(function() {
        $('#testConnectionBtn').on('click', testS3Connection);
        $('#startSyncBtn').on('click', startMediaSync);
        $('#startRestoreBtn').on('click', startRestoreFromS3);
    });
})(jQuery);
</script> 