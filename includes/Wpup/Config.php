<?php

class Wpup_Config {

	private array $data;

	public function __construct(array $data = []) {
		$this->data = $data;
	}

	/**
	 * Load configuration from a PHP file that returns an array.
	 *
	 * Returns an empty config if the file does not exist or is not readable.
	 */
	public static function fromFile(string $path): self {
		if (!is_file($path) || !is_readable($path)) {
			return new self();
		}

		$data = require $path;
		if (!is_array($data)) {
			throw new RuntimeException('Config file must return an array: ' . $path);
		}

		return new self($data);
	}

	/**
	 * Get a configuration value using dot notation.
	 *
	 * Example: $config->get('auth.api_keys') resolves to $data['auth']['api_keys'].
	 */
	public function get(string $key, mixed $default = null): mixed {
		$keys = explode('.', $key);
		$value = $this->data;

		foreach ($keys as $k) {
			if (!is_array($value) || !array_key_exists($k, $value)) {
				return $default;
			}
			$value = $value[$k];
		}

		return $value;
	}

	public function has(string $key): bool {
		$keys = explode('.', $key);
		$value = $this->data;

		foreach ($keys as $k) {
			if (!is_array($value) || !array_key_exists($k, $value)) {
				return false;
			}
			$value = $value[$k];
		}

		return true;
	}

	public function all(): array {
		return $this->data;
	}
}
