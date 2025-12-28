# Changelog

All notable changes to the S3 Storage Plugin for OJS will be documented in this file.

## [1.1.0] - 2025-10-13

### Added
- OJS 3.5+ compatibility
- Modern namespace structure (`APP\plugins\generic\s3ojs`)
- Version history section in README

### Changed
- **BREAKING**: Migrated from legacy `import()` statements to PHP `use` statements
- Updated all class imports to use modern PKP/OJS namespaces:
  - `PKP\plugins\GenericPlugin` instead of old GenericPlugin
  - `PKP\plugins\Hook` instead of HookRegistry
  - `PKP\core\JSONMessage` instead of old JSONMessage
  - `PKP\linkAction\*` instead of old linkAction classes
  - `PKP\form\*` instead of old form classes
  - `PKP\file\FileManager` instead of old FileManager
- Updated `Application::isUnderMaintenance()` check instead of `Config::getVar('general', 'installed')`
- Updated version to 1.1.0.0
- Updated README to reflect OJS 3.5+ requirement
- Updated minimum PHP version requirement to 8.0+

### Removed
- **CRITICAL FIX**: Removed deprecated `getInstallEmailTemplateDataFile()` method that was causing fatal error
  - This method was marked as `final` in OJS 3.5 and cannot be overridden
  - Email templates now use `.po` files instead of XML data files
- Removed legacy `AppLocale::requireComponents()` call (no longer needed)
- Removed old-style `array($this, 'method')` callbacks in favor of modern array syntax `[$this, 'method']`

### Fixed
- **MAJOR FIX**: Plugin now installs without fatal error in OJS 3.5
  - Previous error: "Cannot override final method PKP\plugins\Plugin::getInstallEmailTemplateDataFile()"
- Plugin now appears in admin UI after installation
- Updated class instantiation to use fully qualified namespaces

## [1.0.0] - 2025-06-06

### Initial Release
- S3-compatible storage integration for OJS 3.4.0
- Support for AWS S3, Wasabi, DigitalOcean Spaces, and custom endpoints
- Hybrid mode and fallback mechanisms
- Media library synchronization
- Scheduled maintenance tasks
- Multi-language support (English and Vietnamese)

