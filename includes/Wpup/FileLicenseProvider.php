<?php

/**
 * File-based license provider.
 *
 * Reads license definitions from a JSON file with this structure:
 * {
 *     "license-key-abc": {
 *         "packages": ["my-plugin", "other-plugin"],
 *         "expires": "2027-01-01"
 *     },
 *     "wildcard-key": {
 *         "packages": ["*"],
 *         "expires": null
 *     }
 * }
 */
class Wpup_FileLicenseProvider implements Wpup_LicenseProvider {

	private array $licenses;

	public function __construct(string $licensesFile) {
		if (is_file($licensesFile) && is_readable($licensesFile)) {
			//phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
			$data = json_decode(file_get_contents($licensesFile), true);
			$this->licenses = is_array($data) ? $data : [];
		} else {
			$this->licenses = [];
		}
	}

	public function validate(string $key, string $slug): bool {
		if (!isset($this->licenses[$key])) {
			return false;
		}

		$license = $this->licenses[$key];

		// Check expiration.
		$expires = $license['expires'] ?? null;
		if ($expires !== null && strtotime($expires) < time()) {
			return false;
		}

		// Check slug authorization.
		$packages = $license['packages'] ?? [];
		return in_array('*', $packages, true) || in_array($slug, $packages, true);
	}
}
