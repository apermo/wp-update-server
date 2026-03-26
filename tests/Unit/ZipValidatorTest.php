<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer\Tests\Unit;

use Apermo\WpUpdateServer\Validation\ZipValidator;
use PHPUnit\Framework\TestCase;

class ZipValidatorTest extends TestCase {

	private static string $fixturesDir;

	public static function setUpBeforeClass(): void {
		self::$fixturesDir = \dirname( __DIR__ ) . '/fixtures/packages';
	}

	public function testValidPluginZip(): void {
		$result = ZipValidator::validate(
			self::$fixturesDir . '/hello-dolly/1.1.0/hello-dolly.zip',
		);
		$this->assertTrue( $result->isValid(), \implode( '; ', $result->getErrors() ) );
	}

	public function testValidThemeZip(): void {
		$result = ZipValidator::validate(
			self::$fixturesDir . '/starter-theme/2.0.0/starter-theme.zip',
		);
		$this->assertTrue( $result->isValid(), \implode( '; ', $result->getErrors() ) );
	}

	public function testSlugMismatch(): void {
		$result = ZipValidator::validate(
			self::$fixturesDir . '/hello-dolly/1.1.0/hello-dolly.zip',
			'wrong-slug',
		);
		$this->assertFalse( $result->isValid() );
		$this->assertStringContainsString( 'does not match expected slug', $result->getErrors()[0] );
	}

	public function testVersionMismatch(): void {
		$result = ZipValidator::validate(
			self::$fixturesDir . '/hello-dolly/1.1.0/hello-dolly.zip',
			'hello-dolly',
			'9.9.9',
		);
		$this->assertFalse( $result->isValid() );
		$this->assertStringContainsString( 'does not match expected', $result->getErrors()[0] );
	}

	public function testVersionMatch(): void {
		$result = ZipValidator::validate(
			self::$fixturesDir . '/hello-dolly/1.1.0/hello-dolly.zip',
			'hello-dolly',
			'1.1.0',
		);
		$this->assertTrue( $result->isValid() );
	}

	public function testNonexistentFile(): void {
		$result = ZipValidator::validate( '/nonexistent/file.zip' );
		$this->assertFalse( $result->isValid() );
		$this->assertStringContainsString( 'does not exist', $result->getErrors()[0] );
	}

	public function testInvalidZipFile(): void {
		$tmpFile = \tempnam( \sys_get_temp_dir(), 'wpup_invalid_' );
		\file_put_contents( $tmpFile, 'not a zip file' );

		$result = ZipValidator::validate( $tmpFile );
		$this->assertFalse( $result->isValid() );

		\unlink( $tmpFile );
	}
}
