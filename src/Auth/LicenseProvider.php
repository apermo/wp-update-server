<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer\Auth;

interface LicenseProvider {

	/**
	 * Validate a license key for a given package slug.
	 *
	 * @param string $key  License key.
	 * @param string $slug Package slug.
	 * @return bool True if the key is valid and authorized for the slug.
	 */
	public function validate( string $key, string $slug ): bool;
}
