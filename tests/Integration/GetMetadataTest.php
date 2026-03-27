<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer\Tests\Integration;

use Apermo\WpUpdateServer\Cache\FileCache;
use Apermo\WpUpdateServer\PackageRepository;
use Apermo\WpUpdateServer\ComposerEndpoint;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests verifying the metadata structure is compatible with
 * the WordPress Plugin Update Checker library and Composer.
 */
class GetMetadataTest extends TestCase {

	private static string $fixturesDir;
	private static FileCache $cache;
	private static PackageRepository $repo;

	public static function setUpBeforeClass(): void {
		self::$fixturesDir = \dirname( __DIR__ ) . '/fixtures';
		self::$cache = new FileCache( self::$fixturesDir . '/cache' );
		self::$repo = new PackageRepository( self::$fixturesDir . '/packages', self::$cache );
	}

	/**
	 * Plugin Update Checker expects: name, version, homepage, author, slug, download_url.
	 *
	 * @see https://github.com/YahnisElsts/plugin-update-checker
	 */
	public function testMetadataContainsPluginUpdateCheckerFields(): void {
		$package = self::$repo->findPackage( 'hello-dolly' );
		$this->assertNotNull( $package );

		$meta = $package->getMetadata();

		// Required fields for plugin-update-checker compatibility.
		$this->assertArrayHasKey( 'name', $meta );
		$this->assertArrayHasKey( 'version', $meta );
		$this->assertArrayHasKey( 'slug', $meta );
		$this->assertSame( 'hello-dolly', $meta['slug'] );
		$this->assertSame( '1.1.0', $meta['version'] );
		$this->assertSame( 'Hello Dolly', $meta['name'] );
	}

	public function testMetadataContainsRequiresPHP(): void {
		$package = self::$repo->findPackage( 'hello-dolly' );
		$this->assertNotNull( $package );
		$meta = $package->getMetadata();

		$this->assertArrayHasKey( 'requires_php', $meta );
		$this->assertSame( '7.4', $meta['requires_php'] );
	}

	public function testThemeMetadataContainsCorrectType(): void {
		$package = self::$repo->findPackage( 'starter-theme' );
		$this->assertNotNull( $package );

		$meta = $package->getMetadata();
		$this->assertSame( 'theme', $meta['type'] );
		$this->assertSame( 'starter-theme', $meta['slug'] );
		$this->assertSame( '2.0.0', $meta['version'] );
	}

	/**
	 * Composer packages.json must conform to the Composer repository format.
	 *
	 * @see https://getcomposer.org/doc/05-repositories.md#composer
	 */
	public function testComposerPackagesJsonStructure(): void {
		$endpoint = new ComposerEndpoint( self::$repo, 'https://example.com/', 'test-vendor' );
		$json = $endpoint->generatePackagesJson();

		$this->assertArrayHasKey( 'packages', $json );
		$packages = $json['packages'];

		// Should contain both test packages.
		$this->assertArrayHasKey( 'test-vendor/hello-dolly', $packages );
		$this->assertArrayHasKey( 'test-vendor/starter-theme', $packages );
	}

	public function testComposerPluginEntryStructure(): void {
		$endpoint = new ComposerEndpoint( self::$repo, 'https://example.com/', 'test-vendor' );
		$json = $endpoint->generatePackagesJson();
		$versions = $json['packages']['test-vendor/hello-dolly'];

		// Should list all 3 versions.
		$this->assertArrayHasKey( '1.0.0', $versions );
		$this->assertArrayHasKey( '1.1.0', $versions );
		$this->assertArrayHasKey( '1.2.0-beta.1', $versions );

		// Verify required Composer fields.
		$entry = $versions['1.1.0'];
		$this->assertSame( 'test-vendor/hello-dolly', $entry['name'] );
		$this->assertSame( '1.1.0', $entry['version'] );
		$this->assertSame( 'wordpress-plugin', $entry['type'] );
		$this->assertArrayHasKey( 'dist', $entry );
		$this->assertSame( 'zip', $entry['dist']['type'] );
		$this->assertStringContainsString( 'action=download', $entry['dist']['url'] );
		$this->assertStringContainsString( 'slug=hello-dolly', $entry['dist']['url'] );
		$this->assertStringContainsString( 'version=1.1.0', $entry['dist']['url'] );

		// PHP requirement should be present.
		$this->assertArrayHasKey( 'require', $entry );
		$this->assertSame( '>=7.4', $entry['require']['php'] );
	}

	public function testComposerThemeEntryHasCorrectType(): void {
		$endpoint = new ComposerEndpoint( self::$repo, 'https://example.com/', 'test-vendor' );
		$json = $endpoint->generatePackagesJson();
		$entry = $json['packages']['test-vendor/starter-theme']['2.0.0'];

		$this->assertSame( 'wordpress-theme', $entry['type'] );
	}

	public function testVersionedDownloadUrlFormat(): void {
		$endpoint = new ComposerEndpoint( self::$repo, 'https://updates.example.com/', 'vendor' );
		$json = $endpoint->generatePackagesJson();
		$entry = $json['packages']['vendor/hello-dolly']['1.0.0'];

		// The download URL must be query-string based (plugin-update-checker compatible).
		$url = $entry['dist']['url'];
		$this->assertMatchesRegularExpression(
			'#^https://updates\.example\.com/\?action=download&slug=hello-dolly&version=1\.0\.0$#',
			$url,
		);
	}
}
