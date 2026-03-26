<?php

class Wpup_ValidationResult {

	private array $errors;

	public function __construct(array $errors = []) {
		$this->errors = $errors;
	}

	public function isValid(): bool {
		return empty($this->errors);
	}

	/**
	 * @return string[]
	 */
	public function getErrors(): array {
		return $this->errors;
	}
}
