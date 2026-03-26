<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer;

/**
 * Generates Composer-compatible packages.json responses.
 */
class ComposerEndpoint {

	private PackageRepository $repository;
	private string $serverUrl;
	private string $vendorPrefix;

	public function __construct(
		PackageRepository $repository,
		string $serverUrl,
		string $vendorPrefix = 'wpup',
	) {
		$this->repository = $repository;
		$this->serverUrl = $serverUrl;
		$this->vendorPrefix = $vendorPrefix;
	}

	/**
	 * Generate the full packages.json response.
	 *
	 * @return array Composer repository format with a "packages" key.
	 */
	public function generatePackagesJson(): array {
		$packages = [];

		foreach ($this->repository->listSlugs() as $slug) {
			$allVersions = $this->repository->findAllVersions($slug);
			$composerName = $this->vendorPrefix . '/' . $slug;

			foreach ($allVersions as $package) {
				$meta = $package->getMetadata();
				$version = $meta['version'] ?? 'dev-main';
				$type = ($meta['type'] ?? 'plugin') === 'theme'
					? 'wordpress-theme'
					: 'wordpress-plugin';

				$entry = [
					'name'    => $composerName,
					'version' => $version,
					'type'    => $type,
					'dist'    => [
						'url'  => $this->generateDownloadUrl($slug, $version),
						'type' => 'zip',
					],
				];

				$require = $this->buildRequirements($meta);
				if (!empty($require)) {
					$entry['require'] = $require;
				}

				$packages[$composerName][$version] = $entry;
			}
		}

		return ['packages' => $packages];
	}

	private function generateDownloadUrl(string $slug, string $version): string {
		$query = http_build_query([
			'action'  => 'download',
			'slug'    => $slug,
			'version' => $version,
		], '', '&');

		$separator = str_contains($this->serverUrl, '?') ? '&' : '?';
		return $this->serverUrl . $separator . $query;
	}

	private function buildRequirements(array $meta): array {
		$require = [];
		if (!empty($meta['requires_php'])) {
			$require['php'] = '>=' . $meta['requires_php'];
		}
		return $require;
	}
}
