<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer\Cache;

interface CacheInterface {

	/**
	 * Get cached value.
	 *
	 * @param string $key Cache key.
	 */
	public function get( string $key ): mixed;

	/**
	 * Update the cache.
	 *
	 * @param string $key Cache key.
	 * @param mixed  $value The value to store in the cache.
	 * @param int    $expiration Time until expiration, in seconds. Optional.
	 */
	public function set( string $key, mixed $value, int $expiration = 0 ): void;

	/**
	 * Clear a cache entry.
	 *
	 * @param string $key Cache key to clear.
	 */
	public function clear( string $key ): void;

	/**
	 * Clear all cache entries for a given package slug.
	 *
	 * @param string $slug Package slug.
	 */
	public function clearBySlug( string $slug ): void;
}
