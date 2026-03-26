<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer;

class Headers implements \ArrayAccess, \IteratorAggregate, \Countable {

	protected array $headers = [];

	/**
	 * HTTP headers stored in the $_SERVER array are usually prefixed with "HTTP_" or "X_".
	 * These special headers don't have that prefix, so we need an explicit list to identify them.
	 */
	protected static array $unprefixedNames = [
		'CONTENT_TYPE',
		'CONTENT_LENGTH',
		'PHP_AUTH_USER',
		'PHP_AUTH_PW',
		'PHP_AUTH_DIGEST',
		'AUTH_TYPE',
	];

	public function __construct(array $headers = []) {
		foreach ($headers as $name => $value) {
			$this->set($name, $value);
		}
	}

	/**
	 * Extract HTTP headers from an array of data (usually $_SERVER).
	 */
	public static function parse(array $environment): array {
		$results = [];
		foreach ($environment as $key => $value) {
			$key = strtoupper($key);
			if (self::isHeaderName($key)) {
				$key = preg_replace('/^HTTP[_-]/', '', $key);
				$results[$key] = $value;
			}
		}
		return $results;
	}

	protected static function isHeaderName(string $key): bool {
		return str_starts_with($key, 'X_')
			|| str_starts_with($key, 'HTTP_')
			|| in_array($key, static::$unprefixedNames, true);
	}

	/**
	 * Parse headers for the current HTTP request.
	 */
	public static function parseCurrent(): array {
		if (function_exists('getallheaders')) {
			$headers = getallheaders();
			if ($headers !== false) {
				return $headers;
			}
		}
		return self::parse($_SERVER);
	}

	protected function normalizeName(string $name): string {
		$name = strtolower($name);
		$name = str_replace(['_', '-'], ' ', $name);
		$name = ucwords($name);
		return str_replace(' ', '-', $name);
	}

	/**
	 * Get the value of a HTTP header.
	 */
	public function get(string $name, ?string $default = null): ?string {
		$name = $this->normalizeName($name);
		return $this->headers[$name] ?? $default;
	}

	public function set(string $name, string $value): void {
		$name = $this->normalizeName($name);
		$this->headers[$name] = $value;
	}

	public function offsetExists(mixed $offset): bool {
		return array_key_exists($offset, $this->headers);
	}

	public function offsetGet(mixed $offset): mixed {
		return $this->get($offset);
	}

	public function offsetSet(mixed $offset, mixed $value): void {
		$this->set($offset, $value);
	}

	public function offsetUnset(mixed $offset): void {
		$name = $this->normalizeName($offset);
		unset($this->headers[$name]);
	}

	public function count(): int {
		return count($this->headers);
	}

	public function getIterator(): \ArrayIterator {
		return new \ArrayIterator($this->headers);
	}
}
