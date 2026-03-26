<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer;

use Apermo\WpUpdateServer\Cache\CacheInterface;
use Apermo\WpUpdateServer\Exception\InvalidPackageException;

class PackageRepository {

	protected string $packageDirectory;
	protected ?CacheInterface $cache;
	protected bool $legacyFlatEnabled;

	/** @var callable */
	protected $packageFileLoader;

	public function __construct(
		string $packageDirectory,
		?CacheInterface $cache = null,
		bool $legacyFlatEnabled = false,
		?callable $packageFileLoader = null,
	) {
		$this->packageDirectory = $packageDirectory;
		$this->cache = $cache;
		$this->legacyFlatEnabled = $legacyFlatEnabled;
		$this->packageFileLoader = $packageFileLoader ?? [Package::class, 'fromArchive'];
	}

	/**
	 * Find a specific version, the latest for a channel, or fall back to legacy flat layout.
	 */
	public function findPackage(
		string $slug,
		?string $version = null,
		string $channel = 'stable',
	): ?Package {
		$safeSlug = self::sanitizeSlug($slug);

		if ($version !== null) {
			return $this->loadSpecificVersion($safeSlug, $version);
		}

		$versionMap = $this->scanVersionedDirectory($safeSlug);
		if (!empty($versionMap)) {
			$latest = VersionUtils::getLatest(array_keys($versionMap), $channel);
			if ($latest === null) {
				return null;
			}
			return $this->loadPackageFile($versionMap[$latest], $safeSlug);
		}

		if ($this->legacyFlatEnabled) {
			return $this->findLegacyPackage($safeSlug);
		}

		return null;
	}

	/**
	 * Return all versions of a package as Package instances, sorted newest first.
	 *
	 * @return Package[]
	 */
	public function findAllVersions(string $slug): array {
		$safeSlug = self::sanitizeSlug($slug);
		$versionMap = $this->scanVersionedDirectory($safeSlug);

		if ($this->legacyFlatEnabled && empty($versionMap)) {
			$legacy = $this->findLegacyPackage($safeSlug);
			return $legacy !== null ? [$legacy] : [];
		}

		$versions = array_keys($versionMap);
		usort($versions, fn(string $a, string $b): int => VersionUtils::compareVersions($b, $a));

		$packages = [];
		foreach ($versions as $version) {
			$pkg = $this->loadPackageFile($versionMap[$version], $safeSlug);
			if ($pkg !== null) {
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

		if (is_dir($this->packageDirectory)) {
			foreach (new \DirectoryIterator($this->packageDirectory) as $entry) {
				if ($entry->isDot() || !$entry->isDir()) {
					continue;
				}
				$name = $entry->getFilename();
				$versionMap = $this->scanVersionedDirectory($name);
				if (!empty($versionMap)) {
					$slugs[] = $name;
				}
			}
		}

		if ($this->legacyFlatEnabled && is_dir($this->packageDirectory)) {
			foreach (glob($this->packageDirectory . '/*.zip', GLOB_NOESCAPE) as $file) {
				$name = basename($file, '.zip');
				if (!in_array($name, $slugs, true)) {
					$slugs[] = $name;
				}
			}
		}

		sort($slugs);
		return $slugs;
	}

	/**
	 * @return array<string, string> Map of version => zip file path.
	 */
	protected function scanVersionedDirectory(string $slug): array {
		$slugDir = $this->packageDirectory . '/' . $slug;
		if (!is_dir($slugDir)) {
			return [];
		}

		$versions = [];
		foreach (new \DirectoryIterator($slugDir) as $entry) {
			if ($entry->isDot() || !$entry->isDir()) {
				continue;
			}
			$versionDir = $entry->getFilename();
			$zipPath = $slugDir . '/' . $versionDir . '/' . $slug . '.zip';
			if (is_file($zipPath) && is_readable($zipPath)) {
				$versions[$versionDir] = $zipPath;
			}
		}

		return $versions;
	}

	protected function loadSpecificVersion(string $slug, string $version): ?Package {
		$zipPath = $this->packageDirectory . '/' . $slug . '/' . $version . '/' . $slug . '.zip';
		if (is_file($zipPath) && is_readable($zipPath)) {
			return $this->loadPackageFile($zipPath, $slug);
		}
		return null;
	}

	protected function findLegacyPackage(string $slug): ?Package {
		$filename = $this->packageDirectory . '/' . $slug . '.zip';
		if (is_file($filename) && is_readable($filename)) {
			return $this->loadPackageFile($filename, $slug);
		}
		return null;
	}

	protected function loadPackageFile(string $filename, string $slug): ?Package {
		try {
			return call_user_func($this->packageFileLoader, $filename, $slug, $this->cache);
		} catch (InvalidPackageException) {
			return null;
		}
	}

	protected static function sanitizeSlug(string $slug): string {
		return preg_replace('@[^a-z0-9\-_\.,+!]@i', '', $slug);
	}
}
