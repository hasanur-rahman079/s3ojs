# Pull Request: OJS 3.5 Compatibility Update

## Overview
This PR updates the S3 Storage Plugin to be fully compatible with OJS 3.5+, fixing critical installation errors and modernizing the codebase to follow current OJS/PKP development standards.

## Problem Statement
The plugin was originally developed for OJS 3.4.0 and encountered a fatal error when installing on OJS 3.5:

```
PHP Fatal error: Cannot override final method PKP\plugins\Plugin::getInstallEmailTemplateDataFile()
in .../S3StoragePlugin.inc.php on line 428
```

After commenting out the method, the plugin would install but would not appear in the admin UI.

## Solution
Complete refactoring of the plugin to use OJS 3.5's modern architecture:

### 1. **Critical Fix: Removed Deprecated Method**
- Removed the `getInstallEmailTemplateDataFile()` method which is now `final` in OJS 3.5
- This method was deprecated since OJS 3.2 in favor of `.po` locale files

### 2. **Namespace Migration**
All files now use proper PHP namespaces:
```php
namespace APP\plugins\generic\s3ojs;
```

### 3. **Modern Import Statements**
Replaced legacy `import()` calls with PHP `use` statements:

**Before:**
```php
import('lib.pkp.classes.plugins.GenericPlugin');
import('lib.pkp.classes.core.JSONMessage');
```

**After:**
```php
use PKP\plugins\GenericPlugin;
use PKP\core\JSONMessage;
```

### 4. **Updated Hook Registration**
**Before:**
```php
HookRegistry::register('FileManager::getFileManager', array($this, 'getFileManager'));
```

**After:**
```php
Hook::add('FileManager::getFileManager', [$this, 'getFileManager']);
```

### 5. **Modernized Application Checks**
**Before:**
```php
if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE'))
```

**After:**
```php
if (Application::isUnderMaintenance())
```

## Files Modified

### Core Plugin Files
1. **S3StoragePlugin.inc.php**
   - Added namespace declaration
   - Updated all imports to use modern classes
   - Removed deprecated `getInstallEmailTemplateDataFile()` method
   - Modernized hook registration
   - Updated file includes to use `require_once` with fully qualified class names

2. **S3FileManager.inc.php**
   - Added namespace declaration
   - Updated to extend `PKP\file\FileManager`
   - Added necessary use statements

3. **S3StorageSettingsForm.inc.php**
   - Added namespace declaration
   - Updated to extend `PKP\form\Form`
   - Updated form validation classes

### Configuration Files
4. **version.xml**
   - Updated version to 1.1.0.0
   - Updated release date
   - Added `<sitewide>0</sitewide>` tag

5. **README.md**
   - Updated compatibility note to OJS 3.5+
   - Updated PHP requirement to 8.0+
   - Added version history section

6. **CHANGELOG.md** (New)
   - Added comprehensive changelog following Keep a Changelog format

## Testing Recommendations

### Installation Test
1. Upload the plugin to OJS 3.5
2. Navigate to Settings > Website > Plugins
3. Verify the plugin appears in the Generic Plugins list
4. Enable the plugin
5. Verify no errors in PHP error log

### Configuration Test
1. Click on plugin settings
2. Configure S3 credentials
3. Test connection to S3
4. Save settings
5. Verify settings are saved correctly

### Functionality Test
1. Upload a file to OJS
2. Verify file is uploaded to S3 bucket
3. Download the file
4. Verify file downloads correctly
5. Test sync and cleanup functions

## Compatibility

### Supported Versions
- **OJS**: 3.5.0 and newer
- **PHP**: 8.0 and newer

### Breaking Changes
- This version is **NOT** compatible with OJS 3.4.x or earlier
- If you need OJS 3.4.x support, use version 1.0.0

## Migration Notes

For users upgrading from version 1.0.0:
1. Back up your existing configuration
2. Install version 1.1.0
3. Your settings should be preserved
4. Test all functionality before deploying to production

## References
- [OJS 3.2 Release Notebook - Email Template Changes](https://docs.pkp.sfu.ca/dev/release-notebooks/en/3.2-release-notebook#email-templates-now-use-po-files)
- [PKP Plugin Guide](https://docs.pkp.sfu.ca/dev/plugin-guide/en/)

## Checklist
- [x] All files updated with namespaces
- [x] Deprecated methods removed
- [x] Modern imports implemented
- [x] Version number updated
- [x] README updated
- [x] CHANGELOG created
- [x] No linter errors
- [ ] Tested on OJS 3.5
- [ ] All functionality verified

