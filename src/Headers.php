<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;

/**
 * HTTP header collection with case-insensitive access.
 *
 * Normalizes header names to Title-Case-With-Dashes and provides
 * array-like access via ArrayAccess, IteratorAggregate, and Countable.
 */
class Headers implements ArrayAccess, IteratorAggregate, Countable {

	/**
	 * Headers stored in $_SERVER that lack the standard HTTP_ prefix.
	 *
	 * @var string[]
	 */
	protected static array $unprefixedNames = [
		'CONTENT_TYPE',
		'CONTENT_LENGTH',
		'PHP_AUTH_USER',
		'PHP_AUTH_PW',
		'PHP_AUTH_DIGEST',
		'AUTH_TYPE',
	];

	/** @var array<string, string> Normalized header name => value pairs. */
	protected array $headers = [];

	/**
	 * Create a new Headers instance.
	 *
	 * @param array<string, string> $headers Raw header name => value pairs.
	 */
	public function __construct( array $headers = [] ) {
		foreach ( $headers as $name => $value ) {
			$this->set( $name, $value );
		}
	}

	/**
	 * Extract HTTP headers from an array of data (usually $_SERVER).
	 *
	 * @param array<string, mixed> $environment The server environment array.
	 * @return array<string, string> Extracted headers with HTTP_ prefix removed.
	 */
	public static function parse( array $environment ): array {
		$results = [];
		foreach ( $environment as $key => $value ) {
			$key = \strtoupper( $key );
			if ( self::isHeaderName( $key ) ) {
				$key = \preg_replace( '/^HTTP[_-]/', '', $key );
				$results[ $key ] = $value;
			}
		}
		return $results;
	}

	/**
	 * Check if a $_SERVER key looks like a HTTP header name.
	 *
	 * @param string $key Uppercased $_SERVER key.
	 */
	protected static function isHeaderName( string $key ): bool {
		return \str_starts_with( $key, 'X_' )
			|| \str_starts_with( $key, 'HTTP_' )
			|| \in_array( $key, static::$unprefixedNames, true );
	}

	/**
	 * Parse headers for the current HTTP request.
	 *
	 * Prefers getallheaders() when available, falls back to parsing $_SERVER.
	 *
	 * @return array<string, string> HTTP headers for the current request.
	 */
	public static function parseCurrent(): array {
		if ( \function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( $headers !== false ) {
				return $headers;
			}
		}
		return self::parse( $_SERVER );
	}

	/**
	 * Convert a header name to Title-Case-With-Dashes.
	 *
	 * @param string $name Raw header name with underscores or dashes.
	 */
	protected function normalizeName( string $name ): string {
		$name = \strtolower( $name );
		$name = \str_replace( [ '_', '-' ], ' ', $name );
		$name = \ucwords( $name );
		return \str_replace( ' ', '-', $name );
	}

	/**
	 * Get the value of a HTTP header.
	 *
	 * @param string      $name    Header name (case-insensitive).
	 * @param string|null $default Value returned when the header is not set.
	 */
	public function get( string $name, ?string $default = null ): ?string {
		$name = $this->normalizeName( $name );
		return $this->headers[ $name ] ?? $default;
	}

	/**
	 * Set a header value.
	 *
	 * @param string $name  Header name (will be normalized).
	 * @param string $value Header value.
	 */
	public function set( string $name, string $value ): void {
		$name = $this->normalizeName( $name );
		$this->headers[ $name ] = $value;
	}

	/**
	 * Check if a header exists (ArrayAccess).
	 *
	 * @param mixed $offset Header name.
	 */
	public function offsetExists( mixed $offset ): bool {
		return \array_key_exists( $offset, $this->headers );
	}

	/**
	 * Get a header value (ArrayAccess).
	 *
	 * @param mixed $offset Header name.
	 */
	public function offsetGet( mixed $offset ): mixed {
		return $this->get( $offset );
	}

	/**
	 * Set a header value (ArrayAccess).
	 *
	 * @param mixed $offset Header name.
	 * @param mixed $value  Header value.
	 */
	public function offsetSet( mixed $offset, mixed $value ): void {
		$this->set( $offset, $value );
	}

	/**
	 * Remove a header (ArrayAccess).
	 *
	 * @param mixed $offset Header name.
	 */
	public function offsetUnset( mixed $offset ): void {
		$name = $this->normalizeName( $offset );
		unset( $this->headers[ $name ] );
	}

	/**
	 * Return the number of headers.
	 */
	public function count(): int {
		return \count( $this->headers );
	}

	/**
	 * Return an iterator over all headers.
	 */
	public function getIterator(): ArrayIterator {
		return new ArrayIterator( $this->headers );
	}
}
