<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer\Tests\Unit;

use Apermo\WpUpdateServer\Auth\FileLicenseProvider;
use PHPUnit\Framework\TestCase;

class FileLicenseProviderTest extends TestCase {

	private string $licensesFile;

	protected function setUp(): void {
		$this->licensesFile = \tempnam( \sys_get_temp_dir(), 'wpup_licenses_' );
		\file_put_contents( $this->licensesFile, \json_encode( [
			'valid-key' => [
				'packages' => [ 'my-plugin', 'other-plugin' ],
				'expires'  => '2099-01-01',
			],
			'wildcard-key' => [
				'packages' => [ '*' ],
				'expires'  => null,
			],
			'expired-key' => [
				'packages' => [ 'my-plugin' ],
				'expires'  => '2020-01-01',
			],
		] ) );
	}

	protected function tearDown(): void {
		if ( \is_file( $this->licensesFile ) ) {
			\unlink( $this->licensesFile );
		}
	}

	public function testValidKeyForAllowedSlug(): void {
		$provider = new FileLicenseProvider( $this->licensesFile );
		$this->assertTrue( $provider->validate( 'valid-key', 'my-plugin' ) );
		$this->assertTrue( $provider->validate( 'valid-key', 'other-plugin' ) );
	}

	public function testValidKeyForDisallowedSlug(): void {
		$provider = new FileLicenseProvider( $this->licensesFile );
		$this->assertFalse( $provider->validate( 'valid-key', 'unauthorized-plugin' ) );
	}

	public function testWildcardKeyAllowsAnySlug(): void {
		$provider = new FileLicenseProvider( $this->licensesFile );
		$this->assertTrue( $provider->validate( 'wildcard-key', 'anything' ) );
	}

	public function testExpiredKeyIsRejected(): void {
		$provider = new FileLicenseProvider( $this->licensesFile );
		$this->assertFalse( $provider->validate( 'expired-key', 'my-plugin' ) );
	}

	public function testUnknownKeyIsRejected(): void {
		$provider = new FileLicenseProvider( $this->licensesFile );
		$this->assertFalse( $provider->validate( 'nonexistent-key', 'my-plugin' ) );
	}

	public function testMissingFileReturnsEmptyProvider(): void {
		$provider = new FileLicenseProvider( '/nonexistent/licenses.json' );
		$this->assertFalse( $provider->validate( 'any-key', 'any-slug' ) );
	}
}
