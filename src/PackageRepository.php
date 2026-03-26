<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer;

use Apermo\WpUpdateServer\Cache\CacheInterface;
use Apermo\WpUpdateServer\Exception\InvalidPackageException;
use DirectoryIterator;

/**
 * Discovers and resolves WordPress plugin/theme packages from the filesystem.
 *
 * Supports both versioned directory layouts (packages/{slug}/{version}/{slug}.zip)
 * and legacy flat-file layouts (packages/{slug}.zip) with configurable fallback.
 */
class PackageRepository {

	/**
	 * Base directory containing package directories.
	 *
	 * @var string
	 */
	protected string $packageDirectory;

	/**
	 * Optional metadata cache.
	 *
	 * @var ?CacheInterface
	 */
	protected ?CacheInterface $cache;

	/**
	 * Whether to fall back to legacy packages/{slug}.zip layout.
	 *
	 * @var bool
	 */
	protected bool $legacyFlatEnabled;

	/**
	 * Factory callable for creating Package instances from ZIP files.
	 *
	 * @var mixed
	 */
	protected $packageFileLoader;

	/**
	 * @param string              $packageDirectory Base path to the packages directory.
	 * @param CacheInterface|null $cache            Optional metadata cache.
	 * @param bool                $legacyFlatEnabled Enable legacy flat-file fallback.
	 * @param callable|null       $packageFileLoader Custom package factory callable.
	 */
	public function __construct(
		string $packageDirectory,
		?CacheInterface $cache = null,
		bool $legacyFlatEnabled = false,
		?callable $packageFileLoader = null,
	) {
		$this->packageDirectory = $packageDirectory;
		$this->cache = $cache;
		$this->legacyFlatEnabled = $legacyFlatEnabled;
		$this->packageFileLoader = $packageFileLoader ?? [ Package::class, 'fromArchive' ];
	}

	/**
	 * Remove unsafe characters from a slug for filesystem use.
	 *
	 * @param string $slug Raw slug input.
	 */
	protected static function sanitizeSlug( string $slug ): string {
		return \preg_replace( '@[^a-z0-9\-_\.,+!]@i', '', $slug );
	}

	/**
	 * Find a specific version, the latest for a channel, or fall back to legacy flat layout.
	 *
	 * @param string      $slug    Package slug.
	 * @param string|null $version Specific version to load, or null for latest.
	 * @param string      $channel Minimum stability channel (stable, rc, beta, alpha).
	 */
	public function findPackage(
		string $slug,
		?string $version = null,
		string $channel = 'stable',
	): ?Package {
		$safeSlug = self::sanitizeSlug( $slug );

		if ( $version !== null ) {
			return $this->loadSpecificVersion( $safeSlug, $version );
		}

		$versionMap = $this->scanVersionedDirectory( $safeSlug );
		if ( ! empty( $versionMap ) ) {
			$latest = VersionUtils::getLatest( \array_keys( $versionMap ), $channel );
			if ( $latest === null ) {
				return null;
			}
			return $this->loadPackageFile( $versionMap[ $latest ], $safeSlug );
		}

		if ( $this->legacyFlatEnabled ) {
			return $this->findLegacyPackage( $safeSlug );
		}

		return null;
	}

	/**
	 * Return all versions of a package as Package instances, sorted newest first.
	 *
	 * @param string $slug Package slug.
	 * @return Package[]
	 */
	public function findAllVersions( string $slug ): array {
		$safeSlug = self::sanitizeSlug( $slug );
		$versionMap = $this->scanVersionedDirectory( $safeSlug );

		if ( $this->legacyFlatEnabled && empty( $versionMap ) ) {
			$legacy = $this->findLegacyPackage( $safeSlug );
			return $legacy !== null ? [ $legacy ] : [];
		}

		$versions = \array_keys( $versionMap );
		\usort( $versions, static fn( string $a, string $b ): int => VersionUtils::compareVersions( $b, $a ) );

		$packages = [];
		foreach ( $versions as $version ) {
			$pkg = $this->loadPackageFile( $versionMap[ $version ], $safeSlug );
			if ( $pkg !== null ) {
				$packages[] = $pkg;
			}
		}
		return $packages;
	}

	/**
	 * Return all known package slugs.
	 *
	 * @return string[]
	 */
	public function listSlugs(): array {
		$slugs = [];

		if ( \is_dir( $this->packageDirectory ) ) {
			foreach ( new DirectoryIterator( $this->packageDirectory ) as $entry ) {
				if ( $entry->isDot() || ! $entry->isDir() ) {
					continue;
				}
				$name = $entry->getFilename();
				$versionMap = $this->scanVersionedDirectory( $name );
				if ( ! empty( $versionMap ) ) {
					$slugs[] = $name;
				}
			}
		}

		if ( $this->legacyFlatEnabled && \is_dir( $this->packageDirectory ) ) {
			foreach ( \glob( $this->packageDirectory . '/*.zip', \GLOB_NOESCAPE ) as $file ) {
				$name = \basename( $file, '.zip' );
				if ( ! \in_array( $name, $slugs, true ) ) {
					$slugs[] = $name;
				}
			}
		}

		\sort( $slugs );
		return $slugs;
	}

	/**
	 * Scan a slug's directory for version subdirectories containing ZIP files.
	 *
	 * @param string $slug Sanitized package slug.
	 * @return array<string, string> Map of version => zip file path.
	 */
	protected function scanVersionedDirectory( string $slug ): array {
		$slugDir = $this->packageDirectory . '/' . $slug;
		if ( ! \is_dir( $slugDir ) ) {
			return [];
		}

		$versions = [];
		foreach ( new DirectoryIterator( $slugDir ) as $entry ) {
			if ( $entry->isDot() || ! $entry->isDir() ) {
				continue;
			}
			$versionDir = $entry->getFilename();
			$zipPath = $slugDir . '/' . $versionDir . '/' . $slug . '.zip';
			if ( \is_file( $zipPath ) && \is_readable( $zipPath ) ) {
				$versions[ $versionDir ] = $zipPath;
			}
		}

		return $versions;
	}

	/**
	 * Load a specific version of a package from the versioned directory layout.
	 *
	 * @param string $slug    Sanitized package slug.
	 * @param string $version Version string matching the subdirectory name.
	 */
	protected function loadSpecificVersion( string $slug, string $version ): ?Package {
		$zipPath = $this->packageDirectory . '/' . $slug . '/' . $version . '/' . $slug . '.zip';
		if ( \is_file( $zipPath ) && \is_readable( $zipPath ) ) {
			return $this->loadPackageFile( $zipPath, $slug );
		}
		return null;
	}

	/**
	 * Try the legacy flat-file layout (packages/{slug}.zip).
	 *
	 * @param string $slug Sanitized package slug.
	 */
	protected function findLegacyPackage( string $slug ): ?Package {
		$filename = $this->packageDirectory . '/' . $slug . '.zip';
		if ( \is_file( $filename ) && \is_readable( $filename ) ) {
			return $this->loadPackageFile( $filename, $slug );
		}
		return null;
	}

	/**
	 * Load a package from a ZIP file, suppressing invalid package exceptions.
	 *
	 * @param string $filename Path to the ZIP file.
	 * @param string $slug     Package slug.
	 */
	protected function loadPackageFile( string $filename, string $slug ): ?Package {
		try {
			return \call_user_func( $this->packageFileLoader, $filename, $slug, $this->cache );
		} catch ( InvalidPackageException ) {
			return null;
		}
	}
}
