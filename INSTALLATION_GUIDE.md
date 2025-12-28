# Installation and Testing Guide for S3 Storage Plugin v1.1.0

## Quick Start

### For Fresh Installation

1. **Navigate to the OJS installation**
   ```bash
   cd /home/unix/projects/emspub/apps/ojs
   ```

2. **Verify the plugin files are in the correct location**
   ```bash
   ls -la plugins/generic/s3ojs/
   ```

3. **Install the plugin via command line (recommended)**
   ```bash
   php lib/pkp/tools/installPluginVersion.php plugins/generic/s3ojs/version.xml
   ```

4. **Enable the plugin for your journal**
   ```bash
   # Replace 'journal_path' with your actual journal path
   php tools/plugin.php enable S3StoragePlugin journal_path
   ```

5. **Or install via web interface**
   - Log in as Administrator
   - Go to: Settings > Website > Plugins > Upload A New Plugin
   - Create a ZIP file of the `s3ojs` directory
   - Upload and install

### Configuration

1. **Access Plugin Settings**
   - Navigate to: Settings > Website > Plugins
   - Find "S3 Storage Plugin" in Generic Plugins
   - Click the settings icon

2. **Configure S3 Credentials**
   - **Provider**: Choose your S3-compatible provider (AWS, Wasabi, DigitalOcean, or Custom)
   - **Bucket**: Your S3 bucket name
   - **Access Key**: Your S3 access key ID
   - **Secret Key**: Your S3 secret access key
   - **Region**: Select the appropriate region for your provider
   - **Custom Endpoint**: (Optional) For custom S3-compatible services

3. **Advanced Settings**
   - **Hybrid Mode**: Store files both locally and in cloud (for redundancy)
   - **Fallback Enabled**: Automatically use local storage if cloud fails
   - **Auto Sync**: Automatically sync files to cloud on upload
   - **Cron Enabled**: Enable scheduled maintenance tasks
   - **Cleanup Orphaned**: Automatically remove unused files

4. **Test Connection**
   - Click the "Test Connection" button in settings
   - Verify successful connection to your S3 bucket

## Troubleshooting

### Plugin Not Appearing in Admin UI

If the plugin installs but doesn't appear:

1. **Check PHP error log**
   ```bash
   tail -f /var/log/php/error.log
   # or wherever your PHP error log is located
   ```

2. **Verify namespace is correct**
   - The plugin should use namespace `APP\plugins\generic\s3ojs`
   - Check that all `.inc.php` files have the namespace declaration

3. **Clear OJS cache**
   ```bash
   cd /home/unix/projects/emspub/apps/ojs
   php tools/deleteCache.php
   ```

4. **Check plugin is in database**
   ```sql
   SELECT * FROM plugin_settings WHERE plugin_name = 's3ojs';
   SELECT * FROM versions WHERE product LIKE '%s3ojs%';
   ```

### Installation Errors

**Error: "Cannot override final method getInstallEmailTemplateDataFile()"**
- This error should NOT occur with v1.1.0
- If you see this, you may be using the wrong version
- Ensure you have the updated files from this PR

**Error: "Class not found" errors**
- Verify all `.inc.php` files have proper namespace declarations
- Check that vendor/aws is present in the plugin directory
- Ensure AWS SDK autoloader is included

### Permission Issues

If you get permission errors:

1. **Check file ownership**
   ```bash
   sudo chown -R www-data:www-data plugins/generic/s3ojs/
   ```

2. **Check file permissions**
   ```bash
   sudo chmod -R 755 plugins/generic/s3ojs/
   ```

## Testing Checklist

### Basic Functionality
- [ ] Plugin appears in admin UI
- [ ] Plugin can be enabled/disabled
- [ ] Settings page loads without errors
- [ ] Connection test succeeds
- [ ] Settings can be saved

### File Operations
- [ ] Upload a test file (e.g., article submission)
- [ ] Verify file appears in S3 bucket
- [ ] Download the file
- [ ] Verify file content is correct
- [ ] Delete the file
- [ ] Verify file is removed from S3

### Advanced Features
- [ ] Sync existing files to S3
- [ ] Test cleanup orphaned files
- [ ] Verify hybrid mode works (if enabled)
- [ ] Test fallback to local storage (if enabled)
- [ ] Verify CDN integration (if configured)

## Verifying S3 Integration

### Check Files in S3 Bucket

Using AWS CLI:
```bash
aws s3 ls s3://your-bucket-name/ --recursive
```

Using browser:
- Log into AWS Console
- Navigate to S3
- Open your bucket
- Verify files are present

### Monitor PHP Error Log

```bash
# Watch for S3-related messages
tail -f /var/log/php/error.log | grep S3Storage
```

### Test File Download

1. Upload a file in OJS
2. Note the file path in S3 logs
3. Try to download via OJS interface
4. Verify it serves from S3 (check response headers)

## Rollback Procedure

If you need to rollback to version 1.0.0:

1. **Disable the plugin**
   ```bash
   php tools/plugin.php disable S3StoragePlugin journal_path
   ```

2. **Replace files with v1.0.0**
   ```bash
   # Back up current version
   mv plugins/generic/s3ojs plugins/generic/s3ojs.v1.1.0
   
   # Restore v1.0.0 (from your backup)
   cp -r /path/to/backup/s3ojs plugins/generic/s3ojs
   ```

3. **Re-enable the plugin**
   ```bash
   php tools/plugin.php enable S3StoragePlugin journal_path
   ```

## Getting Help

If you encounter issues:

1. Check the error logs first
2. Review the CHANGELOG.md for breaking changes
3. Consult the README.md for configuration details
4. Create an issue on GitHub with:
   - OJS version
   - PHP version
   - Error messages
   - Steps to reproduce

## Next Steps After Installation

1. **Back up your database** before enabling in production
2. **Test thoroughly** in a staging environment
3. **Configure cron jobs** for automated maintenance
4. **Set up monitoring** for S3 operations
5. **Document your configuration** for your team

## Support

For issues specific to OJS 3.5 compatibility, please refer to:
- PR_SUMMARY.md - Detailed technical changes
- CHANGELOG.md - Version history and changes
- README.md - General plugin information

