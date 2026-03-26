<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer\Tests\Unit;

use Apermo\WpUpdateServer\Validation\ValidationResult;
use PHPUnit\Framework\TestCase;

class ValidationResultTest extends TestCase {

	public function testValidResultHasNoErrors(): void {
		$result = new ValidationResult();
		$this->assertTrue( $result->isValid() );
		$this->assertSame( [], $result->getErrors() );
	}

	public function testInvalidResultHasErrors(): void {
		$errors = [ 'Missing plugin header', 'Wrong slug' ];
		$result = new ValidationResult( $errors );
		$this->assertFalse( $result->isValid() );
		$this->assertSame( $errors, $result->getErrors() );
	}
}
