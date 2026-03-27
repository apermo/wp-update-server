<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer;

use Apermo\WpUpdateServer\Cache\CacheInterface;

/**
 * Represents the collection of files and metadata that make up a WordPress plugin or theme.
 */
class Package {

	/**
	 * Filesystem path to the package ZIP file.
	 *
	 * @var ?string
	 */
	protected ?string $filename;

	/**
	 * Parsed metadata from the package header and readme.
	 *
	 * @var array<string, mixed>
	 */
	protected array $metadata = [];

	/**
	 * Package slug derived from the top-level directory name.
	 *
	 * @var string
	 */
	public string $slug;

	/**
	 * Create a new instance.
	 *
	 * @param string               $slug     Package slug.
	 * @param string|null          $filename Path to the ZIP archive.
	 * @param array<string, mixed> $metadata Pre-parsed metadata.
	 */
	public function __construct( string $slug, ?string $filename = null, array $metadata = [] ) {
		$this->slug = $slug;
		$this->filename = $filename;
		$this->metadata = $metadata;
	}

	/**
	 * Load package information from a zip archive.
	 *
	 * @param string              $filename Path to the ZIP file.
	 * @param string|null         $slug     Expected package slug.
	 * @param CacheInterface|null $cache    Optional metadata cache.
	 */
	public static function fromArchive( string $filename, ?string $slug = null, ?CacheInterface $cache = null ): self {
		$metaObj = new ZipMetadataParser( $slug, $filename, $cache );
		$metadata = $metaObj->get();

		if ( $slug === null && isset( $metadata['slug'] ) ) {
			$slug = $metadata['slug'];
		}

		return new self( $slug ?? '', $filename, $metadata );
	}

	/**
	 * Get the filesystem path to the package ZIP file.
	 */
	public function getFilename(): ?string {
		return $this->filename;
	}

	/**
	 * Get the full metadata array, including the slug.
	 *
	 * @return array<string, mixed>
	 */
	public function getMetadata(): array {
		return \array_merge( $this->metadata, [ 'slug' => $this->slug ] );
	}

	/**
	 * Get the file size of the package ZIP in bytes.
	 */
	public function getFileSize(): int {
		return \filesize( $this->filename );
	}

	/**
	 * Get the last-modified timestamp of the package ZIP.
	 */
	public function getLastModified(): int {
		return \filemtime( $this->filename );
	}

	/**
	 * Get the version string from the package metadata.
	 */
	public function getVersion(): ?string {
		return $this->metadata['version'] ?? null;
	}
}
