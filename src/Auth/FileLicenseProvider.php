<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer\Auth;

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
class FileLicenseProvider implements LicenseProvider {

	/**
	 * License definitions keyed by license key string.
	 *
	 * @var array<string, array{packages: string[], expires: string|null}>
	 */
	private array $licenses;

	/**
	 * @param string $licensesFile Path to the JSON licenses file.
	 */
	public function __construct( string $licensesFile ) {
		if ( \is_file( $licensesFile ) && \is_readable( $licensesFile ) ) {
			$data = \json_decode( \file_get_contents( $licensesFile ), true );
			$this->licenses = \is_array( $data ) ? $data : [];
		} else {
			$this->licenses = [];
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key  License key to validate.
	 * @param string $slug Package slug the key must authorize.
	 */
	public function validate( string $key, string $slug ): bool {
		if ( ! isset( $this->licenses[ $key ] ) ) {
			return false;
		}

		$license = $this->licenses[ $key ];

		// Check expiration.
		$expires = $license['expires'] ?? null;
		if ( $expires !== null && \strtotime( $expires ) < \time() ) {
			return false;
		}

		// Check slug authorization.
		$packages = $license['packages'] ?? [];
		return \in_array( '*', $packages, true ) || \in_array( $slug, $packages, true );
	}
}
