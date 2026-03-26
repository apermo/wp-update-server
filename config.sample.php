<?php
/**
 * WP Update Server configuration.
 *
 * Copy this file to config.php and adjust the values to your needs.
 * All settings are optional — missing keys use the defaults shown below.
 */
return [

	// Composer vendor prefix for the packages.json endpoint.
	// Packages are exposed as {vendor_prefix}/{slug}.
	'vendor_prefix' => 'wpup',

	// Enable legacy flat-file package lookup (packages/{slug}.zip).
	// When disabled, only the versioned layout (packages/{slug}/{version}/{slug}.zip) is used.
	'legacy_flat_packages' => false,

	// Logging configuration.
	'logging' => [
		// Anonymize IP addresses in log files.
		'anonymize_ip' => false,

		// Log rotation settings.
		'rotation' => [
			'enabled' => false,
			// 'Y-m' for monthly, 'Y-m-d' for daily.
			'period'  => 'Y-m',
			// Number of rotated log files to keep. 0 = unlimited.
			'keep'    => 10,
		],
	],

	// License authentication settings.
	'auth' => [
		// Require a valid license key for metadata and download requests.
		'require_license' => false,

		// Packages that are accessible without a license key (when require_license is true).
		'public_packages' => [],

		// Path to the JSON file containing license definitions.
		// Relative paths are resolved from the server directory.
		'licenses_file' => 'licenses.json',
	],

	// Upload API settings.
	'upload' => [
		// API keys authorized to upload packages.
		// Format: 'key-string' => ['name' => 'Human label', 'allowed_slugs' => ['slug'] or ['*']]
		'api_keys' => [],

		// Maximum upload size in bytes. Default: 50 MB.
		'max_size' => 50 * 1024 * 1024,
	],
];
