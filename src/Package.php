<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer;

use Apermo\WpUpdateServer\Cache\CacheInterface;

/**
 * Represents the collection of files and metadata that make up a WordPress plugin or theme.
 */
class Package {

	protected ?string $filename;
	protected array $metadata = [];
	public string $slug;

	public function __construct(string $slug, ?string $filename = null, array $metadata = []) {
		$this->slug = $slug;
		$this->filename = $filename;
		$this->metadata = $metadata;
	}

	public function getFilename(): ?string {
		return $this->filename;
	}

	public function getMetadata(): array {
		return array_merge($this->metadata, ['slug' => $this->slug]);
	}

	/**
	 * Load package information from a zip archive.
	 */
	public static function fromArchive(string $filename, ?string $slug = null, ?CacheInterface $cache = null): self {
		$metaObj = new ZipMetadataParser($slug, $filename, $cache);
		$metadata = $metaObj->get();

		if ($slug === null && isset($metadata['slug'])) {
			$slug = $metadata['slug'];
		}

		return new self($slug ?? '', $filename, $metadata);
	}

	public function getFileSize(): int {
		return filesize($this->filename);
	}

	public function getLastModified(): int {
		return filemtime($this->filename);
	}

	public function getVersion(): ?string {
		return $this->metadata['version'] ?? null;
	}
}
