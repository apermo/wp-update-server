<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer\Validation;

/**
 * Holds the outcome of a package validation check.
 */
class ValidationResult {

	/**
	 * Validation error messages, empty when valid.
	 *
	 * @var string[]
	 */
	private array $errors;

	/**
	 * @param string[] $errors Validation error messages, empty when valid.
	 */
	public function __construct( array $errors = [] ) {
		$this->errors = $errors;
	}

	/**
	 * Whether the validation passed without errors.
	 */
	public function isValid(): bool {
		return empty( $this->errors );
	}

	/**
	 * Get the list of validation error messages.
	 *
	 * @return string[]
	 */
	public function getErrors(): array {
		return $this->errors;
	}
}
