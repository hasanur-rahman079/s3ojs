<?php

/**
 * @file plugins/generic/s3Storage/S3FileManager.inc.php
 *
 * Copyright (c) 2023 OJS/PKP
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class S3FileManager
 *
 * @ingroup plugins_generic_s3Storage
 *
 * @brief S3-compatible file management operations with hybrid mode and fallback support
 */

namespace APP\plugins\generic\s3ojs;

use AsyncAws\S3\S3Client;
use AsyncAws\S3\Exception\S3Exception;
use Exception;
use DateTimeImmutable;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use PKP\file\FileManager;
use PKP\core\PKPApplication;

class S3FileManager extends FileManager
{
    /** @var S3Client */
    private $s3Client;

    /** @var string */
    private $bucket;

    /** @var string */
    private $region;

    /** @var string */
    private $provider;

    /** @var string */
    private $customEndpoint;

    /** @var bool */
    private $hybridMode;

    /** @var bool */
    private $fallbackEnabled;

    /** @var FileManager */
    private $localFileManager;

    /**
     * Constructor
     *
     * @param string $bucket S3-compatible bucket name
     * @param string $key Access Key ID
     * @param string $secret Secret Access Key
     * @param string $region Region
     * @param string $provider Storage provider (aws, wasabi, digitalocean, custom)
     * @param string $customEndpoint Custom endpoint URL for non-AWS providers
     * @param bool $hybridMode Enable hybrid storage mode
     * @param bool $fallbackEnabled Enable fallback to local storage
     */
    public function __construct($bucket, $key, $secret, $region = 'us-east-1', $provider = 'aws', $customEndpoint = '', $hybridMode = false, $fallbackEnabled = true)
    {
        parent::__construct();

        $this->bucket = $bucket;
        $this->region = $region;
        $this->provider = $provider;
        $this->customEndpoint = $customEndpoint;
        $this->hybridMode = $hybridMode;
        $this->fallbackEnabled = $fallbackEnabled;
        $this->localFileManager = new FileManager();

        $this->initializeS3Client($key, $secret);
    }

    /**
     * Initialize S3 client based on provider
     *
     * @param string $key Access Key ID
     * @param string $secret Secret Access Key
     */
    private function initializeS3Client($key, $secret)
    {
        try {
            $config = [
                'region' => $this->region,
                'accessKeyId' => $key,
                'accessKeySecret' => $secret,
            ];

            // Set endpoint and other provider-specific settings
            switch ($this->provider) {
                case 'wasabi':
                    $config['endpoint'] = $this->customEndpoint ?: "https://s3.{$this->region}.wasabisys.com";
                    $config['pathStyleEndpoint'] = true;
                    break;
                case 'digitalocean':
                    $config['endpoint'] = $this->customEndpoint ?: "https://{$this->region}.digitaloceanspaces.com";
                    $config['pathStyleEndpoint'] = true;
                    break;
                case 'custom':
                    if ($this->customEndpoint) {
                        $config['endpoint'] = $this->customEndpoint;
                        $config['pathStyleEndpoint'] = true;
                    }
                    break;
                case 'aws':
                default:
                    if ($this->customEndpoint) {
                        $config['endpoint'] = $this->customEndpoint;
                    }
                    break;
            }

            error_log('S3StoragePlugin: Initializing AsyncAws S3 client');

            $this->s3Client = new S3Client($config);
        } catch (Exception $e) {
            error_log('S3StoragePlugin: Failed to initialize S3 client: ' . $e->getMessage());
            if (!$this->fallbackEnabled) {
                throw $e;
            }
        }
    }

    /**
     * Upload a file with hybrid mode and fallback support
     *
     * @param string $sourceFile Path to the source file
     * @param string $destFile Destination path
     *
     * @return boolean Success/failure
     */
    public function uploadFile($sourceFile, $destFile)
    {
        $cloudSuccess = false;
        $localSuccess = false;

        // Try cloud upload
        if ($this->s3Client) {
            $cloudSuccess = $this->uploadToCloud($sourceFile, $destFile);
        }

        // Handle hybrid mode or fallback
        if ($this->hybridMode) {
            $localSuccess = $this->localFileManager->copyFile($sourceFile, $destFile);
            return $cloudSuccess || $localSuccess;
        } elseif ($this->fallbackEnabled && !$cloudSuccess) {
            error_log('S3StoragePlugin: ' . __('plugins.generic.s3Storage.error.fallbackActivated'));
            return $this->localFileManager->copyFile($sourceFile, $destFile);
        }

        return $cloudSuccess;
    }

    /**
     * Upload file to cloud storage
     *
     * @param string $sourceFile Source file path
     * @param string $destFile Destination file path
     *
     * @return boolean Success/failure
     */
    public function uploadToCloud($sourceFile, $destFile)
    {
        if (!$this->s3Client || !file_exists($sourceFile)) {
            return false;
        }

        try {
            $this->s3Client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $destFile,
                'Body' => fopen($sourceFile, 'rb'),
                'ContentType' => $this->getMimeType($sourceFile),
            ]);

            return true;
        } catch (S3Exception $e) {
            error_log('S3StoragePlugin: Failed to upload file to cloud: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Download a file with fallback support
     *
     * @param string $sourceFile Source path
     * @param string $destFile Local destination path
     *
     * @return boolean Success/failure
     */
    public function downloadFile($sourceFile, $destFile)
    {
        // Attempt to redirect. If it fails, we MUST know why for debugging.
        if ($this->s3Client) {
            $tempUrl = $this->getTemporaryUrl($sourceFile);

            if ($tempUrl) {
                // Success! Redirect to S3 and terminate.
                PKPApplication::get()->getRequest()->redirect($tempUrl);
                exit;
            } else {
                // If we cannot get a temp URL, we should not silently fall back.
                // Forcing an error message is better for debugging.
                // We will try to re-run the command inside a try/catch to get the specific exception message.
                $errorMessage = 'Unable to get specific error message from AsyncAws.';
                try {
                    $this->s3Client->getObject([
                        'Bucket' => $this->bucket,
                        'Key' => $sourceFile,
                    ])->resolve();
                } catch (S3Exception $e) {
                    $errorMessage = 'Reason (S3Exception): ' . htmlspecialchars($e->getMessage());
                } catch (Exception $e) {
                    $errorMessage = 'Reason (Exception): ' . htmlspecialchars($e->getMessage());
                }

                header('Content-Type: text/plain; charset=utf-8');
                http_response_code(500);
                die(
                    "S3StoragePlugin - Lỗi Debug Tải Tệp:\n\n" .
                    "Không thể tạo URL tạm thời để chuyển hướng đến S3.\n" .
                    'Tệp: ' . htmlspecialchars($sourceFile) . "\n\n" .
                    $errorMessage . "\n\n" .
                    'Vui lòng sao chép và gửi toàn bộ thông báo này để gỡ lỗi.'
                );
            }
        }

        // This fallback should only execute if the S3 client was not configured at all.
        error_log('S3StoragePlugin: downloadFile fallback - S3 client not available.');
        if (($this->hybridMode || $this->fallbackEnabled) && $this->localFileManager->fileExists($sourceFile)) {
            return $this->localFileManager->copyFile($sourceFile, $destFile);
        }

        return false;
    }

    /**
     * Download file from cloud storage
     *
     * @param string $sourceFile Source file path on cloud
     * @param string $destFile Local destination path
     *
     * @return boolean Success/failure
     */
    private function downloadFromCloud($sourceFile, $destFile)
    {
        if (!$this->s3Client) {
            return false;
        }

        try {
            $result = $this->s3Client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $sourceFile,
            ]);

            $stream = $result->getBody()->getContentAsResource();
            $destStream = fopen($destFile, 'wb');
            stream_copy_to_stream($stream, $destStream);
            fclose($destStream);

            return file_exists($destFile);
        } catch (S3Exception $e) {
            error_log('S3StoragePlugin: Failed to download file from cloud: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Copy a file with hybrid support
     *
     * @param string $sourceFile Source path
     * @param string $destFile Destination path
     *
     * @return boolean Success/failure
     */
    public function copyFile($sourceFile, $destFile)
    {
        $cloudSuccess = false;
        $localSuccess = false;

        // Try cloud copy
        if ($this->s3Client) {
            $cloudSuccess = $this->copyInCloud($sourceFile, $destFile);
        }

        // Handle hybrid mode or fallback
        if ($this->hybridMode) {
            $localSuccess = $this->localFileManager->copyFile($sourceFile, $destFile);
            return $cloudSuccess || $localSuccess;
        } elseif ($this->fallbackEnabled && !$cloudSuccess) {
            return $this->localFileManager->copyFile($sourceFile, $destFile);
        }

        return $cloudSuccess;
    }

    /**
     * Copy file within cloud storage
     *
     * @param string $sourceFile Source path
     * @param string $destFile Destination path
     *
     * @return boolean Success/failure
     */
    private function copyInCloud($sourceFile, $destFile)
    {
        if (!$this->s3Client) {
            return false;
        }

        try {
            $this->s3Client->copyObject([
                'Bucket' => $this->bucket,
                'Key' => $destFile,
                'CopySource' => $this->bucket . '/' . $sourceFile,
            ]);

            return true;
        } catch (S3Exception $e) {
            error_log('S3StoragePlugin: Failed to copy file in cloud: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a file with hybrid support
     *
     * @param string $filePath File path
     *
     * @return boolean Success/failure
     */
    public function deleteFile($filePath)
    {
        $cloudSuccess = false;
        $localSuccess = false;

        // Try cloud delete
        if ($this->s3Client) {
            $cloudSuccess = $this->deleteFromCloud($filePath);
        }

        // Handle hybrid mode
        if ($this->hybridMode) {
            $localSuccess = $this->localFileManager->deleteFile($filePath);
            return $cloudSuccess || $localSuccess;
        } elseif ($this->fallbackEnabled && !$cloudSuccess) {
            return $this->localFileManager->deleteFile($filePath);
        }

        return $cloudSuccess;
    }

    /**
     * Delete file from cloud storage
     *
     * @param string $filePath File path on cloud
     *
     * @return boolean Success/failure
     */
    private function deleteFromCloud($filePath)
    {
        if (!$this->s3Client) {
            return false;
        }

        try {
            $this->s3Client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $filePath,
            ]);

            return true;
        } catch (S3Exception $e) {
            error_log('S3StoragePlugin: Failed to delete file from cloud: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a file exists (cloud or local based on mode)
     *
     * @param string $filePath
     * @param string $type Optional type check ('file' or 'dir')
     *
     * @return boolean
     */
    public function fileExists($filePath, $type = 'file')
    {
        // In hybrid mode, we check both locations
        if ($this->hybridMode) {
            return $this->fileExistsInCloud($filePath) || $this->localFileManager->fileExists($filePath, $type);
        }

        // Cloud-only mode
        if ($this->s3Client) {
            $cloudExists = $this->fileExistsInCloud($filePath);
            if ($cloudExists) {
                return true;
            }
        }

        // Fallback to local if enabled
        if ($this->fallbackEnabled) {
            return $this->localFileManager->fileExists($filePath, $type);
        }

        return false;
    }

    /**
     * Check if file exists in cloud storage
     *
     * @param string $filePath File path on cloud
     *
     * @return boolean File exists
     */
    public function fileExistsInCloud($filePath)
    {
        if (!$this->s3Client) {
            return false;
        }

        try {
            $this->s3Client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $filePath,
            ])->resolve();

            return true;
        } catch (S3Exception $e) {
            return false;
        }
    }

    /**
     * Sync files from local to cloud storage
     *
     * @param string $localPath Local directory path
     * @param string $cloudPath Cloud directory path
     * @param bool $deleteLocal Delete local files after sync
     *
     * @return array Sync results
     */
    public function syncToCloud($localPath, $cloudPath = '', $deleteLocal = false)
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        if (!$this->s3Client) {
            $results['errors'][] = 'Cloud storage not available';
            return $results;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($localPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $relativePath = str_replace($localPath . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $cloudFilePath = $cloudPath ? rtrim($cloudPath, '/') . '/' . $relativePath : $relativePath;
            $cloudFilePath = str_replace(DIRECTORY_SEPARATOR, '/', $cloudFilePath);

            if ($file->isDir()) {
                // S3 does not have real directories, but some tools create
                // zero-byte objects with a trailing slash to simulate them.
                // We can choose to create them or not. Here we skip them.
                continue;
            }

            if ($file->isFile()) {
                if ($this->uploadToCloud($file->getPathname(), $cloudFilePath)) {
                    $results['success']++;
                    if ($deleteLocal) {
                        unlink($file->getPathname());
                    }
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Failed to sync: {$relativePath}";
                }
            }
        }

        return $results;
    }

    /**
     * Sync files from cloud to local storage (Restore)
     *
     * @param string $localPath Local directory path (destination)
     * @param string $cloudPath Cloud directory prefix (optional)
     *
     * @return array Sync results
     */
    public function syncFromCloud($localPath, $cloudPath = '')
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        if (!$this->s3Client) {
            $results['errors'][] = 'Cloud storage not available';
            return $results;
        }

        try {
            $objects = $this->s3Client->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => $cloudPath ? rtrim($cloudPath, '/') . '/' : '',
            ]);

            foreach ($objects as $object) {
                $key = $object->getKey();
                
                // Skip directory markers (keys ending with /)
                if (str_ends_with($key, '/')) {
                    continue;
                }

                // Determine relative path for local storage
                if ($cloudPath) {
                    $relativePath = substr($key, strlen(rtrim($cloudPath, '/') . '/'));
                } else {
                    $relativePath = $key;
                }

                $destPath = $localPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
                
                // Create local directory if it doesn't exist
                $destDir = dirname($destPath);
                if (!is_dir($destDir)) {
                    if (!mkdir($destDir, 0777, true) && !is_dir($destDir)) {
                        $results['failed']++;
                        $results['errors'][] = "Failed to create directory: {$destDir}";
                        continue;
                    }
                }

                // Download the file
                if ($this->downloadFromCloud($key, $destPath)) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Failed to download: {$key}";
                }
            }

            return $results;
        } catch (S3Exception $e) {
            $results['errors'][] = 'S3 Error: ' . $e->getMessage();
            return $results;
        } catch (Exception $e) {
            $results['errors'][] = 'Error: ' . $e->getMessage();
            return $results;
        }
    }

    /**
     * Clean up orphaned files
     *
     * @param array $validFiles List of valid file paths
     * @param string $prefix Cloud directory prefix to scope cleanup (optional)
     *
     * @return array Cleanup results
     */
    public function cleanupOrphanedFiles($validFiles = [], $prefix = '')
    {
        $results = [
            'deleted' => 0,
            'errors' => []
        ];

        if (!$this->s3Client) {
            $results['errors'][] = 'Cloud storage not available';
            return $results;
        }

        $validFilesSet = array_flip($validFiles);

        try {
            $objects = $this->s3Client->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => $prefix ? rtrim($prefix, '/') . '/' : '',
            ]);

            foreach ($objects as $object) {
                $key = $object->getKey();

                if (!isset($validFilesSet[$key])) {
                    if ($this->deleteFromCloud($key)) {
                        $results['deleted']++;
                    } else {
                        $results['errors'][] = "Failed to delete: {$key}";
                    }
                }
            }
        } catch (S3Exception $e) {
            $results['errors'][] = 'Failed to list objects: ' . $e->getMessage();
        }

        return $results;
    }

    /**
     * Get storage statistics
     *
     * @return array Storage statistics
     */
    public function getStorageStats()
    {
        $stats = [
            'cloud' => ['count' => 0, 'size' => 0],
            'local' => ['count' => 0, 'size' => 0],
            'provider' => $this->provider,
            'hybrid_mode' => $this->hybridMode,
            'fallback_enabled' => $this->fallbackEnabled
        ];

        // Get cloud stats
        if ($this->s3Client) {
            try {
                $count = 0;
                $size = 0;
                $objects = $this->s3Client->listObjectsV2(['Bucket' => $this->bucket]);
                foreach ($objects as $object) {
                    $count++;
                    $size += $object->getSize();
                }
                $stats['cloud']['count'] = $count;
                $stats['cloud']['size'] = $size;

            } catch (S3Exception $e) {
                error_log('S3StoragePlugin: Failed to get cloud storage stats: ' . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * Test connection to storage service
     *
     * @return boolean|string True on success, error message string on failure
     */
    public function testConnection()
    {
        if (!$this->s3Client) {
            return 'S3 client not initialized. Check credentials or endpoint.';
        }

        try {
            $this->s3Client->listObjectsV2([
                'Bucket' => $this->bucket,
                'MaxKeys' => 1,
            ])->resolve();

            return true;
        } catch (S3Exception $e) {
            $errorMessage = 'S3 Error: ' . $e->getMessage();
            error_log('S3StoragePlugin: Connection test failed: ' . $errorMessage);
            return $errorMessage;
        } catch (Exception $e) {
            $errorMessage = 'Generic Error: ' . $e->getMessage();
            error_log('S3StoragePlugin: Connection test failed with generic exception: ' . $errorMessage);
            return $errorMessage;
        }
    }

    /**
     * Get a temporary URL for file access
     *
     * @param string $filePath File path
     * @param int $expires Expiration time in seconds
     *
     * @return string|false Temporary URL or false on failure
     */
    public function getTemporaryUrl($filePath, $expires = 3600)
    {
        if (!$this->s3Client) {
            return false;
        }

        try {
            return $this->s3Client->presign(
                $this->s3Client->getObject([
                    'Bucket' => $this->bucket,
                    'Key' => $filePath,
                ]),
                new \DateTimeImmutable("+{$expires} seconds")
            );
        } catch (Exception $e) {
            error_log('S3StoragePlugin: Failed to generate temporary URL: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the public URL for a file
     *
     * @param string $filePath File path
     *
     * @return string Public URL
     */
    public function getPublicUrl($filePath)
    {
        switch ($this->provider) {
            case 'wasabi':
                return "https://s3.{$this->region}.wasabisys.com/{$this->bucket}/{$filePath}";
            case 'digitalocean':
                return "https://{$this->bucket}.{$this->region}.digitaloceanspaces.com/{$filePath}";
            case 'custom':
                if ($this->customEndpoint) {
                    $endpoint = rtrim($this->customEndpoint, '/');
                    return "{$endpoint}/{$this->bucket}/{$filePath}";
                }
                break;
            case 'aws':
            default:
                return "https://{$this->bucket}.s3.{$this->region}.amazonaws.com/{$filePath}";
        }

        return "https://{$this->bucket}.s3.{$this->region}.amazonaws.com/{$filePath}";
    }

    /**
     * Get file size with fallback support
     *
     * @param string $filePath File path
     *
     * @return int|false File size or false on failure
     */
    public function getFileSize($filePath)
    {
        // Try cloud first
        if ($this->s3Client) {
            $size = $this->getCloudFileSize($filePath);
            if ($size !== false) {
                return $size;
            }
        }

        // Fallback to local if hybrid mode or fallback enabled
        if (($this->hybridMode || $this->fallbackEnabled) && file_exists($filePath)) {
            return filesize($filePath);
        }

        return false;
    }

    /**
     * Get file size from cloud storage
     *
     * @param string $filePath File path on cloud
     *
     * @return int|false File size or false on failure
     */
    private function getCloudFileSize($filePath)
    {
        if (!$this->s3Client) {
            return false;
        }

        try {
            $result = $this->s3Client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $filePath,
            ]);

            return $result->getContentLength();
        } catch (S3Exception $e) {
            return false;
        }
    }

    /**
     * List files in a directory
     *
     * @param string $directory Directory path
     * @param string $filter File filter (optional)
     *
     * @return array List of files
     */
    public function getDirectoryContents($directory, $filter = null)
    {
        $files = [];

        // Get cloud files
        if ($this->s3Client) {
            $files = array_merge($files, $this->getCloudDirectoryContents($directory, $filter));
        }

        // Get local files if hybrid mode
        if ($this->hybridMode && is_dir($directory)) {
            $localFiles = $this->localFileManager->getDirectoryContents($directory, $filter);
            $files = array_unique(array_merge($files, $localFiles));
        }

        return $files;
    }

    /**
     * Get directory contents from cloud storage
     *
     * @param string $directory Directory path on cloud
     * @param string $filter File filter (optional)
     *
     * @return array List of files
     */
    private function getCloudDirectoryContents($directory, $filter = null)
    {
        if (!$this->s3Client) {
            return [];
        }

        try {
            $files = [];
            $objects = $this->s3Client->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => $directory,
            ]);

            foreach ($objects as $object) {
                $filename = basename($object->getKey());
                if (!$filter || preg_match($filter, $filename)) {
                    $files[] = $filename;
                }
            }

            return $files;
        } catch (S3Exception $e) {
            error_log('S3StoragePlugin: Failed to list directory contents: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Create a directory (no-op for cloud, real for local in hybrid mode)
     *
     * @param string $dirPath Directory path
     * @param int $perms Permissions (ignored for cloud)
     *
     * @return boolean Always returns true for cloud
     */
    public function mkdir($dirPath, $perms = null)
    {
        if ($this->hybridMode) {
            return $this->localFileManager->mkdir($dirPath, $perms);
        }

        // Cloud storage doesn't have real directories
        return true;
    }

    /**
     * Remove a directory
     *
     * @param string $dirPath Directory path
     *
     * @return boolean Success/failure
     */
    public function rmdir($dirPath)
    {
        $cloudSuccess = false;
        $localSuccess = false;

        // Remove from cloud
        if ($this->s3Client) {
            $cloudSuccess = $this->removeCloudDirectory($dirPath);
        }

        // Remove from local if hybrid mode
        if ($this->hybridMode) {
            $localSuccess = $this->localFileManager->rmdir($dirPath);
            return $cloudSuccess || $localSuccess;
        }

        return $cloudSuccess;
    }

    /**
     * Remove directory from cloud storage
     *
     * @param string $dirPath Directory path on cloud
     *
     * @return boolean Success/failure
     */
    private function removeCloudDirectory($dirPath)
    {
        if (!$this->s3Client) {
            return false;
        }

        try {
            $objects = $this->s3Client->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => rtrim($dirPath, '/') . '/',
            ]);

            foreach ($objects as $object) {
                $this->s3Client->deleteObject([
                    'Bucket' => $this->bucket,
                    'Key' => $object->getKey(),
                ]);
            }

            return true;
        } catch (S3Exception $e) {
            error_log('S3StoragePlugin: Failed to remove directory: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get MIME type of a file
     *
     * @param string $filePath File path
     *
     * @return string MIME type
     */
    private function getMimeType($filePath)
    {
        if (!function_exists('finfo_open')) {
            return 'application/octet-stream';
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        return $mimeType ?: 'application/octet-stream';
    }
}
