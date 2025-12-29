# Pull Request: S3OJS Architecture Redesign & Optimization

## Overview
This PR redesigns the S3OJS plugin architecture to be significantly more lightweight, production-ready, and maintainable. The primary focus was reducing the plugin's footprint from over 64MB to just ~3.5MB while improving database management and reliability for OJS 3.5+.

## Key Improvements

### 1. **Massive Size Reduction (64MB → ~3.5MB)**
- **Replaced AWS SDK V3** (`aws/aws-sdk-php`) with **AsyncAws S3** (`async-aws/s3`).
- Replaced abandoned Flysystem adapter with modern **`league/flysystem-async-aws-s3`**.
- This change eliminates thousands of unused files from the `vendor` directory, drastically reducing the risk of PHP "too many open files" errors and speeding up plugin installation/updates.

### 2. **Modernized Architecture**
- **S3FileManager**: Refactored to use `AsyncAws` client with improved error handling and smaller memory usage.
- **S3HybridAdapter**: Updated for Flysystem V3 compatibility, ensuring seamless fallback between S3 and Local storage.
- **Namespacing**: Full namespace implementation (`APP\plugins\generic\s3ojs`) compliant with OJS 3.5 standards.

### 3. **Robust Database Lifecycle Management**
- **Automatic Cleanup**: Implemented logic to purge plugin settings from the database when the plugin is **disabled**, **uninstalled**, or **deleted**.
- **Fresh Configuration**: Ensuring that disabling/re-enabling the plugin allows for a clean configuration state, preventing legacy setting conflicts.
- **Direct SQL Cleanup**: Used Laravel DB facade for direct and reliable setting removal, bypassing potential DAO limitations.

### 4. **Enhanced Configuration Management**
- **S3Provider Support**: Maintained and improved support for AWS, Wasabi, DigitalOcean, and Custom S3-compatible providers.
- **Path-Style Endpoints**: Improved support for custom providers (like MinIO) via `pathStyleEndpoint` configuration.

## Files Modified

### Core Plugin Files
1. **S3StoragePlugin.inc.php**
   - Implemented `setEnabled` and `deinstall` hooks for database cleanup.
   - Refactored `configureS3Adapter` to use `AsyncAwsS3Adapter`.
   - Updated client initialization for better security and flexibility.

2. **S3FileManager.inc.php**
   - Full refactor to `AsyncAws\S3\S3Client`.
   - Replaced heavy SDK paginators with efficient iterators.
   - Optimized file transfer using stream copy instead of loading full files into memory.

3. **S3StorageSettingsForm.inc.php**
   - Refactored connection test to use the new lightweight client.
   - Improved validation logic for regions and custom endpoints.

4. **S3StorageCronHandler.inc.php**
   - Fixed namespacing and file inclusion for compatibility with the new architecture.

## Testing Recommendations

### Size Verification
1. Compare `vendor` directory size before and after this PR (should be ~3.5MB).

### Functional Tests
1. **Connection Test**: Verify "Test Connection" works for AWS and custom providers.
2. **File Lifecycle**: Test uploading, downloading (presigned URLs), and syncing files.
3. **Redundancy**: Verify "Hybrid Mode" correctly falls back to local storage if S3 is unreachable.
4. **Cleanup**: Disable the plugin and verify `plugin_settings` are removed from the database for the current context.

## Checklist
- [x] Reduced plugin size by ~95%
- [x] Switched to `AsyncAws` for S3 operations
- [x] Implemented robust database cleanup on disable/uninstall
- [x] Verified namespacing and OJS 3.5 compatibility
- [x] Updated all core logic for modern Flysystem (V3)
- [x] Updated documentation and PR summary
