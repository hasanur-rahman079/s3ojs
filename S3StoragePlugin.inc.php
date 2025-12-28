<?php

/**
 * @file plugins/generic/s3Storage/S3StoragePlugin.inc.php
 *
 * Copyright (c) 2023 OJS/PKP
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class S3StoragePlugin
 *
 * @ingroup plugins_generic_s3Storage
 *
 * @brief S3-compatible Storage plugin for OJS 3.5+ with hybrid mode and fallback support
 */

namespace APP\plugins\generic\s3ojs;

use APP\core\Application;
use APP\template\TemplateManager;
use APP\facades\Repo;
use PKP\config\Config;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

require_once(dirname(__FILE__) . '/vendor/autoload.php');

class S3StoragePlugin extends GenericPlugin
{
    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        
        if (Application::isUnderMaintenance()) {
            return $success;
        }
        
        $isEnabled = $this->getEnabled($mainContextId);

        if ($success && $isEnabled) {
            // Register the S3 file manager
            Hook::add('FileManager::getFileManager', [$this, 'getFileManager']);

            // Register scheduled tasks hook
            Hook::add('Scheduler::execute', [$this, 'scheduledTasks']);

            // Auto-sync hook for uploaded files
            Hook::add('SubmissionFile::add', [$this, 'autoSyncFile']);
            
            // File download hooks - redirect to S3 presigned URLs
            Hook::add('File::download', [$this, 'handleFileDownload']);
            Hook::add('FileManager::downloadFile', [$this, 'handleFileManagerDownload']);
            
            // If delete local is enabled, hook into the filesystem adapter to use S3
            if ($this->getSetting(0, 's3_delete_local_after_sync')) {
                Hook::add('File::adapter', [$this, 'configureS3Adapter']);
            }
        }
        return $success;
    }

    /**
     * Site-wide plugins should override this function to return true.
     *
     * @return bool
     */
    public function isSitePlugin()
    {
        return true;
    }

    /**
     * Get the display name of this plugin.
     *
     * @return String
     */
    public function getDisplayName()
    {
        return __('plugins.generic.s3Storage.displayName');
    }

    /**
     * Get the name of this plugin.
     *
     * @return String
     */
    public function getName()
    {
        return 's3ojs';
    }

    /**
     * Get a description of the plugin.
     *
     * @return String
     */
    public function getDescription()
    {
        return __('plugins.generic.s3Storage.description');
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $router = $request->getRouter();
        $actions[] = new LinkAction(
            'settings',
            new AjaxModal(
                $router->url($request, null, null, 'manage', null, ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        );

        return $actions;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        switch ($request->getUserVar('verb')) {
            case 'settings':
                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->registerPlugin('function', 'plugin_url', [$this, 'smartyPluginUrl']);

                require_once(dirname(__FILE__) . '/S3StorageSettingsForm.inc.php');
                // Use context_id = 0 for site-wide settings
                $form = new S3StorageSettingsForm($this, 0);

                if ($request->getUserVar('save')) {
                    $form->readInputData();
                    if ($form->validate()) {
                        $form->execute();
                        return new JSONMessage(true);
                    }
                    // If validation failed, return form with errors
                    return new JSONMessage(true, $form->fetch($request));
                } else {
                    $form->initData();
                    return new JSONMessage(true, $form->fetch($request));
                }

                // no break
            case 'sync':
                return $this->handleSync($request);

            case 'restore':
                return $this->handleRestore($request);

            case 'testConnection':
                return $this->handleTestConnection($request);

            case 'stats':
                return $this->handleStats($request);
        }
        return parent::manage($args, $request);
    }

    /**
     * Handle media library sync
     *
     * @param PKPRequest $request
     *
     * @return JSONMessage
     */
    private function handleSync($request)
    {
        require_once(dirname(__FILE__) . '/S3FileManager.inc.php');
        // Use site-wide settings (contextId = 0)
        $fileManager = $this->getS3FileManager(0);

        if (!$fileManager) {
            $errorMessage = 'Sync failed: S3FileManager could not be initialized. Check settings.';
            return new JSONMessage(false, $errorMessage);
        }

        // Get all journals and sync each
        $journalDao = DAORegistry::getDAO('JournalDAO');
        $journals = $journalDao->getAll(true); // true = only enabled
        
        $totalSuccess = 0;
        $totalFailed = 0;
        $errors = [];
        
        while ($journal = $journals->next()) {
            $journalId = $journal->getId();
            $filesDir = Config::getVar('files', 'files_dir') . '/journals/' . $journalId;
            // Use journal ID (not path) in S3 to match database paths
            $s3Prefix = 'journals/' . $journalId;
            
            if (!is_dir($filesDir)) {
                continue; // Skip journals without files
            }
            
            // Perform sync with journal path prefix
            $results = $fileManager->syncToCloud($filesDir, $s3Prefix);
            $totalSuccess += $results['success'];
            $totalFailed += $results['failed'];
            if (!empty($results['errors'])) {
                $errors = array_merge($errors, $results['errors']);
            }
        }

        if ($totalFailed > 0) {
            $errorMessage = __('plugins.generic.s3Storage.sync.failed') . ': ' . implode(', ', array_slice($errors, 0, 3));
            return new JSONMessage(false, $errorMessage);
        }

        $successMessage = __('plugins.generic.s3Storage.sync.completed') . " ({$totalSuccess} files synced across all journals)";
        return new JSONMessage(true, $successMessage);
    }

    /**
     * Handle connection test
     *
     * @param PKPRequest $request
     *
     * @return JSONMessage
     */
    private function handleTestConnection($request)
    {
        // Site-wide plugin - no context needed for connection test

        // Get settings from the form submission for the test
        $bucket = $request->getUserVar('s3_bucket');
        $key = $request->getUserVar('s3_key');
        $secret = $request->getUserVar('s3_secret');
        $region = $request->getUserVar('s3_region');
        $provider = $request->getUserVar('s3_provider');
        $customEndpoint = $request->getUserVar('s3_custom_endpoint');

        // For custom provider, if region is not provided, use a default one
        // as it's required by the AWS SDK.
        if ($provider === 'custom' && empty($region)) {
            $region = 'us-east-1'; // A common default
        }

        error_log('S3StoragePlugin: Testing connection with params - Provider: ' . $provider . ', Region: ' . $region . ', Endpoint: ' . $customEndpoint);

        try {
            require_once(dirname(__FILE__) . '/S3FileManager.inc.php');
            $fileManager = new \APP\plugins\generic\s3ojs\S3FileManager($bucket, $key, $secret, $region, $provider, $customEndpoint, false, false);

            $connectionResult = $fileManager->testConnection();

            if ($connectionResult === true) {
                return new JSONMessage(true, ['status' => true, 'message' => __('plugins.generic.s3Storage.settings.connectionTest.success')]);
            } else {
                // Failure, $connectionResult contains the error message string
                error_log('S3StoragePlugin: handleTestConnection failed. Reason: ' . $connectionResult);
                return new JSONMessage(true, ['status' => false, 'message' => $connectionResult]);
            }
        } catch (Exception $e) {
            $errorMessage = 'Connection test threw a fatal exception: ' . $e->getMessage();
            error_log('S3StoragePlugin: ' . $errorMessage);
            return new JSONMessage(true, ['status' => false, 'message' => $errorMessage]);
        }
    }

    /**
     * Handle storage statistics
     *
     * @param PKPRequest $request
     *
     * @return JSONMessage
     */
    private function handleStats($request)
    {
        $context = $request->getContext();
        $fileManager = $this->getS3FileManager($context->getId());

        if (!$fileManager) {
            return new JSONMessage(false, 'Storage not configured');
        }

        $stats = $fileManager->getStorageStats();
        return new JSONMessage(true, $stats);
    }

    /**
     * Hook callback: register the S3 file manager
     *
     * @param $hookName string
     * @param $args array
     */
    public function getFileManager($hookName, $args)
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();

        if (!$context) {
            // If we can't determine the context, we can't get the correct settings.
            return false;
        }
        $contextId = $context->getId();

        if ($this->getEnabled($contextId)) {
            $fileManager = $this->getS3FileManager($contextId);
            if ($fileManager) {
                $args[0] = $fileManager;
                return true;
            }
        }
        return false;
    }

    /**
     * Get S3 file manager instance
     *
     * @param int $contextId Context ID (optional)
     *
     * @return S3FileManager|null
     */
    private function getS3FileManager($contextId = null)
    {
        if ($contextId === null) {
            $contextId = CONTEXT_ID_NONE;
        }

        $bucket = $this->getSetting($contextId, 's3_bucket');
        $key = $this->getSetting($contextId, 's3_key');
        $secret = $this->getSetting($contextId, 's3_secret');
        $region = $this->getSetting($contextId, 's3_region');
        $provider = $this->getSetting($contextId, 's3_provider') ?: 'aws';
        $customEndpoint = $this->getSetting($contextId, 's3_custom_endpoint');
        $hybridMode = $this->getSetting($contextId, 's3_hybrid_mode');
        $fallbackEnabled = $this->getSetting($contextId, 's3_fallback_enabled');

        // Basic credentials check
        if (empty($bucket) || empty($key) || empty($secret)) {
            error_log('S3StoragePlugin: Cannot initialize S3FileManager - missing bucket, key, or secret.');
            return null;
        }

        // Region is required for all providers except 'custom'
        if ($provider !== 'custom' && empty($region)) {
            error_log('S3StoragePlugin: Cannot initialize S3FileManager - region is required for provider "' . $provider . '".');
            return null;
        }

        // For custom provider, if region is not provided, use a default one.
        if ($provider === 'custom' && empty($region)) {
            $region = 'us-east-1'; // A common default
        }

        require_once(dirname(__FILE__) . '/S3FileManager.inc.php');
        return new \APP\plugins\generic\s3ojs\S3FileManager($bucket, $key, $secret, $region, $provider, $customEndpoint, $hybridMode, $fallbackEnabled);
    }

    /**
     * Hook callback: Configure S3 as the filesystem adapter
     * This allows OJS to read files directly from S3 when local files are deleted
     *
     * @param $hookName string
     * @param $args array [&$adapter, $fileService]
     * @return bool
     */
    public function configureS3Adapter($hookName, $args)
    {
        error_log('S3StoragePlugin: configureS3Adapter called');
        
        $bucket = $this->getSetting(0, 's3_bucket');
        $key = $this->getSetting(0, 's3_key');
        $secret = $this->getSetting(0, 's3_secret');
        $region = $this->getSetting(0, 's3_region');
        $provider = $this->getSetting(0, 's3_provider') ?: 'aws';
        $customEndpoint = $this->getSetting(0, 's3_custom_endpoint');

        $hybridMode = (bool) $this->getSetting(0, 's3_hybrid_mode');
        $fallbackEnabled = (bool) $this->getSetting(0, 's3_fallback_enabled');

        if (empty($bucket) || empty($key) || empty($secret)) {
            error_log('S3StoragePlugin: Missing credentials for S3 adapter');
            return false;
        }

        try {
            // Load the plugin's composer autoloader (includes Flysystem S3 adapter)
            require_once(dirname(__FILE__) . '/vendor/autoload.php');
            require_once(dirname(__FILE__) . '/S3HybridAdapter.inc.php');

            // Build S3 client config
            $config = [
                'version' => 'latest',
                'region' => $region ?: 'us-east-1',
                'credentials' => [
                    'key' => $key,
                    'secret' => $secret,
                ],
            ];

            // Custom endpoint for non-AWS providers
            if ($provider !== 'aws' && !empty($customEndpoint)) {
                $config['endpoint'] = $customEndpoint;
                $config['use_path_style_endpoint'] = true;
            }

            $s3Client = new \Aws\S3\S3Client($config);
            $s3Adapter = new \League\Flysystem\AwsS3V3\AwsS3V3Adapter($s3Client, $bucket);

            // Default adapter (local) is passed in $args[0]
            $localAdapter = $args[0];

            // Wrap in hybrid adapter
            $args[0] = new S3HybridAdapter($s3Adapter, $localAdapter, $hybridMode, $fallbackEnabled);

            error_log('S3StoragePlugin: S3 Hybrid Flysystem adapter configured successfully (Hybrid: ' . ($hybridMode ? 'Yes' : 'No') . ', Fallback: ' . ($fallbackEnabled ? 'Yes' : 'No') . ')');

            return true;
        } catch (\Exception $e) {
            error_log('S3StoragePlugin: Failed to configure S3 adapter: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Hook callback: Auto-sync files when uploaded
     * Called when SubmissionFile::add hook is fired
     *
     * @param $hookName string
     * @param $args array Contains the SubmissionFile object
     */
    public function autoSyncFile($hookName, $args)
    {
        $submissionFile = $args[0];
        
        // Get context from the request (needed for journal path)
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        
        if (!$context) {
            return false;
        }
        
        // Check if auto-sync is enabled (site-wide setting, contextId = 0)
        $autoSyncEnabled = $this->getSetting(0, 's3_auto_sync');
        if (!$autoSyncEnabled) {
            return false;
        }
        
        // Get S3 file manager (site-wide settings)
        $fileManager = $this->getS3FileManager(0);
        if (!$fileManager) {
            return false;
        }
        
        // Get the file path from the submission file
        $filesDir = Config::getVar('files', 'files_dir');
        $relativePath = $submissionFile->getData('path');
        
        if (empty($relativePath)) {
            return false;
        }
        
        $fullPath = $filesDir . '/' . $relativePath;
        
        // Use the same path in S3 as in the database so OJS Flysystem can find it
        // This is critical when "delete local files" is enabled
        $s3Key = $relativePath;
        
        if (file_exists($fullPath)) {
            $uploadResult = $fileManager->uploadToCloud($fullPath, $s3Key);
            
            // If upload was successful and delete local setting is enabled, delete local file
            if ($uploadResult && $this->getSetting(0, 's3_delete_local_after_sync')) {
                // Verify file exists in S3 before deleting local copy
                if ($fileManager->fileExistsInCloud($s3Key)) {
                    unlink($fullPath);
                    
                    // Also try to remove empty parent directories
                    $this->cleanupEmptyDirectories(dirname($fullPath), $filesDir);
                }
            }
            
            return $uploadResult;
        }
        
        return false;
    }
    
    /**
     * Remove empty parent directories up to files_dir
     * 
     * @param string $dir Directory to check
     * @param string $stopDir Stop removing at this directory
     */
    private function cleanupEmptyDirectories($dir, $stopDir)
    {
        while ($dir !== $stopDir && is_dir($dir)) {
            $files = scandir($dir);
            // Check if directory only contains . and ..
            if (count($files) <= 2) {
                @rmdir($dir);
                $dir = dirname($dir);
            } else {
                break;
            }
        }
    }

    /**
     * Hook callback: Handle scheduled tasks
     *
     * @param $hookName string
     * @param $args array
     */
    public function scheduledTasks($hookName, $args)
    {
        $taskName = $args[0];

        if ($taskName === 's3_storage_maintenance') {
            $this->runMaintenanceTasks();
        }
    }

    /**
     * Run maintenance tasks (cleanup, sync, etc.)
     */
    private function runMaintenanceTasks()
    {
        $contextDao = Application::getContextDAO();
        $contexts = $contextDao->getAll();

        while ($context = $contexts->next()) {
            if ($this->getEnabled($context->getId())) {
                $fileManager = $this->getS3FileManager($context->getId());

                if ($fileManager && $this->getSetting($context->getId(), 's3_cleanup_orphaned')) {
                    $validFiles = $this->getValidFilesFromDatabase($context);
                    $fileManager->cleanupOrphanedFiles($validFiles);
                }

                if ($fileManager && $this->getSetting($context->getId(), 's3_auto_sync')) {
                    $filesDir = Config::getVar('files', 'files_dir') . '/journals/' . $context->getId();
                    if (is_dir($filesDir)) {
                        $fileManager->syncToCloud($filesDir);
                    }
                }
            }
        }
    }

    /**
     * Handle File::download hook - redirect to S3 presigned URL
     * This hook is called by PKPFileService::download()
     *
     * @param $hookName string
     * @param $args array [$file, &$filename, $inline]
     * @return bool True to prevent default behavior
     */
    public function handleFileDownload($hookName, $args)
    {
        $file = $args[0];
        $filename = &$args[1];
        $inline = $args[2] ?? false;
        
        // Get the file path
        $filePath = $file->path ?? null;
        if (!$filePath) {
            return false; // Let default handler take over
        }
        
        // Get site-wide settings (context_id = 0 for site-wide)
        $s3DirectServing = $this->getSetting(0, 's3_direct_serving');
        if (!$s3DirectServing) {
            return false; // Direct serving not enabled
        }
        
        // Build S3 key - extract journal path from file path
        // File path format: journals/{id}/articles/...
        $s3Key = $this->buildS3KeyFromPath($filePath);
        
        // Get S3 file manager
        $fileManager = $this->getS3FileManager(0);
        if (!$fileManager) {
            return false;
        }
        
        // Check if file exists in S3
        if (!$fileManager->fileExistsInCloud($s3Key)) {
            return false; // File not in S3, use local
        }
        
        // Generate presigned URL and redirect
        $presignedUrl = $fileManager->getTemporaryUrl($s3Key, 3600);
        if ($presignedUrl) {
            header('Location: ' . $presignedUrl);
            exit;
        }
        
        return false;
    }

    /**
     * Handle FileManager::downloadFile hook - redirect to S3 presigned URL
     * This hook is called by FileManager::downloadByPath()
     *
     * @param $hookName string  
     * @param $args array [&$filePath, &$mediaType, &$inline, &$result, &$fileName]
     * @return bool True to prevent default behavior
     */
    public function handleFileManagerDownload($hookName, $args)
    {
        $filePath = &$args[0];
        $result = &$args[3];
        
        // Get site-wide settings
        $s3DirectServing = $this->getSetting(0, 's3_direct_serving');
        if (!$s3DirectServing) {
            return false;
        }
        
        // Extract relative path from full file path
        $filesDir = Config::getVar('files', 'files_dir');
        $relativePath = str_replace($filesDir . '/', '', $filePath);
        
        // Build S3 key
        $s3Key = $this->buildS3KeyFromPath($relativePath);
        
        // Get S3 file manager
        $fileManager = $this->getS3FileManager(0);
        if (!$fileManager) {
            return false;
        }
        
        // Check if file exists in S3
        if (!$fileManager->fileExistsInCloud($s3Key)) {
            return false;
        }
        
        // Generate presigned URL and redirect
        $presignedUrl = $fileManager->getTemporaryUrl($s3Key, 3600);
        if ($presignedUrl) {
            $result = true;
            header('Location: ' . $presignedUrl);
            exit;
        }
        
        return false;
    }

    /**
     * Build S3 key from local file path
     * Converts journal ID to journal path for consistent S3 structure
     *
     * @param string $path Local file path (relative to files_dir)
     * @return string S3 key
     */
    private function buildS3KeyFromPath($path)
    {
        // Extract journal ID from path (format: journals/{id}/...)
        if (preg_match('/^journals\/(\d+)\//', $path, $matches)) {
            $journalId = $matches[1];
            
            // Get journal path from ID
            $journalDao = DAORegistry::getDAO('JournalDAO');
            $journal = $journalDao->getById($journalId);
            
            if ($journal) {
                $journalPath = $journal->getPath();
                return preg_replace('/^journals\/\d+\//', 'journals/' . $journalPath . '/', $path);
            }
        }
        
        // Return as-is if no journal ID found
        return $path;
    }

    /**
     * Handle restoration from S3 (Download from S3)
     *
     * @param PKPRequest $request
     *
     * @return JSONMessage
     */
    private function handleRestore($request)
    {
        require_once(dirname(__FILE__) . '/S3FileManager.inc.php');
        // Use site-wide settings (contextId = 0)
        $fileManager = $this->getS3FileManager(0);

        if (!$fileManager) {
            $errorMessage = 'Restore failed: S3FileManager could not be initialized. Check settings.';
            return new JSONMessage(false, $errorMessage);
        }

        $filesDir = Config::getVar('files', 'files_dir');
        
        // Site-wide restoration for all journals
        $results = $fileManager->syncFromCloud($filesDir);

        if (!empty($results['errors'])) {
            $errorMessage = __('plugins.generic.s3Storage.restore.failed') . ': ' . implode(', ', array_slice($results['errors'], 0, 3));
            return new JSONMessage(false, $errorMessage);
        }

        $successMessage = __('plugins.generic.s3Storage.restore.completed') . " ({$results['success']} files restored to local storage)";
        return new JSONMessage(true, $successMessage);
    }
}
