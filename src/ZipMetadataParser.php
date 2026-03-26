<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer;

use Apermo\WpUpdateServer\Cache\CacheInterface;
use Apermo\WpUpdateServer\Exception\InvalidPackageException;

/**
 * Extracts and caches metadata from WordPress plugin/theme zip archives.
 */
class ZipMetadataParser {

	/**
	 * How long the package metadata should be cached in seconds.
	 * Defaults to 1 week (7 * 24 * 60 * 60).
	 */
	public static int $cacheTime = 604800;

	protected array $headerMap = [
		'Name' => 'name',
		'Version' => 'version',
		'PluginURI' => 'homepage',
		'ThemeURI' => 'homepage',
		'Author' => 'author',
		'AuthorURI' => 'author_homepage',
		'RequiresPHP' => 'requires_php',
		'DetailsURI' => 'details_url',
		'Depends' => 'depends',
		'Provides' => 'provides',
	];

	protected array $readmeMap = [
		'requires',
		'tested',
		'requires_php',
	];

	protected array|bool $packageInfo;
	protected string $filename;
	protected ?string $slug;
	protected ?CacheInterface $cache;
	protected ?array $metadata = null;

	public function __construct(?string $slug, string $filename, ?CacheInterface $cache = null) {
		$this->slug = $slug;
		$this->filename = $filename;
		$this->cache = $cache;

		$this->setMetadata();
	}

	public function get(): ?array {
		return $this->metadata;
	}

	protected function setMetadata(): void {
		$cacheKey = $this->generateCacheKey();

		if ($this->cache !== null) {
			$cached = $this->cache->get($cacheKey);
			if (is_array($cached)) {
				$this->metadata = $cached;
				return;
			}
		}

		$this->extractMetadata();

		if ($this->cache !== null) {
			$this->cache->set($cacheKey, $this->metadata, static::$cacheTime);
		}
	}

	/**
	 * @throws InvalidPackageException
	 */
	protected function extractMetadata(): void {
		$this->packageInfo = \WshWordPressPackageParser::parsePackage($this->filename, true);
		if (is_array($this->packageInfo) && $this->packageInfo !== []) {
			$this->setInfoFromHeader();
			$this->setInfoFromReadme();
			$this->setLastUpdateDate();
			$this->setSlug();
			$this->metadata['type'] = $this->packageInfo['type'] ?? 'plugin';
		} else {
			throw new InvalidPackageException(
				sprintf('The specified file %s does not contain a valid WordPress plugin or theme.', $this->filename)
			);
		}
	}

	protected function setInfoFromHeader(): void {
		if (!empty($this->packageInfo['header'])) {
			$this->setMappedFields($this->packageInfo['header'], $this->headerMap);
			$this->setThemeDetailsUrl();
		}
	}

	protected function setInfoFromReadme(): void {
		if (!empty($this->packageInfo['readme'])) {
			$readmeMap = array_combine(array_values($this->readmeMap), $this->readmeMap);
			$this->setMappedFields($this->packageInfo['readme'], $readmeMap);
			$this->setReadmeSections();
			$this->setReadmeUpgradeNotice();
		}
	}

	protected function setMappedFields(array $input, array $map): void {
		foreach ($map as $fieldKey => $metaKey) {
			if (!empty($input[$fieldKey])) {
				$this->metadata[$metaKey] = $input[$fieldKey];
			}
		}
	}

	protected function setThemeDetailsUrl(): void {
		if ($this->packageInfo['type'] === 'theme' && !isset($this->metadata['details_url']) && isset($this->metadata['homepage'])) {
			$this->metadata['details_url'] = $this->metadata['homepage'];
		}
	}

	protected function setReadmeSections(): void {
		if (is_array($this->packageInfo['readme']['sections']) && $this->packageInfo['readme']['sections'] !== []) {
			foreach ($this->packageInfo['readme']['sections'] as $sectionName => $sectionContent) {
				$sectionName = str_replace(' ', '_', strtolower($sectionName));
				$this->metadata['sections'][$sectionName] = $sectionContent;
			}
		}
	}

	protected function setReadmeUpgradeNotice(): void {
		if (isset($this->metadata['sections']['upgrade_notice'], $this->metadata['version'])) {
			$regex = '@<h4>\s*' . preg_quote($this->metadata['version']) . '\s*</h4>[^<>]*?<p>(.+?)</p>@i';
			if (preg_match($regex, $this->metadata['sections']['upgrade_notice'], $matches)) {
				$this->metadata['upgrade_notice'] = trim(strip_tags($matches[1]));
			}
		}
	}

	protected function setLastUpdateDate(): void {
		if (!isset($this->metadata['last_updated'])) {
			$this->metadata['last_updated'] = gmdate('Y-m-d H:i:s', filemtime($this->filename));
		}
	}

	protected function setSlug(): void {
		$mainFile = $this->packageInfo['type'] === 'plugin'
			? $this->packageInfo['pluginFile']
			: $this->packageInfo['stylesheet'];
		$this->metadata['slug'] = basename(dirname(strtolower($mainFile)));
	}

	protected function generateCacheKey(): string {
		return 'metadata-b64-' . $this->slug . '-' . md5($this->filename . '|' . filesize($this->filename) . '|' . filemtime($this->filename));
	}
}
