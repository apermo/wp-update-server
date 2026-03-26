# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/) and this project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [3.0.0] - 2026-03-26

### Added

- Configuration system via `config.php` with `Wpup_Config` class and dot-notation access (#8)
- Zip structure validation with `Wpup_ZipValidator` for plugin/theme archive verification (#7)
- Multiple versions per package with `packages/{slug}/{version}/{slug}.zip` directory layout (#2)
- `Wpup_PackageRepository` for version-aware package discovery and resolution
- `Wpup_VersionUtils` for version comparison and stability parsing
- `?version=x.y.z` parameter for requesting specific package versions
- Pre-release channel support with `?channel=stable|rc|beta|alpha` parameter (#3)
- License key authentication with pluggable `Wpup_LicenseProvider` interface (#5)
- File-based license provider (`Wpup_FileLicenseProvider`) reading from `licenses.json`
- Composer repository endpoint via `?action=composer_packages` returning `packages.json` (#1)
- Authenticated upload API via `POST ?action=upload` with Bearer token authentication (#6)
- Reusable GitHub Actions workflow for automated plugin deployments (#4)
- `config.sample.php` documenting all available configuration options

### Changed

- **BREAKING:** Minimum PHP version raised from 5.3 to 8.0
- Replaced manual `loader.php` requires with `spl_autoload_register` for `Wpup_` classes
- Action dispatch now uses `match` expression
- Package name in `composer.json` changed from `yahnis-elsts/wp-update-server` to
  `apermo/wp-update-server`
- Download URLs now include version parameter for versioned packages
- `Wpup_Cache` interface extended with `clearBySlug()` method

## [2.0.2] - 2024-12-09

### Added

- `Wpup_Version::VERSION` constant for programmatic version access
- GitHub Actions workflow to validate version tag matches `Version.php` on release
- PHPCS configuration using WordPress-VIP-Go standard

### Changed

- Version management via GitHub Actions release trigger (changed to `published` event)
- Updated `composer.json` with `ext-json` dependency and HTTPS URLs
- Updated code sample for plugin updates in README

### Fixed

- PHP Code Sniffer issues across the codebase

## [2.0.1] - 2022-12-16

### Fixed

- PHP 8 deprecation notices in `Wpup_Headers` (added `#[ReturnTypeWillChange]` attributes)

## [2.0.0] - 2021-01-17

### Added

- IP anonymization support via `enableIpAnonymization()` method
- Named column keys in `filterLogInfo()` for easier subclass customization
- Log escaping to prevent injection into plain text log files
- Support for the `Requires PHP` plugin header field
- WordPress.com site URL detection from User-Agent header
- Subclass-overridable cache time

### Changed

- Replaced Markdown library with Parsedown to fix PHP 7 curly brace warnings
- Moved icons and banners to `package-assets/` directory
- Updated `.htaccess` for Apache 2.4 compatibility (prevent direct package downloads)

### Fixed

- Changelog parsing where lists were not terminated correctly
- Incorrect banner and icon URLs on Windows in certain configurations

## [1.3.1] - 2017-12-08

### Added

- Plugin icon support (128x128, 256x256, SVG) for WordPress plugin directory integration

## [1.3.0] - 2017-06-21

### Added

- Plugin banner support for WordPress plugin directory integration
- Basic log rotation with configurable period and backup count
- PclZip library as fallback when ZipArchive extension is not available

### Changed

- Created `Wpup_Archive` wrapper class abstracting ZipArchive and PclZip
- Sanitized `action` and `slug` request parameters against injection

### Removed

- Hard dependency on the ZipArchive PHP extension (PclZip is now the fallback)

## [1.2.0] - 2016-08-24

### Added

- `Wpup_ZipMetadataParser` class extracted from `Wpup_Package` for cleaner metadata handling
- `composer.json` and MIT license
- Cache expiration: expired cache files are now deleted automatically

### Changed

- Refactored metadata retrieval into a separate parser class
- Updated Markdown library to version 1.2.8
- Improved cache key handling with dedicated `cacheKey` method

### Fixed

- Double slashes in download URLs
- UTF-8 re-encoding issue in readme contents
- Deprecated constructor warnings on PHP 7
- Metadata caching bug causing stale data

## [1.1.0] - 2014-12-15

### Changed

- Refactored `Wpup_Request` class with HTTP header parsing support
- Optimized regex patterns in header parsing (unnecessary escapes, case flags)

## [1.0.0] - 2014-09-30

### Added

- Core update server with `get_metadata` and `download` actions
- `Wpup_UpdateServer` main class handling request dispatch and response
- `Wpup_Request` class for parsing update API requests
- `Wpup_Package` class representing plugin/theme zip archives
- `Wpup_Cache` interface and `Wpup_FileCache` implementation
- Request logging with tab-separated log files
- Automatic server URL detection
- WordPress User-Agent parsing for version and site URL extraction

[Unreleased]: https://github.com/apermo/wp-update-server/compare/v3.0.0...HEAD
[3.0.0]: https://github.com/apermo/wp-update-server/compare/v2.0.2...v3.0.0
[2.0.2]: https://github.com/apermo/wp-update-server/compare/v2.0.1...v2.0.2
[2.0.1]: https://github.com/apermo/wp-update-server/compare/v2.0...v2.0.1
[2.0.0]: https://github.com/apermo/wp-update-server/compare/v1.3.1...v2.0
[1.3.1]: https://github.com/apermo/wp-update-server/compare/v1.3...v1.3.1
[1.3.0]: https://github.com/apermo/wp-update-server/compare/v1.2...v1.3
[1.2.0]: https://github.com/apermo/wp-update-server/compare/v1.1...v1.2
[1.1.0]: https://github.com/apermo/wp-update-server/compare/v1.0...v1.1
[1.0.0]: https://github.com/apermo/wp-update-server/releases/tag/v1.0
