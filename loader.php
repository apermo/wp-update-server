<?php
require_once __DIR__ . '/includes/Version.php';

spl_autoload_register(function (string $class): void {
	if (!str_starts_with($class, 'Wpup_')) {
		return;
	}

	$relativePath = str_replace('_', '/', substr($class, 5));
	$file = __DIR__ . '/includes/Wpup/' . $relativePath . '.php';

	if (is_file($file)) {
		require_once $file;
	}
});

if (!class_exists('WshWordPressPackageParser')) {
	require_once __DIR__ . '/includes/extension-meta/extension-meta.php';
}
