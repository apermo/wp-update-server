<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer\Validation;

class ValidationResult {

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
