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

If you have a custom server class that extends `Wpup_UpdateServer`, review these breaking changes:

### Constructor signature

The constructor now loads configuration automatically. If you override it, call `parent::__construct()`
first:

```php
// v2.x
class MyServer extends Wpup_UpdateServer {
    public function __construct($serverUrl = null, $serverDirectory = null) {
        parent::__construct($serverUrl, $serverDirectory);
        $this->enableIpAnonymization();
        $this->enableLogRotation();
    }
}

// v3.0.0 — move settings to config.php instead
class MyServer extends Wpup_UpdateServer {
    // No constructor override needed if your only customizations were
    // enableIpAnonymization() and enableLogRotation().
    // These are now handled by config.php.
}
```

### `findPackage()` signature changed

```php
// v2.x
protected function findPackage($slug)

// v3.0.0
protected function findPackage(string $slug, ?string $version = null, string $channel = 'stable'): ?Wpup_Package
```

If you override `findPackage()`, update your signature to match.

### `checkAuthorization()` is no longer a stub

If you implemented custom authorization logic by overriding `checkAuthorization()`, it will still
work. However, you may want to consider using the built-in license provider system instead
(see `config.sample.php` under `auth`).

### `dispatch()` uses `match` expression

If you override `dispatch()` to add custom actions, update your code:

```php
// v2.x
protected function dispatch($request) {
    if ($request->action === 'my_action') {
        $this->actionMyAction($request);
    } else {
        parent::dispatch($request);
    }
}

// v3.0.0 — same pattern still works
protected function dispatch($request) {
    if ($request->action === 'my_action') {
        $this->actionMyAction($request);
    } else {
        parent::dispatch($request);
    }
}
```

### `Wpup_Cache` interface has a new method

If you implemented a custom cache class, add the `clearBySlug()` method:

```php
class MyCache implements Wpup_Cache {
    // Existing methods: get(), set(), clear()

    // New in v3.0.0:
    public function clearBySlug(string $slug): void {
        // Clear all cached metadata entries for the given slug.
    }
}
```

### Autoloader replaces manual requires

`loader.php` now uses `spl_autoload_register` instead of manual `require_once` calls. If your
custom class follows the `Wpup_` naming convention (e.g., `Wpup_MyCustomClass` in
`includes/Wpup/MyCustomClass.php`), it will be loaded automatically. Otherwise, require it
explicitly in your `index.php`.

## Step 6: Update index.php (if customized)

If your `index.php` required custom files manually, you can simplify it since the autoloader
handles `Wpup_` classes:

```php
// v2.x
require __DIR__ . '/loader.php';
require __DIR__ . '/MyCustomServer.php';
$server = new MyCustomServer();
$server->handleRequest();

// v3.0.0 — same pattern, but loader.php now autoloads Wpup_ classes
require __DIR__ . '/loader.php';
require __DIR__ . '/MyCustomServer.php'; // still needed if not under Wpup_ prefix
$server = new MyCustomServer();
$server->handleRequest();
```

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
| License auth | Manual subclass stub | Built-in with `Wpup_LicenseProvider` |
| Upload API | Not supported | `POST ?action=upload` |
| CI/CD deploy | Manual file copy | Reusable GitHub Actions workflow |
| Class loading | Manual `require_once` | `spl_autoload_register` |
