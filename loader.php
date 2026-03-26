<?php
/**
 * Autoloader for WP Update Server.
 *
 * Prefers Composer autoload if available (run `composer install`).
 * Falls back to a simple PSR-4 autoloader for non-Composer deployments.
 */

if (is_file(__DIR__ . '/vendor/autoload.php')) {
	require_once __DIR__ . '/vendor/autoload.php';
	return;
}

// Fallback PSR-4 autoloader for Apermo\WpUpdateServer namespace.
spl_autoload_register(function (string $class): void {
	$prefix = 'Apermo\\WpUpdateServer\\';
	if (!str_starts_with($class, $prefix)) {
		return;
	}

	$relativePath = str_replace('\\', '/', substr($class, strlen($prefix)));
	$file = __DIR__ . '/src/' . $relativePath . '.php';

	if (is_file($file)) {
		require_once $file;
	}
});

// External dependencies that don't follow our namespace.
if (!class_exists('Parsedown')) {
	require_once __DIR__ . '/includes/Parsedown/Parsedown.php';
}
if (!class_exists('WshWordPressPackageParser')) {
	require_once __DIR__ . '/includes/extension-meta/extension-meta.php';
}
