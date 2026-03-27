<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer\Logging;

use Apermo\WpUpdateServer\Request;

/**
 * Handles request logging to tab-separated log files.
 *
 * Supports log rotation, IP anonymization, and column filtering/escaping.
 * Override filterLogInfo() in a subclass to customize logged data.
 */
class RequestLogger {

	public const FILE_PER_DAY = 'Y-m-d';
	public const FILE_PER_MONTH = 'Y-m';

	/**
	 * Absolute path to the log directory.
	 *
	 * @var string
	 */
	protected string $logDirectory;

	/**
	 * Whether log file rotation is enabled.
	 *
	 * @var bool
	 */
	protected bool $rotationEnabled = false;

	/**
	 * Date format suffix appended to rotated log filenames.
	 *
	 * @var string|null
	 */
	protected ?string $dateSuffix = null;

	/**
	 * Maximum number of rotated log files to keep.
	 *
	 * @var int
	 */
	protected int $backupCount = 0;

	/**
	 * Whether to anonymize IP addresses before writing.
	 *
	 * @var bool
	 */
	protected bool $ipAnonymizationEnabled = false;

	/**
	 * Binary bitmask for zeroing the last octet of IPv4 addresses.
	 *
	 * @var string
	 */
	protected string $ip4Mask;

	/**
	 * Binary bitmask for zeroing the last 80 bits of IPv6 addresses.
	 *
	 * @var string
	 */
	protected string $ip6Mask;

	/**
	 * Create a new instance.
	 *
	 * @param string $logDirectory Absolute path to the log directory.
	 */
	public function __construct( string $logDirectory ) {
		$this->logDirectory = $logDirectory;
		$this->ip4Mask = \pack( 'H*', 'ffffff00' );
		$this->ip6Mask = \pack( 'H*', 'ffffffffffff00000000000000000000' );
	}

	/**
	 * Write a log entry for the given request.
	 *
	 * @param Request $request The API request to log.
	 */
	public function log( Request $request ): void {
		$logFile = $this->getLogFileName();
		$mustRotate = $this->rotationEnabled && ! \file_exists( $logFile );

		$handle = \fopen( $logFile, 'a' );
		if ( $handle && \flock( $handle, \LOCK_EX ) ) {
			$line = $this->buildLogLine( $request );
			\fwrite( $handle, $line );

			if ( $mustRotate ) {
				$this->rotateLogs();
			}
			\flock( $handle, \LOCK_UN );
		}
		if ( $handle ) {
			\fclose( $handle );
		}
	}

	/**
	 * Build a formatted log line from a request.
	 *
	 * @param Request $request The API request.
	 */
	protected function buildLogLine( Request $request ): string {
		$loggedIp = $request->clientIp;
		if ( $this->ipAnonymizationEnabled ) {
			$loggedIp = $this->anonymizeIp( $loggedIp );
		}

		$columns = [
			'ip'                => $loggedIp,
			'http_method'       => $request->httpMethod,
			'action'            => $request->param( 'action', '-' ),
			'slug'              => $request->param( 'slug', '-' ),
			'installed_version' => $request->param( 'installed_version', '-' ),
			'wp_version'        => $request->wpVersion ?? '-',
			'site_url'          => $request->wpSiteUrl ?? '-',
			'query'             => \http_build_query( $request->query, '', '&' ),
		];
		$columns = $this->filterLogInfo( $columns, $request );
		$columns = $this->escapeLogInfo( $columns );

		if ( isset( $columns['ip'] ) ) {
			$columns['ip'] = \str_pad( $columns['ip'], 15, ' ' );
		}
		if ( isset( $columns['http_method'] ) ) {
			$columns['http_method'] = \str_pad( $columns['http_method'], 4, ' ' );
		}

		$this->ensureTimezone();

		return \date( '[Y-m-d H:i:s O]' ) . ' ' . \implode( "\t", $columns ) . "\n";
	}

	/**
	 * Ensure a timezone is set to avoid PHP notices from date().
	 */
	protected function ensureTimezone(): void {
		$configuredTz = \ini_get( 'date.timezone' );
		if ( empty( $configuredTz ) ) {
			$defaultTz = \date_default_timezone_get();
			if ( $defaultTz !== false ) {
				\date_default_timezone_set( $defaultTz );
			}
		}
	}

	/**
	 * Build the full path to the current log file, including any rotation suffix.
	 */
	protected function getLogFileName(): string {
		$path = $this->logDirectory . '/request';
		if ( $this->rotationEnabled ) {
			$path .= '-' . \date( $this->dateSuffix );
		}
		return $path . '.log';
	}

	/**
	 * Adjust information that will be logged. Override in subclasses.
	 *
	 * @param array<string, string|null> $columns Key-value pairs of log data.
	 * @param Request|null               $request The current API request, if available.
	 * @return array<string, string|null> Filtered log columns.
	 */
	protected function filterLogInfo( array $columns, ?Request $request = null ): array {
		return $columns;
	}

	/**
	 * Escape all values in a log column array.
	 *
	 * @param array<string, string|null> $columns Raw log column values.
	 * @return array<string, string|null> Escaped columns.
	 */
	protected function escapeLogInfo( array $columns ): array {
		return \array_map( [ $this, 'escapeLogValue' ], $columns );
	}

	/**
	 * Escape non-printable and non-graphic characters in a single log value.
	 *
	 * @param string|null $value The raw log value.
	 */
	protected function escapeLogValue( ?string $value ): ?string {
		if ( $value === null ) {
			return null;
		}

		$regex = '/[[:^graph:]]/';

		if ( \function_exists( 'mb_check_encoding' ) && \mb_check_encoding( $value, 'UTF-8' ) ) {
			$regex .= 'u';
		}

		$value = \str_replace( '\\', '\\\\', $value );
		$value = \preg_replace_callback( $regex, [ $this, 'escapeNonGraphicCharacters' ], $value );

		return $value;
	}

	/**
	 * Convert non-graphic bytes in a regex match to hex escape sequences.
	 *
	 * @param array<int, string> $matches Regex match array.
	 */
	protected function escapeNonGraphicCharacters( array $matches ): string {
		$length = \strlen( $matches[0] );
		$escaped = '';
		for ( $index = 0; $index < $length; $index++ ) {
			$hexCode = \dechex( \ord( $matches[0][ $index ] ) );
			$escaped .= '\x' . \strtoupper( \str_pad( $hexCode, 2, '0', \STR_PAD_LEFT ) );
		}
		return $escaped;
	}

	/**
	 * Enable automatic log file rotation.
	 *
	 * @param string|null $rotationPeriod Date format for the log suffix; defaults to FILE_PER_MONTH.
	 * @param int         $filesToKeep    Maximum number of rotated log files to retain.
	 */
	public function enableLogRotation( ?string $rotationPeriod = null, int $filesToKeep = 10 ): void {
		$this->dateSuffix = $rotationPeriod ?? self::FILE_PER_MONTH;
		$this->backupCount = $filesToKeep;
		$this->rotationEnabled = true;
	}

	/**
	 * Delete old rotated log files that exceed the backup count.
	 */
	protected function rotateLogs(): void {
		if ( $this->backupCount === 0 ) {
			return;
		}

		$logFiles = \glob( $this->logDirectory . '/request*.log', \GLOB_NOESCAPE );
		if ( $logFiles === false || \count( $logFiles ) <= $this->backupCount ) {
			return;
		}

		\usort( $logFiles, 'strcmp' );
		$logFiles = \array_reverse( $logFiles );

		foreach ( \array_slice( $logFiles, $this->backupCount ) as $fileName ) {
			if ( \is_file( $fileName ) && ! \unlink( $fileName ) ) {
				\error_log( 'RequestLogger: Failed to delete rotated log file: ' . $fileName );
			}
		}
	}

	/**
	 * Enable IP address anonymization in log entries.
	 */
	public function enableIpAnonymization(): void {
		$this->ipAnonymizationEnabled = true;
	}

	/**
	 * Mask the host portion of an IP address for privacy.
	 *
	 * @param string $ipAddress The IP address to anonymize.
	 */
	protected function anonymizeIp( string $ipAddress ): string {
		$binaryIp = \inet_pton( $ipAddress );
		if ( $binaryIp === false ) {
			return $ipAddress;
		}
		if ( \strlen( $binaryIp ) === 4 ) {
			$anonBinaryIp = $binaryIp & $this->ip4Mask;
		} elseif ( \strlen( $binaryIp ) === 16 ) {
			$anonBinaryIp = $binaryIp & $this->ip6Mask;
		} else {
			return $ipAddress;
		}
		return \inet_ntop( $anonBinaryIp );
	}
}
