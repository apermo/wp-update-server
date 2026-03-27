<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer\Tests\Unit;

use Apermo\WpUpdateServer\Cache\FileCache;
use Apermo\WpUpdateServer\PackageRepository;
use PHPUnit\Framework\TestCase;

class PackageRepositoryTest extends TestCase {

	private static string $fixturesDir;
	private static FileCache $cache;

	public static function setUpBeforeClass(): void {
		self::$fixturesDir = \dirname( __DIR__ ) . '/fixtures';
		self::$cache = new FileCache( self::$fixturesDir . '/cache' );
	}

	public function testFindLatestStableVersion(): void {
		$repo = new PackageRepository( self::$fixturesDir . '/packages', self::$cache );
		$package = $repo->findPackage( 'hello-dolly' );

		$this->assertNotNull( $package );
		$this->assertSame( '1.1.0', $package->getVersion() );
		$this->assertSame( 'hello-dolly', $package->slug );
	}

	public function testFindSpecificVersion(): void {
		$repo = new PackageRepository( self::$fixturesDir . '/packages', self::$cache );
		$package = $repo->findPackage( 'hello-dolly', '1.0.0' );

		$this->assertNotNull( $package );
		$this->assertSame( '1.0.0', $package->getVersion() );
	}

	public function testFindBetaChannelIncludesPrerelease(): void {
		$repo = new PackageRepository( self::$fixturesDir . '/packages', self::$cache );
		$package = $repo->findPackage( 'hello-dolly', null, 'beta' );

		$this->assertNotNull( $package );
		$this->assertSame( '1.2.0-beta.1', $package->getVersion() );
	}

	public function testStableChannelExcludesPrerelease(): void {
		$repo = new PackageRepository( self::$fixturesDir . '/packages', self::$cache );
		$package = $repo->findPackage( 'hello-dolly', null, 'stable' );

		$this->assertNotNull( $package );
		$this->assertSame( '1.1.0', $package->getVersion() );
	}

	public function testFindNonexistentSlugReturnsNull(): void {
		$repo = new PackageRepository( self::$fixturesDir . '/packages', self::$cache );
		$this->assertNull( $repo->findPackage( 'nonexistent-plugin' ) );
	}

	public function testFindNonexistentVersionReturnsNull(): void {
		$repo = new PackageRepository( self::$fixturesDir . '/packages', self::$cache );
		$this->assertNull( $repo->findPackage( 'hello-dolly', '9.9.9' ) );
	}

	public function testFindAllVersionsSortedDescending(): void {
		$repo = new PackageRepository( self::$fixturesDir . '/packages', self::$cache );
		$packages = $repo->findAllVersions( 'hello-dolly' );

		$this->assertCount( 3, $packages );
		$this->assertSame( '1.2.0-beta.1', $packages[0]->getVersion() );
		$this->assertSame( '1.1.0', $packages[1]->getVersion() );
		$this->assertSame( '1.0.0', $packages[2]->getVersion() );
	}

	public function testFindAllVersionsForTheme(): void {
		$repo = new PackageRepository( self::$fixturesDir . '/packages', self::$cache );
		$packages = $repo->findAllVersions( 'starter-theme' );

		$this->assertCount( 1, $packages );
		$this->assertSame( '2.0.0', $packages[0]->getVersion() );
	}

	public function testListSlugs(): void {
		$repo = new PackageRepository( self::$fixturesDir . '/packages', self::$cache );
		$slugs = $repo->listSlugs();

		$this->assertContains( 'hello-dolly', $slugs );
		$this->assertContains( 'starter-theme', $slugs );
	}

	public function testThemeDetection(): void {
		$repo = new PackageRepository( self::$fixturesDir . '/packages', self::$cache );
		$package = $repo->findPackage( 'starter-theme' );

		$this->assertNotNull( $package );
		$meta = $package->getMetadata();
		$this->assertSame( 'theme', $meta['type'] );
	}

	public function testPluginDetection(): void {
		$repo = new PackageRepository( self::$fixturesDir . '/packages', self::$cache );
		$package = $repo->findPackage( 'hello-dolly' );

		$this->assertNotNull( $package );
		$meta = $package->getMetadata();
		$this->assertSame( 'plugin', $meta['type'] );
	}
}
