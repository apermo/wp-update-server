<?php

class Wpup_PackageRepository {

	protected string $packageDirectory;
	protected ?Wpup_Cache $cache;
	protected bool $legacyFlatEnabled;

	/** @var callable */
	protected $packageFileLoader;

	public function __construct(
		string $packageDirectory,
		?Wpup_Cache $cache = null,
		bool $legacyFlatEnabled = false,
		?callable $packageFileLoader = null,
	) {
		$this->packageDirectory = $packageDirectory;
		$this->cache = $cache;
		$this->legacyFlatEnabled = $legacyFlatEnabled;
		$this->packageFileLoader = $packageFileLoader ?? [Wpup_Package::class, 'fromArchive'];
	}

	/**
	 * Find a specific version, the latest for a channel, or fall back to legacy flat layout.
	 */
	public function findPackage(
		string $slug,
		?string $version = null,
		string $channel = 'stable',
	): ?Wpup_Package {
		$safeSlug = self::sanitizeSlug($slug);

		// Specific version requested.
		if ($version !== null) {
			return $this->loadSpecificVersion($safeSlug, $version);
		}

		// Scan versioned directory for all available versions.
		$versionMap = $this->scanVersionedDirectory($safeSlug);
		if (!empty($versionMap)) {
			$latest = Wpup_VersionUtils::getLatest(array_keys($versionMap), $channel);
			if ($latest === null) {
				return null;
			}
			return $this->loadPackageFile($versionMap[$latest], $safeSlug);
		}

		// Fall back to legacy flat file if enabled.
		if ($this->legacyFlatEnabled) {
			return $this->findLegacyPackage($safeSlug);
		}

		return null;
	}

	/**
	 * Return all versions of a package as Wpup_Package instances, sorted newest first.
	 *
	 * @return Wpup_Package[]
	 */
	public function findAllVersions(string $slug): array {
		$safeSlug = self::sanitizeSlug($slug);
		$versionMap = $this->scanVersionedDirectory($safeSlug);

		// Include legacy flat file as a version if applicable.
		if ($this->legacyFlatEnabled && empty($versionMap)) {
			$legacy = $this->findLegacyPackage($safeSlug);
			return $legacy !== null ? [$legacy] : [];
		}

		// Sort versions descending.
		$versions = array_keys($versionMap);
		usort($versions, fn(string $a, string $b): int => Wpup_VersionUtils::compareVersions($b, $a));

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
	 * Return all known package slugs (both versioned directories and legacy flat files).
	 *
	 * @return string[]
	 */
	public function listSlugs(): array {
		$slugs = [];

		// Scan for versioned directories (directories containing version subdirs).
		if (is_dir($this->packageDirectory)) {
			foreach (new \DirectoryIterator($this->packageDirectory) as $entry) {
				if ($entry->isDot() || !$entry->isDir()) {
					continue;
				}
				$name = $entry->getFilename();
				if ($name === '.' || $name === '..') {
					continue;
				}
				// Check if this directory contains version subdirectories.
				$versionMap = $this->scanVersionedDirectory($name);
				if (!empty($versionMap)) {
					$slugs[] = $name;
				}
			}
		}

		// Scan for legacy flat files.
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
	 * Scan the versioned directory structure for a slug.
	 *
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

	protected function loadSpecificVersion(string $slug, string $version): ?Wpup_Package {
		$zipPath = $this->packageDirectory . '/' . $slug . '/' . $version . '/' . $slug . '.zip';
		if (is_file($zipPath) && is_readable($zipPath)) {
			return $this->loadPackageFile($zipPath, $slug);
		}
		return null;
	}

	protected function findLegacyPackage(string $slug): ?Wpup_Package {
		$filename = $this->packageDirectory . '/' . $slug . '.zip';
		if (is_file($filename) && is_readable($filename)) {
			return $this->loadPackageFile($filename, $slug);
		}
		return null;
	}

	protected function loadPackageFile(string $filename, string $slug): ?Wpup_Package {
		try {
			return call_user_func($this->packageFileLoader, $filename, $slug, $this->cache);
		} catch (Wpup_InvalidPackageException) {
			return null;
		}
	}

	protected static function sanitizeSlug(string $slug): string {
		return preg_replace('@[^a-z0-9\-_\.,+!]@i', '', $slug);
	}
}
