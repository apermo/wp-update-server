<?php

class Wpup_ZipValidator {

	/**
	 * Validate that a ZIP archive conforms to WordPress plugin/theme packaging standards.
	 *
	 * @param string $zipPath Path to the ZIP file.
	 * @param string|null $expectedSlug Expected top-level directory name.
	 * @param string|null $expectedVersion Expected version string from the plugin/theme header.
	 */
	public static function validate(
		string $zipPath,
		?string $expectedSlug = null,
		?string $expectedVersion = null,
	): Wpup_ValidationResult {
		$errors = [];

		if (!is_file($zipPath) || !is_readable($zipPath)) {
			return new Wpup_ValidationResult(['File does not exist or is not readable: ' . $zipPath]);
		}

		// Parse the package using the existing WordPress parser.
		$packageInfo = WshWordPressPackageParser::parsePackage($zipPath);

		if ($packageInfo === false) {
			return new Wpup_ValidationResult([
				'Not a valid WordPress plugin or theme archive. '
				. 'Ensure the zip contains a plugin PHP file with a "Plugin Name" header '
				. 'or a style.css with a "Theme Name" header.',
			]);
		}

		// Determine the top-level directory from the detected file path.
		$detectedFile = $packageInfo['pluginFile'] ?? $packageInfo['stylesheet'] ?? null;
		if ($detectedFile !== null) {
			$topDir = explode('/', $detectedFile)[0];
		} else {
			$errors[] = 'Could not determine top-level directory from archive contents.';
			$topDir = null;
		}

		// Validate slug match.
		if ($expectedSlug !== null && $topDir !== null && $topDir !== $expectedSlug) {
			$errors[] = sprintf(
				'Top-level directory "%s" does not match expected slug "%s".',
				$topDir,
				$expectedSlug,
			);
		}

		// Validate that a version header is present.
		$header = $packageInfo['header'] ?? [];
		$version = $header['Version'] ?? ($header['version'] ?? null);

		if (empty($version)) {
			$errors[] = 'The ' . $packageInfo['type'] . ' header does not contain a version.';
		}

		// Validate version consistency.
		if ($expectedVersion !== null && !empty($version) && $version !== $expectedVersion) {
			$errors[] = sprintf(
				'Version "%s" in %s header does not match expected "%s".',
				$version,
				$packageInfo['type'],
				$expectedVersion,
			);
		}

		return new Wpup_ValidationResult($errors);
	}
}
