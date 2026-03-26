<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer\Tests\Unit;

use Apermo\WpUpdateServer\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase {

	public function testEmptyConfigReturnsDefaults(): void {
		$config = new Config();
		$this->assertNull( $config->get( 'nonexistent' ) );
		$this->assertSame( 'fallback', $config->get( 'nonexistent', 'fallback' ) );
		$this->assertFalse( $config->has( 'nonexistent' ) );
		$this->assertSame( [], $config->all() );
	}

	public function testDotNotationAccess(): void {
		$config = new Config( [
			'auth' => [
				'require_license' => true,
				'public_packages' => [ 'free-plugin' ],
			],
		] );

		$this->assertTrue( $config->get( 'auth.require_license' ) );
		$this->assertSame( [ 'free-plugin' ], $config->get( 'auth.public_packages' ) );
		$this->assertTrue( $config->has( 'auth.require_license' ) );
		$this->assertFalse( $config->has( 'auth.nonexistent' ) );
	}

	public function testTopLevelAccess(): void {
		$config = new Config( [ 'vendor_prefix' => 'apermo' ] );
		$this->assertSame( 'apermo', $config->get( 'vendor_prefix' ) );
		$this->assertTrue( $config->has( 'vendor_prefix' ) );
	}

	public function testFromFileMissingFileReturnsEmpty(): void {
		$config = Config::fromFile( '/nonexistent/config.php' );
		$this->assertSame( [], $config->all() );
	}

	public function testFromFileLoadsArray(): void {
		$tmpFile = \tempnam( \sys_get_temp_dir(), 'wpup_config_' );
		\file_put_contents( $tmpFile, "<?php\nreturn ['vendor_prefix' => 'test'];\n" );

		$config = Config::fromFile( $tmpFile );
		$this->assertSame( 'test', $config->get( 'vendor_prefix' ) );

		\unlink( $tmpFile );
	}

	public function testFromFileThrowsOnNonArray(): void {
		$tmpFile = \tempnam( \sys_get_temp_dir(), 'wpup_config_' );
		\file_put_contents( $tmpFile, "<?php\nreturn 'not an array';\n" );

		$this->expectException( \RuntimeException::class );
		Config::fromFile( $tmpFile );

		\unlink( $tmpFile );
	}
}
