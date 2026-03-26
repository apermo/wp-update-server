<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer;

/**
 * Generates Composer-compatible packages.json responses.
 */
/**
 * Generates Composer-compatible packages.json responses.
 *
 * Exposes all packages in the repository as a Composer v1/v2 repository,
 * enabling `composer require vendor/slug` for WordPress plugins and themes.
 */
class ComposerEndpoint {

	/**
	 * Package source for listing slugs and versions.
	 *
	 * @var PackageRepository
	 */
	private PackageRepository $repository;

	/**
	 * Base URL of the update server.
	 *
	 * @var string
	 */
	private string $serverUrl;

	/**
	 * Composer vendor prefix (e.g. 'apermo').
	 *
	 * @var string
	 */
	private string $vendorPrefix;

	/**
	 * @param PackageRepository $repository   Package source.
	 * @param string            $serverUrl    Base URL of the update server.
	 * @param string            $vendorPrefix Composer vendor prefix.
	 */
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
	 * @return array{packages: array<string, array<string, mixed>>} Composer repository format.
	 */
	public function generatePackagesJson(): array {
		$packages = [];

		foreach ( $this->repository->listSlugs() as $slug ) {
			$allVersions = $this->repository->findAllVersions( $slug );
			$composerName = $this->vendorPrefix . '/' . $slug;

			foreach ( $allVersions as $package ) {
				$meta = $package->getMetadata();
				$version = $meta['version'] ?? 'dev-main';
				$type = ( $meta['type'] ?? 'plugin' ) === 'theme'
					? 'wordpress-theme'
					: 'wordpress-plugin';

				$entry = [
					'name'    => $composerName,
					'version' => $version,
					'type'    => $type,
					'dist'    => [
						'url'  => $this->generateDownloadUrl( $slug, $version ),
						'type' => 'zip',
					],
				];

				$require = $this->buildRequirements( $meta );
				if ( ! empty( $require ) ) {
					$entry['require'] = $require;
				}

				$packages[ $composerName ][ $version ] = $entry;
			}
		}

		return [ 'packages' => $packages ];
	}

	/**
	 * Build a download URL for a specific package version.
	 *
	 * @param string $slug    Package slug.
	 * @param string $version Version string.
	 */
	private function generateDownloadUrl( string $slug, string $version ): string {
		$query = \http_build_query(
			[
				'action'  => 'download',
				'slug'    => $slug,
				'version' => $version,
			],
			'',
			'&',
		);

		$separator = \str_contains( $this->serverUrl, '?' ) ? '&' : '?';
		return $this->serverUrl . $separator . $query;
	}

	/**
	 * Build the Composer "require" section from package metadata.
	 *
	 * @param array<string, mixed> $meta Package metadata.
	 * @return array<string, string> Composer requirement constraints.
	 */
	private function buildRequirements( array $meta ): array {
		$require = [];
		if ( ! empty( $meta['requires_php'] ) ) {
			$require['php'] = '>=' . $meta['requires_php'];
		}
		return $require;
	}
}
