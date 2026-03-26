<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer\Validation;

class ZipValidator {

	/**
	 * Validate that a ZIP archive conforms to WordPress plugin/theme packaging standards.
	 */
	public static function validate(
		string $zipPath,
		?string $expectedSlug = null,
		?string $expectedVersion = null,
	): ValidationResult {
		if (!is_file($zipPath) || !is_readable($zipPath)) {
			return new ValidationResult(['File does not exist or is not readable: ' . $zipPath]);
		}

		$packageInfo = \WshWordPressPackageParser::parsePackage($zipPath);

		if ($packageInfo === false) {
			return new ValidationResult([
				'Not a valid WordPress plugin or theme archive. '
				. 'Ensure the zip contains a plugin PHP file with a "Plugin Name" header '
				. 'or a style.css with a "Theme Name" header.',
			]);
		}

		$errors = [];

		// Determine the top-level directory from the detected file path.
		$detectedFile = $packageInfo['pluginFile'] ?? $packageInfo['stylesheet'] ?? null;
		if ($detectedFile !== null) {
			$topDir = explode('/', $detectedFile)[0];
		} else {
			$errors[] = 'Could not determine top-level directory from archive contents.';
			$topDir = null;
		}

		if ($expectedSlug !== null && $topDir !== null && $topDir !== $expectedSlug) {
			$errors[] = sprintf(
				'Top-level directory "%s" does not match expected slug "%s".',
				$topDir,
				$expectedSlug,
			);
		}

		$header = $packageInfo['header'] ?? [];
		$version = $header['Version'] ?? ($header['version'] ?? null);

		if (empty($version)) {
			$errors[] = 'The ' . $packageInfo['type'] . ' header does not contain a version.';
		}

		if ($expectedVersion !== null && !empty($version) && $version !== $expectedVersion) {
			$errors[] = sprintf(
				'Version "%s" in %s header does not match expected "%s".',
				$version,
				$packageInfo['type'],
				$expectedVersion,
			);
		}

		return new ValidationResult($errors);
	}
}
