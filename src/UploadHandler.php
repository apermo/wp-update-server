<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer;

use Apermo\WpUpdateServer\Cache\CacheInterface;
use Apermo\WpUpdateServer\Cache\FileCache;
use Apermo\WpUpdateServer\Validation\ZipValidator;

class UploadHandler {

	private string $packageDirectory;
	private ?CacheInterface $cache;

	public function __construct(string $packageDirectory, ?CacheInterface $cache = null) {
		$this->packageDirectory = $packageDirectory;
		$this->cache = $cache;
	}

	/**
	 * Process an uploaded ZIP file.
	 *
	 * @param array $fileInfo The $_FILES entry for the uploaded file.
	 * @param string|null $expectedSlug Optional slug to validate against.
	 * @return array{slug: string, version: string, path: string, metadata: array}
	 * @throws \RuntimeException On validation or filesystem errors.
	 */
	public function handleUpload(array $fileInfo, ?string $expectedSlug = null): array {
		$this->validateUploadedFile($fileInfo);

		$tmpFile = $fileInfo['tmp_name'];

		$result = ZipValidator::validate($tmpFile, $expectedSlug);
		if (!$result->isValid()) {
			throw new \RuntimeException(
				'Invalid package: ' . implode('; ', $result->getErrors())
			);
		}

		$packageInfo = \WshWordPressPackageParser::parsePackage($tmpFile);
		if ($packageInfo === false) {
			throw new \RuntimeException('Could not parse package metadata.');
		}

		$slug = $this->detectSlug($packageInfo, $expectedSlug);
		$version = $packageInfo['header']['Version'] ?? null;
		if (empty($version)) {
			throw new \RuntimeException('Package does not contain a version header.');
		}

		$targetDir = $this->packageDirectory . '/' . $slug . '/' . $version;
		if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
			throw new \RuntimeException('Failed to create directory: ' . $targetDir);
		}

		$targetPath = $targetDir . '/' . $slug . '.zip';

		if (is_file($targetPath)) {
			throw new \RuntimeException(sprintf(
				'Version %s of %s already exists. Use ?force=1 to overwrite.',
				$version,
				$slug,
			));
		}

		if (!move_uploaded_file($tmpFile, $targetPath)) {
			throw new \RuntimeException('Failed to move uploaded file to target location.');
		}

		if ($this->cache instanceof FileCache) {
			$this->cache->clearBySlug($slug);
		}

		$package = Package::fromArchive($targetPath, $slug, $this->cache);

		return [
			'slug'     => $slug,
			'version'  => $version,
			'path'     => $targetPath,
			'metadata' => $package->getMetadata(),
		];
	}

	/**
	 * Handle a forced re-upload of an existing version.
	 */
	public function handleForceUpload(array $fileInfo, ?string $expectedSlug = null): array {
		$this->validateUploadedFile($fileInfo);

		$tmpFile = $fileInfo['tmp_name'];

		$result = ZipValidator::validate($tmpFile, $expectedSlug);
		if (!$result->isValid()) {
			throw new \RuntimeException(
				'Invalid package: ' . implode('; ', $result->getErrors())
			);
		}

		$packageInfo = \WshWordPressPackageParser::parsePackage($tmpFile);
		if ($packageInfo === false) {
			throw new \RuntimeException('Could not parse package metadata.');
		}

		$slug = $this->detectSlug($packageInfo, $expectedSlug);
		$version = $packageInfo['header']['Version'] ?? null;
		if (empty($version)) {
			throw new \RuntimeException('Package does not contain a version header.');
		}

		$targetDir = $this->packageDirectory . '/' . $slug . '/' . $version;
		if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
			throw new \RuntimeException('Failed to create directory: ' . $targetDir);
		}

		$targetPath = $targetDir . '/' . $slug . '.zip';

		if (!move_uploaded_file($tmpFile, $targetPath)) {
			throw new \RuntimeException('Failed to move uploaded file to target location.');
		}

		if ($this->cache instanceof FileCache) {
			$this->cache->clearBySlug($slug);
		}

		$package = Package::fromArchive($targetPath, $slug, $this->cache);

		return [
			'slug'     => $slug,
			'version'  => $version,
			'path'     => $targetPath,
			'metadata' => $package->getMetadata(),
		];
	}

	private function validateUploadedFile(array $fileInfo): void {
		if (!isset($fileInfo['error'])) {
			throw new \RuntimeException('No file was uploaded.');
		}

		if ($fileInfo['error'] !== UPLOAD_ERR_OK) {
			$message = match ($fileInfo['error']) {
				UPLOAD_ERR_INI_SIZE,
				UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the maximum allowed size.',
				UPLOAD_ERR_PARTIAL   => 'The file was only partially uploaded.',
				UPLOAD_ERR_NO_FILE   => 'No file was uploaded.',
				UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary directory on the server.',
				UPLOAD_ERR_CANT_WRITE => 'Failed to write the uploaded file to disk.',
				default              => 'Unknown upload error (code ' . $fileInfo['error'] . ').',
			};
			throw new \RuntimeException($message);
		}

		if (!is_uploaded_file($fileInfo['tmp_name'])) {
			throw new \RuntimeException('Invalid upload.');
		}
	}

	private function detectSlug(array $packageInfo, ?string $expectedSlug): string {
		if ($expectedSlug !== null) {
			return preg_replace('@[^a-z0-9\-_\.,+!]@i', '', $expectedSlug);
		}

		$mainFile = $packageInfo['pluginFile'] ?? $packageInfo['stylesheet'] ?? null;
		if ($mainFile === null) {
			throw new \RuntimeException('Could not determine package slug.');
		}

		return basename(dirname(strtolower($mainFile)));
	}
}
