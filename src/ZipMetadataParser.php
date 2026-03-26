<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer;

use Apermo\WpUpdateServer\Cache\CacheInterface;
use Apermo\WpUpdateServer\Exception\InvalidPackageException;
use WshWordPressPackageParser;

/**
 * Extracts and caches metadata from WordPress plugin/theme zip archives.
 */
class ZipMetadataParser {

	/**
	 * How long the package metadata should be cached in seconds.
	 * Defaults to 1 week (7 * 24 * 60 * 60).
	 *
	 * @var int
	 */
	public static int $cacheTime = 604800;

	/**
	 * string> Map of plugin/theme header keys to metadata field names.
	 *
	 * @var array
	 */
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

	/**
	 * Readme fields to copy directly into metadata.
	 *
	 * @var array
	 */
	protected array $readmeMap = [
		'requires',
		'tested',
		'requires_php',
	];

	/** @var array|bool Raw parsed package data from WshWordPressPackageParser. */
	protected array|bool $packageInfo;

	/**
	 * Absolute path to the ZIP archive being parsed.
	 *
	 * @var string
	 */
	protected string $filename;

	/**
	 * Package slug identifier.
	 *
	 * @var ?string
	 */
	protected ?string $slug;

	/**
	 * Cache backend for storing parsed metadata.
	 *
	 * @var ?CacheInterface
	 */
	protected ?CacheInterface $cache;

	/**
	 * Parsed and normalized package metadata.
	 *
	 * @var ?array
	 */
	protected ?array $metadata = null;

	/**
	 * @param string|null         $slug     Package slug identifier.
	 * @param string              $filename Absolute path to the ZIP archive.
	 * @param CacheInterface|null $cache    Optional cache backend for metadata.
	 */
	public function __construct( ?string $slug, string $filename, ?CacheInterface $cache = null ) {
		$this->slug = $slug;
		$this->filename = $filename;
		$this->cache = $cache;

		$this->setMetadata();
	}

	/**
	 * Return the parsed package metadata array.
	 */
	public function get(): ?array {
		return $this->metadata;
	}

	/**
	 * Load metadata from cache or extract it from the archive.
	 */
	protected function setMetadata(): void {
		$cacheKey = $this->generateCacheKey();

		if ( $this->cache !== null ) {
			$cached = $this->cache->get( $cacheKey );
			if ( \is_array( $cached ) ) {
				$this->metadata = $cached;
				return;
			}
		}

		$this->extractMetadata();

		if ( $this->cache !== null ) {
			$this->cache->set( $cacheKey, $this->metadata, static::$cacheTime );
		}
	}

	/**
	 * Parse the ZIP archive and populate the metadata array.
	 *
	 * @throws InvalidPackageException When the archive is not a valid plugin or theme.
	 */
	protected function extractMetadata(): void {
		$this->packageInfo = WshWordPressPackageParser::parsePackage( $this->filename, true );
		if ( \is_array( $this->packageInfo ) && $this->packageInfo !== [] ) {
			$this->setInfoFromHeader();
			$this->setInfoFromReadme();
			$this->setLastUpdateDate();
			$this->setSlug();
			$this->metadata['type'] = $this->packageInfo['type'] ?? 'plugin';
		} else {
			throw new InvalidPackageException(
				\sprintf( 'The specified file %s does not contain a valid WordPress plugin or theme.', $this->filename ),
			);
		}
	}

	/**
	 * Populate metadata from the plugin/theme file header.
	 */
	protected function setInfoFromHeader(): void {
		if ( ! empty( $this->packageInfo['header'] ) ) {
			$this->setMappedFields( $this->packageInfo['header'], $this->headerMap );
			$this->setThemeDetailsUrl();
		}
	}

	/**
	 * Populate metadata from the readme.txt file.
	 */
	protected function setInfoFromReadme(): void {
		if ( ! empty( $this->packageInfo['readme'] ) ) {
			$readmeMap = \array_combine( \array_values( $this->readmeMap ), $this->readmeMap );
			$this->setMappedFields( $this->packageInfo['readme'], $readmeMap );
			$this->setReadmeSections();
			$this->setReadmeUpgradeNotice();
		}
	}

	/**
	 * Copy fields from a source array into metadata using a key mapping.
	 *
	 * @param array $input Source data to read from.
	 * @param array $map   Map of source keys to metadata keys.
	 */
	protected function setMappedFields( array $input, array $map ): void {
		foreach ( $map as $fieldKey => $metaKey ) {
			if ( ! empty( $input[ $fieldKey ] ) ) {
				$this->metadata[ $metaKey ] = $input[ $fieldKey ];
			}
		}
	}

	/**
	 * Fall back to the homepage URL as the details URL for themes.
	 */
	protected function setThemeDetailsUrl(): void {
		if ( $this->packageInfo['type'] === 'theme' && ! isset( $this->metadata['details_url'] ) && isset( $this->metadata['homepage'] ) ) {
			$this->metadata['details_url'] = $this->metadata['homepage'];
		}
	}

	/**
	 * Copy readme sections (description, changelog, etc.) into metadata.
	 */
	protected function setReadmeSections(): void {
		if ( \is_array( $this->packageInfo['readme']['sections'] ) && $this->packageInfo['readme']['sections'] !== [] ) {
			foreach ( $this->packageInfo['readme']['sections'] as $sectionName => $sectionContent ) {
				$sectionName = \str_replace( ' ', '_', \strtolower( $sectionName ) );
				$this->metadata['sections'][ $sectionName ] = $sectionContent;
			}
		}
	}

	/**
	 * Extract the upgrade notice for the current version from readme sections.
	 */
	protected function setReadmeUpgradeNotice(): void {
		if ( isset( $this->metadata['sections']['upgrade_notice'], $this->metadata['version'] ) ) {
			$regex = '@<h4>\s*' . \preg_quote( $this->metadata['version'] ) . '\s*</h4>[^<>]*?<p>(.+?)</p>@i';
			if ( \preg_match( $regex, $this->metadata['sections']['upgrade_notice'], $matches ) ) {
				$this->metadata['upgrade_notice'] = \trim( \strip_tags( $matches[1] ) );
			}
		}
	}

	/**
	 * Set the last_updated timestamp from the archive's file modification time.
	 */
	protected function setLastUpdateDate(): void {
		if ( ! isset( $this->metadata['last_updated'] ) ) {
			$this->metadata['last_updated'] = \gmdate( 'Y-m-d H:i:s', \filemtime( $this->filename ) );
		}
	}

	/**
	 * Derive the package slug from the main plugin file or theme stylesheet path.
	 */
	protected function setSlug(): void {
		$mainFile = $this->packageInfo['type'] === 'plugin'
			? $this->packageInfo['pluginFile']
			: $this->packageInfo['stylesheet'];
		$this->metadata['slug'] = \basename( \dirname( \strtolower( $mainFile ) ) );
	}

	/**
	 * Generate a unique cache key based on slug, filename, size, and mtime.
	 */
	protected function generateCacheKey(): string {
		return 'metadata-b64-' . $this->slug . '-' . \md5( $this->filename . '|' . \filesize( $this->filename ) . '|' . \filemtime( $this->filename ) );
	}
}
