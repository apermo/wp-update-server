<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer\Tests\Unit;

use Apermo\WpUpdateServer\VersionUtils;
use PHPUnit\Framework\TestCase;

class VersionUtilsTest extends TestCase {

	#[\PHPUnit\Framework\Attributes\DataProvider( 'stabilityProvider' )]
	public function testParseStability( string $version, string $expected ): void {
		$this->assertSame( $expected, VersionUtils::parseStability( $version ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function stabilityProvider(): array {
		return [
			'stable release'    => [ '1.0.0', 'stable' ],
			'alpha suffix'      => [ '1.0.0-alpha.1', 'alpha' ],
			'beta suffix'       => [ '2.0.0-beta.3', 'beta' ],
			'rc suffix'         => [ '1.0.0-rc.1', 'rc' ],
			'short alpha'       => [ '1.0.0-a', 'alpha' ],
			'short beta'        => [ '1.0.0-b', 'beta' ],
			'uppercase'         => [ '1.0.0-RC.2', 'rc' ],
			'dot-separated'     => [ '1.0.0.beta.1', 'beta' ],
		];
	}

	public function testGetStabilityRank(): void {
		$this->assertSame( VersionUtils::STABILITY_ALPHA, VersionUtils::getStabilityRank( 'alpha' ) );
		$this->assertSame( VersionUtils::STABILITY_BETA, VersionUtils::getStabilityRank( 'beta' ) );
		$this->assertSame( VersionUtils::STABILITY_RC, VersionUtils::getStabilityRank( 'rc' ) );
		$this->assertSame( VersionUtils::STABILITY_STABLE, VersionUtils::getStabilityRank( 'stable' ) );
		// Unknown defaults to stable.
		$this->assertSame( VersionUtils::STABILITY_STABLE, VersionUtils::getStabilityRank( 'unknown' ) );
	}

	public function testMatchesChannel(): void {
		$this->assertTrue( VersionUtils::matchesChannel( '1.0.0', 'stable' ) );
		$this->assertFalse( VersionUtils::matchesChannel( '1.0.0-beta.1', 'stable' ) );
		$this->assertTrue( VersionUtils::matchesChannel( '1.0.0-beta.1', 'beta' ) );
		$this->assertTrue( VersionUtils::matchesChannel( '1.0.0-rc.1', 'beta' ) );
		$this->assertTrue( VersionUtils::matchesChannel( '1.0.0', 'alpha' ) );
		$this->assertFalse( VersionUtils::matchesChannel( '1.0.0-alpha.1', 'beta' ) );
	}

	public function testCompareVersions(): void {
		$this->assertSame( -1, VersionUtils::compareVersions( '1.0.0', '1.1.0' ) );
		$this->assertSame( 0, VersionUtils::compareVersions( '1.0.0', '1.0.0' ) );
		$this->assertSame( 1, VersionUtils::compareVersions( '2.0.0', '1.9.9' ) );
	}

	public function testGetLatestStable(): void {
		$versions = [ '1.0.0', '1.1.0', '1.2.0-beta.1', '0.9.0' ];
		$this->assertSame( '1.1.0', VersionUtils::getLatest( $versions, 'stable' ) );
	}

	public function testGetLatestBeta(): void {
		$versions = [ '1.0.0', '1.1.0', '1.2.0-beta.1' ];
		$this->assertSame( '1.2.0-beta.1', VersionUtils::getLatest( $versions, 'beta' ) );
	}

	public function testGetLatestReturnsNullWhenNoMatch(): void {
		$versions = [ '1.0.0-alpha.1', '1.0.0-beta.1' ];
		$this->assertNull( VersionUtils::getLatest( $versions, 'stable' ) );
	}

	public function testGetLatestWithEmptyArray(): void {
		$this->assertNull( VersionUtils::getLatest( [], 'stable' ) );
	}
}
