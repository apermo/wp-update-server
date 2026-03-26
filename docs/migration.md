# Migrating to v3.0.0

This guide walks you through upgrading from WP Update Server v2.x to v3.0.0.

## Requirements

- **PHP 8.0 or higher** (raised from 5.3)
- **ext-zip** is now a hard requirement (previously optional with PclZip fallback)
- **ext-json** (unchanged)

Check your PHP version before upgrading:

```bash
php -v
php -m | grep -E 'zip|json'
```

## Step 1: Back Up

```bash
# Back up the entire server directory
cp -r /path/to/wp-update-server /path/to/wp-update-server.bak
```

## Step 2: Update the Code

Replace the server files with the v3.0.0 release. Preserve your `packages/`, `cache/`, `logs/`,
and any custom `index.php`:

```bash
SERVER_DIR="/path/to/wp-update-server"

# Keep your data directories and custom files
mv "$SERVER_DIR/packages" /tmp/wpup-packages-backup
mv "$SERVER_DIR/index.php" /tmp/wpup-index-backup.php

# Replace server files (via git, download, or however you deploy)
# Example with git:
cd "$SERVER_DIR"
git fetch origin
git checkout v3.0.0

# Restore your packages
mv /tmp/wpup-packages-backup "$SERVER_DIR/packages"
```

## Step 3: Migrate Packages to the Versioned Layout

v3.0.0 uses a new directory structure for packages:

| v2.x (flat) | v3.0.0 (versioned) |
|---|---|
| `packages/my-plugin.zip` | `packages/my-plugin/1.2.0/my-plugin.zip` |
| `packages/my-theme.zip` | `packages/my-theme/2.0.1/my-theme.zip` |

The version number is read from the plugin/theme header inside the zip.

### Automatic migration script

This script reads the version from each zip and moves it into the correct directory:

```bash
#!/bin/bash
set -euo pipefail

PACKAGES_DIR="/path/to/wp-update-server/packages"

cd "$PACKAGES_DIR"

for zipfile in *.zip; do
    [ -f "$zipfile" ] || continue

    slug="${zipfile%.zip}"

    # Extract the version from the plugin or theme header inside the zip.
    version=$(unzip -p "$zipfile" "$slug/*.php" 2>/dev/null \
        | grep -i "^[[:space:]]*\*[[:space:]]*Version:" \
        | head -1 \
        | sed 's/.*Version:[[:space:]]*//' \
        | tr -d '[:space:]')

    # If no plugin header found, try the theme style.css header.
    if [ -z "$version" ]; then
        version=$(unzip -p "$zipfile" "$slug/style.css" 2>/dev/null \
            | grep -i "^[[:space:]]*Version:" \
            | head -1 \
            | sed 's/.*Version:[[:space:]]*//' \
            | tr -d '[:space:]')
    fi

    if [ -z "$version" ]; then
        echo "WARNING: Could not detect version for $zipfile — skipping"
        continue
    fi

    target_dir="$slug/$version"
    mkdir -p "$target_dir"
    mv "$zipfile" "$target_dir/$zipfile"

    echo "Migrated $zipfile -> $target_dir/$zipfile (version $version)"
done

echo "Done."
```

Save this as `migrate-packages.sh`, make it executable, and run it:

```bash
chmod +x migrate-packages.sh
./migrate-packages.sh
```

### Manual migration

If you prefer to move files by hand or only have a few packages:

```bash
cd /path/to/wp-update-server/packages

# Check the version inside a zip
unzip -p my-plugin.zip my-plugin/my-plugin.php | grep "Version:"
# Output: * Version: 1.2.0

# Create the versioned directory and move the file
mkdir -p my-plugin/1.2.0
mv my-plugin.zip my-plugin/1.2.0/my-plugin.zip
```

### Keeping legacy flat layout (optional)

If you cannot migrate immediately, enable the legacy flat-file fallback in your config. This lets
the old `packages/my-plugin.zip` layout continue working alongside the new versioned layout:

```php
// config.php
return [
    'legacy_flat_packages' => true,
];
```

When a slug is requested and no versioned directory exists, the server falls back to looking for
`packages/{slug}.zip`. Once all packages are migrated, set this back to `false`.

## Step 4: Create a Configuration File

v3.0.0 introduces a configuration file. Many settings that previously required subclassing can now
be controlled via `config.php`:

```bash
cp config.sample.php config.php
```

Edit `config.php` to match your current setup. Common settings to configure:

```php
return [
    // If you had enableIpAnonymization() in a subclass:
    'logging' => [
        'anonymize_ip' => true,
    ],

    // If you had enableLogRotation() in a subclass:
    'logging' => [
        'rotation' => [
            'enabled' => true,
            'period'  => 'Y-m',
            'keep'    => 10,
        ],
    ],

    // If you want to use the Composer endpoint:
    'vendor_prefix' => 'your-vendor-name',
];
```

See `config.sample.php` for all available options and their defaults.

## Step 5: Update Custom Subclasses

All classes have been moved from the `Wpup_` prefix convention to the `Apermo\WpUpdateServer`
namespace. Classes are now in `src/` instead of `includes/Wpup/`.

### Namespace mapping

| v2.x | v3.0.0 |
|---|---|
| `Wpup_UpdateServer` | `Apermo\WpUpdateServer\UpdateServer` |
| `Wpup_Request` | `Apermo\WpUpdateServer\Request` |
| `Wpup_Package` | `Apermo\WpUpdateServer\Package` |
| `Wpup_Cache` | `Apermo\WpUpdateServer\Cache\CacheInterface` |
| `Wpup_FileCache` | `Apermo\WpUpdateServer\Cache\FileCache` |
| `Wpup_Headers` | `Apermo\WpUpdateServer\Headers` |
| `Wpup_InvalidPackageException` | `Apermo\WpUpdateServer\Exception\InvalidPackageException` |

### Updating your custom server class

```php
// v2.x
require __DIR__ . '/loader.php';
require __DIR__ . '/MyCustomServer.php';

class MyCustomServer extends Wpup_UpdateServer {
    protected function filterMetadata($meta, $request) {
        $meta = parent::filterMetadata($meta, $request);
        unset($meta['download_url']);
        return $meta;
    }
}

$server = new MyCustomServer();
$server->handleRequest();

// v3.0.0
require __DIR__ . '/loader.php';

use Apermo\WpUpdateServer\UpdateServer;
use Apermo\WpUpdateServer\Request;

class MyCustomServer extends UpdateServer {
    protected function filterMetadata(array $meta, Request $request): array {
        $meta = parent::filterMetadata($meta, $request);
        unset($meta['download_url']);
        return $meta;
    }
}

$server = new MyCustomServer();
$server->handleRequest();
```

### Constructor signature

The constructor now loads configuration automatically. If you override it, call `parent::__construct()`
first. Settings like IP anonymization and log rotation are now handled via `config.php`.

### `findPackage()` signature changed

```php
// v2.x
protected function findPackage($slug)

// v3.0.0
protected function findPackage(string $slug, ?string $version = null, string $channel = 'stable'): ?Package
```

### `CacheInterface` has a new method

If you implemented a custom cache class, update the interface name and add `clearBySlug()`:

```php
use Apermo\WpUpdateServer\Cache\CacheInterface;

class MyCache implements CacheInterface {
    // Existing methods: get(), set(), clear()

    // New in v3.0.0:
    public function clearBySlug(string $slug): void {
        // Clear all cached metadata entries for the given slug.
    }
}
```

### Autoloader

`loader.php` uses Composer autoload if `vendor/autoload.php` exists, otherwise falls back to a
PSR-4 autoloader for the `Apermo\WpUpdateServer` namespace. Run `composer install` for the best
experience, or deploy without Composer — both work.

## Step 6: Update index.php (if customized)

The default `index.php` now uses the namespaced class:

```php
// v3.0.0
require __DIR__ . '/loader.php';

$server = new Apermo\WpUpdateServer\UpdateServer();
$server->handleRequest();
```

If you have a custom server class, require it after `loader.php` (see Step 5 for the full example).

## Step 7: Clear the Cache

After migrating packages to the new directory layout, clear the metadata cache so stale entries
don't cause issues:

```bash
rm -f /path/to/wp-update-server/cache/*.txt
```

## Step 8: Verify

Test that the server responds correctly:

```bash
SERVER_URL="https://your-server.example.com/wp-update-server"

# Metadata for latest stable version
curl -s "$SERVER_URL/?action=get_metadata&slug=my-plugin" | python3 -m json.tool

# Metadata for a specific version
curl -s "$SERVER_URL/?action=get_metadata&slug=my-plugin&version=1.2.0" | python3 -m json.tool

# Composer packages.json
curl -s "$SERVER_URL/?action=composer_packages" | python3 -m json.tool

# Download a specific version
curl -sI "$SERVER_URL/?action=download&slug=my-plugin&version=1.2.0"
```

## Quick Reference: What Changed

| Feature | v2.x | v3.0.0 |
|---|---|---|
| PHP version | 5.3+ | 8.0+ |
| Package layout | `packages/{slug}.zip` | `packages/{slug}/{version}/{slug}.zip` |
| Configuration | Subclass overrides | `config.php` file |
| Multiple versions | Not supported | `?version=x.y.z` parameter |
| Pre-release channels | Not supported | `?channel=stable\|rc\|beta\|alpha` |
| Composer support | Not supported | `?action=composer_packages` |
| License auth | Manual subclass stub | Built-in `Apermo\WpUpdateServer\Auth\LicenseProvider` |
| Upload API | Not supported | `POST ?action=upload` |
| CI/CD deploy | Manual file copy | Reusable GitHub Actions workflow |
| Class namespace | `Wpup_` prefix (PSR-0) | `Apermo\WpUpdateServer\` (PSR-4) |
| Class location | `includes/Wpup/` | `src/` |
| Autoloading | Manual `require_once` | Composer PSR-4 with fallback autoloader |
