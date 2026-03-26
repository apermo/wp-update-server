<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer;

/**
 * Utility methods for parsing, comparing, and filtering semantic version strings.
 */
class VersionUtils {

	public const STABILITY_ALPHA  = 0;
	public const STABILITY_BETA   = 1;
	public const STABILITY_RC     = 2;
	public const STABILITY_STABLE = 3;

	private const STABILITY_MAP = [
		'alpha'  => self::STABILITY_ALPHA,
		'beta'   => self::STABILITY_BETA,
		'rc'     => self::STABILITY_RC,
		'stable' => self::STABILITY_STABLE,
	];

	/**
	 * Parse the stability level from a version string.
	 *
	 * Recognizes suffixes like -alpha.1, -beta.2, -rc.1 (case-insensitive).
	 *
	 * @param string $version Version string to parse.
	 */
	public static function parseStability( string $version ): string {
		if ( \preg_match( '/[-.](?:alpha|a)\b/i', $version ) ) {
			return 'alpha';
		}
		if ( \preg_match( '/[-.](?:beta|b)\b/i', $version ) ) {
			return 'beta';
		}
		if ( \preg_match( '/[-.]rc\b/i', $version ) ) {
			return 'rc';
		}
		return 'stable';
	}

	/**
	 * Get the numeric rank for a stability level (higher = more stable).
	 *
	 * @param string $stability Stability label (alpha, beta, rc, stable).
	 */
	public static function getStabilityRank( string $stability ): int {
		return self::STABILITY_MAP[ \strtolower( $stability ) ] ?? self::STABILITY_STABLE;
	}

	/**
	 * Check if a version is eligible for a given stability channel.
	 *
	 * @param string $version Version string.
	 * @param string $channel Minimum stability channel.
	 */
	public static function matchesChannel( string $version, string $channel ): bool {
		$versionRank = self::getStabilityRank( self::parseStability( $version ) );
		$channelRank = self::getStabilityRank( $channel );
		return $versionRank >= $channelRank;
	}

	/**
	 * Compare two version strings. Returns -1, 0, or 1.
	 *
	 * @param string $versionA First version.
	 * @param string $versionB Second version.
	 */
	public static function compareVersions( string $versionA, string $versionB ): int {
		return \version_compare( $versionA, $versionB );
	}

	/**
	 * Find the latest version from a list, filtered by stability channel.
	 *
	 * @param string[] $versions List of version strings.
	 * @param string   $channel Minimum stability channel.
	 * @return string|null The latest eligible version, or null if none match.
	 */
	public static function getLatest( array $versions, string $channel = 'stable' ): ?string {
		$eligible = \array_filter(
			$versions,
			static fn( string $version ): bool => self::matchesChannel( $version, $channel ),
		);

		if ( empty( $eligible ) ) {
			return null;
		}

		\usort( $eligible, [ self::class, 'compareVersions' ] );
		return \end( $eligible );
	}
}
