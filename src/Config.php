<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer;

use RuntimeException;

/**
 * Reads and provides access to configuration values using dot notation.
 */
class Config {

	/**
	 * Configuration data.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * @param array<string, mixed> $data Configuration data.
	 */
	public function __construct( array $data = [] ) {
		$this->data = $data;
	}

	/**
	 * Load configuration from a PHP file that returns an array.
	 *
	 * Returns an empty config if the file does not exist or is not readable.
	 *
	 * @param string $path Filesystem path to the config file.
	 * @throws RuntimeException When the file does not return an array.
	 */
	public static function fromFile( string $path ): self {
		if ( ! \is_file( $path ) || ! \is_readable( $path ) ) {
			return new self();
		}

		$data = require $path;
		if ( ! \is_array( $data ) ) {
			throw new RuntimeException( 'Config file must return an array: ' . $path );
		}

		return new self( $data );
	}

	/**
	 * Get a configuration value using dot notation.
	 *
	 * Example: $config->get('auth.api_keys') resolves to $data['auth']['api_keys'].
	 *
	 * @param string $key     Dot-separated key path.
	 * @param mixed  $default Fallback when the key does not exist.
	 */
	public function get( string $key, mixed $default = null ): mixed {
		$keys = \explode( '.', $key );
		$value = $this->data;

		foreach ( $keys as $segment ) {
			if ( ! \is_array( $value ) || ! \array_key_exists( $segment, $value ) ) {
				return $default;
			}
			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * Check whether a configuration key exists.
	 *
	 * @param string $key Dot-separated key path.
	 */
	public function has( string $key ): bool {
		$keys = \explode( '.', $key );
		$value = $this->data;

		foreach ( $keys as $segment ) {
			if ( ! \is_array( $value ) || ! \array_key_exists( $segment, $value ) ) {
				return false;
			}
			$value = $value[ $segment ];
		}

		return true;
	}

	/**
	 * Get the full configuration array.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		return $this->data;
	}
}
