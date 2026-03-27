# WP Update Server

A self-hosted update API for WordPress plugins and themes, compatible with the
[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) library and Composer.

Originally forked from [YahnisElsts/wp-update-server](https://github.com/YahnisElsts/wp-update-server),
now independently maintained with a modernized codebase.

## Features

- **Plugin and theme updates** — works like WordPress.org from the user's perspective
- **Multiple versions per package** — versioned directory layout with `?version=` parameter
- **Pre-release channels** — distribute alpha/beta/RC builds via `?channel=` parameter
- **Composer repository** — `?action=composer_packages` endpoint for `composer require`
- **Upload API** — deploy new versions via `POST ?action=upload` with Bearer token auth
- **License key authentication** — pluggable provider with file-based default
- **Configuration file** — `config.php` for settings without subclassing
- **Extensible by design** — override `filterMetadata()`, `checkAuthorization()`, or any method

## Requirements

- PHP 8.0+
- `ext-zip`
- `ext-json`

## Quick Start

### 1. Install

```bash
git clone https://github.com/apermo/wp-update-server.git
cd wp-update-server
composer install
```

Or download the [latest release](https://github.com/apermo/wp-update-server/releases) and upload to
your server. Composer is optional — a built-in PSR-4 autoloader handles class loading without it.

### 2. Configure

```bash
cp config.sample.php config.php
```

Edit `config.php` to your needs. All settings are optional — the server works with sensible defaults.

### 3. Add packages

Create the versioned directory structure and drop your ZIP files:

```
packages/
  my-plugin/
    1.0.0/
      my-plugin.zip
    1.1.0/
      my-plugin.zip
  my-theme/
    2.0.0/
      my-theme.zip
```

The ZIP must contain a single top-level directory matching the slug, with a valid `Plugin Name:` or
`Theme Name:` header inside.

### 4. Verify

```
https://your-server.com/wp-update-server/?action=get_metadata&slug=my-plugin
```

You should see a JSON response with the plugin metadata.

## Integrating with Plugins

Use the [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) library:

```php
require 'path/to/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$updateChecker = PucFactory::buildUpdateChecker(
    'https://your-server.com/wp-update-server/?action=get_metadata&slug=my-plugin',
    __FILE__,
    'my-plugin'
);
```

Updates will appear in the WordPress Dashboard just like plugins from WordPress.org.

**Tip:** Create a `readme.txt` following the
[WordPress.org standard](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/)
to populate the "View details" modal.

## Integrating with Composer

Point Composer at your server as a repository:

```json
{
    "repositories": [
        {
            "type": "composer",
            "url": "https://your-server.com/wp-update-server"
        }
    ],
    "require": {
        "your-vendor/my-plugin": "^1.0"
    }
}
```

Composer requests `/packages.json` on the repository URL. This requires a web server rewrite rule
to route the request through `index.php` — see [Web Server Configuration](#web-server-configuration)
below.

The vendor prefix is configurable in `config.php` (default: `wpup`).

### Authenticated Composer access

For packages that require a license key, Composer authenticates via its native `auth.json`
mechanism. The server accepts Bearer tokens from the `Authorization` header, which Composer sends
automatically when configured:

```bash
composer config bearer.your-server.com your-license-key
```

This stores the token in `auth.json` (not `composer.json`, so it stays out of version control):

```json
{
    "bearer": {
        "your-server.com": "your-license-key"
    }
}
```

Enable license authentication on the server side in `config.php`:

```php
return [
    'auth' => [
        'require_license' => true,
        'public_packages' => ['free-plugin'],  // these don't need a key
        'licenses_file'   => 'licenses.json',
    ],
];
```

## API Reference

| Endpoint | Method | Description |
|---|---|---|
| `?action=get_metadata&slug=X` | GET | Package metadata (JSON) |
| `?action=get_metadata&slug=X&version=1.0.0` | GET | Metadata for a specific version |
| `?action=get_metadata&slug=X&channel=beta` | GET | Latest version for a stability channel |
| `?action=download&slug=X` | GET | Download the latest stable ZIP |
| `?action=download&slug=X&version=1.0.0` | GET | Download a specific version |
| `?action=composer_packages` | GET | Composer `packages.json` |
| `?action=upload` | POST | Upload a new package version (requires API key) |

## Configuration

Copy `config.sample.php` to `config.php`. Key options:

```php
return [
    'vendor_prefix'        => 'wpup',     // Composer vendor prefix
    'legacy_flat_packages' => false,       // Enable packages/{slug}.zip fallback
    'logging' => [
        'anonymize_ip' => false,
        'rotation'     => ['enabled' => false, 'period' => 'Y-m', 'keep' => 10],
    ],
    'auth' => [
        'require_license'  => false,
        'public_packages'  => [],
        'licenses_file'    => 'licenses.json',
    ],
    'upload' => [
        'api_keys' => [],
        'max_size' => 50 * 1024 * 1024,
    ],
];
```

See [`config.sample.php`](config.sample.php) for the full reference.

## Web Server Configuration

The Composer integration requires `/packages.json` to be routed through `index.php`. A matching
`.htaccess` is included for Apache. For other web servers, add the equivalent rewrite rule.

### Apache / LiteSpeed

The included `.htaccess` handles this automatically. Ensure `mod_rewrite` is enabled:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^packages\.json$ index.php [L,QSA]
</IfModule>
```

LiteSpeed is fully compatible with Apache `.htaccess` rewrite rules — no additional configuration
needed.

### nginx

Add a location block to your server configuration:

```nginx
server {
    # ... existing config ...

    location = /packages.json {
        rewrite ^ /index.php last;
    }

    location ~ \.php$ {
        # ... your existing PHP-FPM config ...
    }
}
```

## Extending the Server

Create a subclass and override any method:

```php
require __DIR__ . '/loader.php';

use Apermo\WpUpdateServer\UpdateServer;
use Apermo\WpUpdateServer\Request;

class MyServer extends UpdateServer {

    protected function filterMetadata( array $meta, Request $request ): array {
        $meta = parent::filterMetadata( $meta, $request );
        unset( $meta['download_url'] );
        return $meta;
    }
}

$server = new MyServer();
$server->handleRequest();
```

Common extension points:
- `filterMetadata()` — modify the JSON response
- `checkAuthorization()` — custom auth logic
- `filterLogInfo()` — customize log entries
- `dispatch()` — add custom actions

## Logging

All requests are logged to `logs/request.log`:

```
[2026-03-26 14:00:00 +0000] 192.168.1.xxx  GET  get_metadata  my-plugin  1.0.0  6.4  https://example.com  action=get_metadata&slug=my-plugin
```

Enable IP anonymization and log rotation in `config.php`.

## Development

```bash
# Install dependencies
composer install

# Run tests
composer test

# Run code style checks
composer cs

# Run static analysis
composer analyse

# Start local DDEV environment
ddev start

# Run smoke tests against DDEV
tests/smoke-test.sh
```

## Migrating from v2.x

See [`docs/migration.md`](docs/migration.md) for a step-by-step upgrade guide including a shell
script to migrate packages from the flat layout to the versioned directory structure.

## Credits

Originally created by [Yahnis Elsts](https://w-shadow.com/). Now independently maintained by
[Christoph Daum](https://christoph-daum.de/).

## License

[MIT](license.txt)
