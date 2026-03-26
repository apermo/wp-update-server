<?php

interface Wpup_LicenseProvider {

	/**
	 * Validate a license key for a given package slug.
	 *
	 * @return bool True if the key is valid and authorized for the slug.
	 */
	public function validate(string $key, string $slug): bool;
}
