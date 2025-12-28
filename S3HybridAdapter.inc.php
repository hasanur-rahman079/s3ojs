<?php

/**
 * @file plugins/generic/s3ojs/S3HybridAdapter.inc.php
 *
 * Copyright (c) 2023 OJS/PKP
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class S3HybridAdapter
 *
 * @ingroup plugins_generic_s3Storage
 *
 * @brief Custom Flysystem adapter that wraps S3 and Local storage for redundancy and fallback.
 */

namespace APP\plugins\generic\s3ojs;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use Exception;

class S3HybridAdapter implements FilesystemAdapter
{
    /** @var FilesystemAdapter */
    protected FilesystemAdapter $s3Adapter;

    /** @var FilesystemAdapter */
    protected FilesystemAdapter $localAdapter;

    /** @var bool */
    protected bool $hybridMode;

    /** @var bool */
    protected bool $fallbackEnabled;

    public function __construct(
        FilesystemAdapter $s3Adapter,
        FilesystemAdapter $localAdapter,
        bool $hybridMode = false,
        bool $fallbackEnabled = true
    ) {
        $this->s3Adapter = $s3Adapter;
        $this->localAdapter = $localAdapter;
        $this->hybridMode = $hybridMode;
        $this->fallbackEnabled = $fallbackEnabled;
    }

    /**
     * @inheritDoc
     */
    public function fileExists(string $path): bool
    {
        try {
            if ($this->s3Adapter->fileExists($path)) {
                return true;
            }
        } catch (Exception $e) {
            // S3 failed, if fallback enabled check local
            if (!$this->fallbackEnabled) {
                throw $e;
            }
        }

        return $this->localAdapter->fileExists($path);
    }

    /**
     * @inheritDoc
     */
    public function directoryExists(string $path): bool
    {
        try {
            if ($this->s3Adapter->directoryExists($path)) {
                return true;
            }
        } catch (Exception $e) {
            if (!$this->fallbackEnabled) {
                throw $e;
            }
        }

        return $this->localAdapter->directoryExists($path);
    }

    /**
     * @inheritDoc
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $s3Success = false;
        try {
            $this->s3Adapter->write($path, $contents, $config);
            $s3Success = true;
        } catch (Exception $e) {
            if (!$this->fallbackEnabled && !$this->hybridMode) {
                throw $e;
            }
            error_log('S3StoragePlugin: S3 Write failure, using local fallback for ' . $path . ' Error: ' . $e->getMessage());
        }

        if ($this->hybridMode || (!$s3Success && $this->fallbackEnabled)) {
            $this->localAdapter->write($path, $contents, $config);
        }
    }

    /**
     * @inheritDoc
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $s3Success = false;
        // S3 client/adapter might consume the stream, so we might need to seek or copy it if hybrid
        
        // For hybrid, we might need a copy of the stream
        $localStream = null;
        if ($this->hybridMode) {
            $tempStream = fopen('php://temp', 'r+');
            stream_copy_to_stream($contents, $tempStream);
            rewind($tempStream);
            rewind($contents);
            $localStream = $tempStream;
        }

        try {
            $this->s3Adapter->writeStream($path, $contents, $config);
            $s3Success = true;
        } catch (Exception $e) {
            if (!$this->fallbackEnabled && !$this->hybridMode) {
                throw $e;
            }
            error_log('S3StoragePlugin: S3 WriteStream failure, using local fallback for ' . $path . ' Error: ' . $e->getMessage());
            // If it failed and we didn't have a local stream yet (because hybrid was false), try to use the current stream
            // but it might be consumed. Flysystem 3 usually handles stream position or errors.
            if (!$localStream) {
                $localStream = $contents;
            }
        }

        if ($this->hybridMode || (!$s3Success && $this->fallbackEnabled)) {
            $this->localAdapter->writeStream($path, $localStream ?: $contents, $config);
        }

        if (is_resource($localStream)) {
            fclose($localStream);
        }
    }

    /**
     * @inheritDoc
     */
    public function read(string $path): string
    {
        try {
            return $this->s3Adapter->read($path);
        } catch (Exception $e) {
            if (!$this->fallbackEnabled && !$this->hybridMode) {
                throw $e;
            }
            return $this->localAdapter->read($path);
        }
    }

    /**
     * @inheritDoc
     */
    public function readStream(string $path)
    {
        try {
            return $this->s3Adapter->readStream($path);
        } catch (Exception $e) {
            if (!$this->fallbackEnabled && !$this->hybridMode) {
                throw $e;
            }
            return $this->localAdapter->readStream($path);
        }
    }

    /**
     * @inheritDoc
     */
    public function delete(string $path): void
    {
        try {
            $this->s3Adapter->delete($path);
        } catch (Exception $e) {
            // Ignore S3 errors on delete if fallback is on?
            // Usually we want to delete from both if possible.
        }
        
        try {
            $this->localAdapter->delete($path);
        } catch (Exception $e) {
            // File might not exist locally
        }
    }

    /**
     * @inheritDoc
     */
    public function deleteDirectory(string $path): void
    {
        try {
            $this->s3Adapter->deleteDirectory($path);
        } catch (Exception $e) {}

        try {
            $this->localAdapter->deleteDirectory($path);
        } catch (Exception $e) {}
    }

    /**
     * @inheritDoc
     */
    public function createDirectory(string $path, Config $config): void
    {
        try {
            $this->s3Adapter->createDirectory($path, $config);
        } catch (Exception $e) {
            if (!$this->fallbackEnabled && !$this->hybridMode) {
                throw $e;
            }
        }

        try {
            $this->localAdapter->createDirectory($path, $config);
        } catch (Exception $e) {}
    }

    /**
     * @inheritDoc
     */
    public function setVisibility(string $path, string $visibility): void
    {
        try {
            $this->s3Adapter->setVisibility($path, $visibility);
        } catch (Exception $e) {}

        try {
            $this->localAdapter->setVisibility($path, $visibility);
        } catch (Exception $e) {}
    }

    /**
     * @inheritDoc
     */
    public function visibility(string $path): FileAttributes
    {
        try {
            return $this->s3Adapter->visibility($path);
        } catch (Exception $e) {
            return $this->localAdapter->visibility($path);
        }
    }

    /**
     * @inheritDoc
     */
    public function mimeType(string $path): FileAttributes
    {
        try {
            return $this->s3Adapter->mimeType($path);
        } catch (Exception $e) {
            return $this->localAdapter->mimeType($path);
        }
    }

    /**
     * @inheritDoc
     */
    public function lastModified(string $path): FileAttributes
    {
        try {
            return $this->s3Adapter->lastModified($path);
        } catch (Exception $e) {
            return $this->localAdapter->lastModified($path);
        }
    }

    /**
     * @inheritDoc
     */
    public function fileSize(string $path): FileAttributes
    {
        try {
            return $this->s3Adapter->fileSize($path);
        } catch (Exception $e) {
            return $this->localAdapter->fileSize($path);
        }
    }

    /**
     * @inheritDoc
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $contents = [];
        
        try {
            foreach ($this->s3Adapter->listContents($path, $deep) as $item) {
                $contents[$item->path()] = $item;
            }
        } catch (Exception $e) {}

        try {
            foreach ($this->localAdapter->listContents($path, $deep) as $item) {
                if (!isset($contents[$item->path()])) {
                    $contents[$item->path()] = $item;
                }
            }
        } catch (Exception $e) {}

        return $contents;
    }

    /**
     * @inheritDoc
     */
    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->s3Adapter->move($source, $destination, $config);
        } catch (Exception $e) {
            if (!$this->fallbackEnabled && !$this->hybridMode) {
                throw $e;
            }
        }

        try {
            $this->localAdapter->move($source, $destination, $config);
        } catch (Exception $e) {}
    }

    /**
     * @inheritDoc
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $this->s3Adapter->copy($source, $destination, $config);
        } catch (Exception $e) {
            if (!$this->fallbackEnabled && !$this->hybridMode) {
                throw $e;
            }
        }

        try {
            $this->localAdapter->copy($source, $destination, $config);
        } catch (Exception $e) {}
    }
}
