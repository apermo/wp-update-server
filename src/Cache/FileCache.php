<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer\Cache;

/**
 * A simple file-based cache.
 *
 * Data is base64 encoded to avoid unserialization issues which could be caused by
 * inconsistent line endings, unescaped quotes/slashes, or miscounted unicode characters.
 *
 * @see https://github.com/YahnisElsts/wp-update-server/pull/11
 */
class FileCache implements CacheInterface {

	/** @var string Filesystem path to the cache directory. */
	protected string $cacheDirectory;

	/**
	 * @param string $cacheDirectory Filesystem path to the cache directory.
	 */
	public function __construct( string $cacheDirectory ) {
		$this->cacheDirectory = $cacheDirectory;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $key ): mixed {
		$filename = $this->getCacheFilename( $key );
		if ( \is_file( $filename ) && \is_readable( $filename ) ) {
			$cache = \unserialize( \base64_decode( \file_get_contents( $filename ) ) );
			if ( $cache['expiration_time'] < \time() ) {
				$this->clear( $key );
				return null;
			}
			return $cache['value'];
		}
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function set( string $key, mixed $value, int $expiration = 0 ): void {
		$cache = [
			'expiration_time' => \time() + $expiration,
			'value' => $value,
		];
		\file_put_contents( $this->getCacheFilename( $key ), \base64_encode( \serialize( $cache ) ) );
	}

	/**
	 * Build the filesystem path for a given cache key.
	 *
	 * @param string $key Cache key.
	 */
	protected function getCacheFilename( string $key ): string {
		return $this->cacheDirectory . '/' . $key . '.txt';
	}

	/**
	 * {@inheritDoc}
	 */
	public function clear( string $key ): void {
		$file = $this->getCacheFilename( $key );
		if ( \is_file( $file ) && ! \unlink( $file ) ) {
			\error_log( 'FileCache: Failed to delete cache file: ' . $file );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function clearBySlug( string $slug ): void {
		$pattern = $this->cacheDirectory . '/metadata-b64-' . $slug . '-*.txt';
		foreach ( \glob( $pattern, \GLOB_NOESCAPE ) as $file ) {
			if ( ! \unlink( $file ) ) {
				\error_log( 'FileCache: Failed to delete cache file: ' . $file );
			}
		}
	}
}
