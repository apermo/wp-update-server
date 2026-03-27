<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer;

use Apermo\WpUpdateServer\Auth\FileLicenseProvider;
use Apermo\WpUpdateServer\Auth\LicenseProvider;
use Apermo\WpUpdateServer\Cache\CacheInterface;
use Apermo\WpUpdateServer\Cache\FileCache;
use Apermo\WpUpdateServer\Exception\InvalidPackageException;
use Apermo\WpUpdateServer\Logging\RequestLogger;
use RuntimeException;

/**
 * Custom update API server for WordPress plugins and themes.
 *
 * Handles metadata requests, downloads, Composer integration, and package uploads.
 * Designed for extensibility via subclassing.
 */
class UpdateServer {

	/**
	 * Allowed release channel identifiers.
	 *
	 * @var string[]
	 */
	private const VALID_CHANNELS = [ 'stable', 'rc', 'beta', 'alpha' ];

	/**
	 * Actions that do not require a package slug.
	 *
	 * @var string[]
	 */
	private const SLUG_OPTIONAL_ACTIONS = [ 'composer_packages', 'upload' ];

	/**
	 * Absolute path to the server root directory.
	 *
	 * @var string
	 */
	protected string $serverDirectory;

	/**
	 * Absolute path to the directory containing package ZIP files.
	 *
	 * @var string
	 */
	protected string $packageDirectory;

	/**
	 * Absolute path to the banner images directory.
	 *
	 * @var string
	 */
	protected string $bannerDirectory;

	/**
	 * Map of asset type names to their directory paths.
	 *
	 * @var array<string, string>
	 */
	protected array $assetDirectories = [];

	/**
	 * Cache backend for package metadata.
	 *
	 * @var CacheInterface
	 */
	protected CacheInterface $cache;

	/**
	 * Server configuration instance.
	 *
	 * @var Config
	 */
	protected Config $config;

	/**
	 * Repository for discovering and loading packages.
	 *
	 * @var PackageRepository
	 */
	protected PackageRepository $packageRepository;

	/**
	 * Request logger instance.
	 *
	 * @var RequestLogger
	 */
	protected RequestLogger $logger;

	/**
	 * Public base URL of the update server.
	 *
	 * @var string
	 */
	protected string $serverUrl;

	/**
	 * Microtime timestamp when request processing started.
	 *
	 * @var float
	 */
	protected float $startTime = 0;

	/**
	 * Factory callable used to load Package instances from ZIP archives.
	 *
	 * @var callable
	 */
	protected mixed $packageFileLoader = [ Package::class, 'fromArchive' ];

	/**
	 * Create a new instance.
	 *
	 * @param string|null $serverUrl      Public base URL; auto-detected when null.
	 * @param string|null $serverDirectory Absolute path to the server root; defaults to parent of src/.
	 */
	public function __construct( ?string $serverUrl = null, ?string $serverDirectory = null ) {
		if ( $serverDirectory === null ) {
			$serverDirectory = \realpath( __DIR__ . '/..' );
		}
		$this->serverDirectory = $this->normalizeFilePath( $serverDirectory );

		if ( $serverUrl === null ) {
			$serverUrl = self::guessServerUrl();
		}

		$this->serverUrl = $serverUrl;
		$this->packageDirectory = $serverDirectory . '/packages';

		$this->bannerDirectory = $serverDirectory . '/package-assets/banners';
		$this->assetDirectories = [
			'banners' => $this->bannerDirectory,
			'icons'   => $serverDirectory . '/package-assets/icons',
		];

		$this->cache = new FileCache( $serverDirectory . '/cache' );
		$this->logger = new RequestLogger( $serverDirectory . '/logs' );
		$this->config = Config::fromFile( $this->serverDirectory . '/config.php' );
		$this->packageRepository = new PackageRepository(
			$this->packageDirectory,
			$this->cache,
			(bool) $this->config->get( 'legacy_flat_packages', false ),
			$this->packageFileLoader,
		);
		$this->applyConfig();
	}

	/**
	 * Derive the server's public URL from $_SERVER superglobals.
	 */
	public static function guessServerUrl(): string {
		if ( ! isset( $_SERVER['HTTP_HOST'] ) || ! isset( $_SERVER['SCRIPT_NAME'] ) ) {
			return '/';
		}

		$serverUrl = ( self::isSsl() ? 'https' : 'http' );
		$serverUrl .= '://' . (string) $_SERVER['HTTP_HOST'];
		$path = (string) $_SERVER['SCRIPT_NAME'];

		if ( \basename( $path ) === 'index.php' ) {
			$path = \dirname( $path );
			if ( \DIRECTORY_SEPARATOR === '/' ) {
				$path = \str_replace( '\\', '/', $path );
			}

			if ( ! \str_ends_with( $path, '/' ) ) {
				$path .= '/';
			}
		}

		$serverUrl .= $path;
		return $serverUrl;
	}

	/**
	 * Detect whether the current request was made over HTTPS.
	 */
	public static function isSsl(): bool {
		if ( isset( $_SERVER['HTTPS'] ) ) {
			if ( ( $_SERVER['HTTPS'] === '1' ) || ( \strtolower( $_SERVER['HTTPS'] ) === 'on' ) ) {
				return true;
			}
		} elseif ( isset( $_SERVER['SERVER_PORT'] ) && (string) $_SERVER['SERVER_PORT'] === '443' ) {
			return true;
		}

		return false;
	}

	/**
	 * Append query arguments to a URL, merging with any existing parameters.
	 *
	 * @param array<string, string|null> $args Key-value pairs to add to the query string.
	 * @param string|null                $url  Base URL; defaults to the guessed server URL.
	 */
	protected static function addQueryArg( array $args, ?string $url = null ): string {
		if ( $url === null ) {
			$url = self::guessServerUrl();
		}
		if ( \str_contains( $url, '?' ) ) {
			$parts = \explode( '?', $url, 2 );
			$base = $parts[0] . '?';
			\parse_str( $parts[1], $query );
		} else {
			$base = $url . '?';
			$query = [];
		}

		$query = \array_merge( $query, $args );
		$query = \array_filter( $query, static fn( $value ) => ( $value !== null ) && ( $value !== false ) );

		return $base . \http_build_query( $query, '', '&' );
	}

	/**
	 * Apply settings from the Config instance to the server.
	 */
	protected function applyConfig(): void {
		if ( $this->config->get( 'logging.anonymize_ip', false ) ) {
			$this->logger->enableIpAnonymization();
		}

		if ( $this->config->get( 'logging.rotation.enabled', false ) ) {
			$this->logger->enableLogRotation(
				$this->config->get( 'logging.rotation.period', RequestLogger::FILE_PER_MONTH ),
				(int) $this->config->get( 'logging.rotation.keep', 10 ),
			);
		}
	}

	/**
	 * Retrieve a configuration value by dot-notation key.
	 *
	 * @param string $key     Dot-separated config key.
	 * @param mixed  $default Value returned when the key does not exist.
	 */
	public function getConfig( string $key, mixed $default = null ): mixed {
		return $this->config->get( $key, $default );
	}

	/**
	 * Process an update API request.
	 *
	 * @param array<string, mixed>|null  $query   Query parameters. Defaults to the current GET request parameters.
	 * @param array<string, string>|null $headers HTTP headers. Defaults to the headers received for the current request.
	 */
	public function handleRequest( ?array $query = null, ?array $headers = null ): void {
		$this->startTime = \microtime( true );

		// Composer requests /packages.json on the repository URL.
		if ( $this->isPackagesJsonRequest() ) {
			$request = $this->initRequest( [ 'action' => 'composer_packages' ], $headers );
			$this->logger->log( $request );
			$this->actionComposerPackages( $request );
			exit();
		}

		$request = $this->initRequest( $query, $headers );
		$this->logger->log( $request );

		$this->loadPackageFor( $request );
		$this->validateRequest( $request );
		$this->checkAuthorization( $request );
		$this->dispatch( $request );
		exit();
	}

	/**
	 * Check if the current request is for /packages.json (Composer repository discovery).
	 */
	protected function isPackagesJsonRequest(): bool {
		$requestUri = $_SERVER['REQUEST_URI'] ?? '';
		$path = \parse_url( $requestUri, \PHP_URL_PATH );
		return $path !== null && \basename( $path ) === 'packages.json';
	}

	/**
	 * Build a Request object from the current HTTP environment.
	 *
	 * @param array<string, mixed>|null  $query   Query parameters; defaults to $_GET.
	 * @param array<string, string>|null $headers HTTP headers; defaults to the current request headers.
	 */
	protected function initRequest( ?array $query = null, ?array $headers = null ): Request {
		if ( $query === null ) {
			$query = $_GET;
		}

		if ( $headers === null ) {
			$headers = Headers::parseCurrent();
		}

		$clientIp = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
		$httpMethod = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';

		return new Request( $query, $headers, $clientIp, $httpMethod );
	}

	/**
	 * Resolve and attach the requested Package to the Request.
	 *
	 * @param Request $request The current API request.
	 */
	protected function loadPackageFor( Request $request ): void {
		if ( empty( $request->slug ) ) {
			return;
		}

		$version = $request->param( 'version' );
		$channel = $request->param( 'channel', 'stable' );

		if ( ! \in_array( $channel, self::VALID_CHANNELS, true ) ) {
			$this->exitWithError(
				'Invalid channel. Must be one of: ' . \implode( ', ', self::VALID_CHANNELS ),
				400,
			);
		}

		try {
			$request->package = $this->findPackage( $request->slug, $version, $channel );
		} catch ( InvalidPackageException $exception ) {
			$this->exitWithError(
				\sprintf(
					'Package "%s" exists, but it is not a valid plugin or theme. '
					. 'Make sure it has the right format (Zip) and directory structure.',
					\htmlentities( $request->slug ),
				),
			);
			exit();
		}
	}

	/**
	 * Ensure the request contains all required parameters.
	 *
	 * @param Request $request The current API request.
	 */
	protected function validateRequest( Request $request ): void {
		if ( $request->action === '' ) {
			$this->exitWithError( 'You must specify an action.', 400 );
		}

		if ( \in_array( $request->action, self::SLUG_OPTIONAL_ACTIONS, true ) ) {
			return;
		}

		if ( $request->slug === '' ) {
			$this->exitWithError( 'You must specify a package slug.', 400 );
		}

		if ( $request->package === null ) {
			$this->exitWithError( 'Package not found', 404 );
		}
	}

	/**
	 * Route the request to the appropriate action handler.
	 *
	 * @param Request $request The current API request.
	 */
	protected function dispatch( Request $request ): void {
		match ( $request->action ) {
			'get_metadata'      => $this->actionGetMetadata( $request ),
			'download'          => $this->actionDownload( $request ),
			'composer_packages' => $this->actionComposerPackages( $request ),
			'upload'            => $this->actionUpload( $request ),
			default             => $this->exitWithError(
				\sprintf( 'Invalid action "%s".', \htmlentities( $request->action ) ),
				400,
			),
		};
	}

	/**
	 * Output a Composer packages.json response for all available packages.
	 *
	 * @param Request $request The current API request.
	 */
	protected function actionComposerPackages( Request $request ): void {
		$endpoint = new ComposerEndpoint(
			$this->packageRepository,
			$this->serverUrl,
			(string) $this->config->get( 'vendor_prefix', 'wpup' ),
		);

		$this->outputAsJson( $endpoint->generatePackagesJson() );
		exit();
	}

	/**
	 * Handle a package upload via the API.
	 *
	 * @param Request $request The current API request.
	 */
	protected function actionUpload( Request $request ): void {
		if ( $request->httpMethod !== 'POST' ) {
			$this->exitWithError( 'Upload requires POST method.', 405 );
		}

		$this->authenticateUploadRequest( $request );

		if ( empty( $_FILES['package'] ) ) {
			$this->exitWithError( 'No package file uploaded.', 400 );
		}

		$maxSize = (int) $this->config->get( 'upload.max_size', 50 * 1024 * 1024 );
		if ( isset( $_FILES['package']['size'] ) && $_FILES['package']['size'] > $maxSize ) {
			$this->exitWithError( 'File exceeds maximum upload size.', 413 );
		}

		$handler = new UploadHandler( $this->packageDirectory, $this->cache );
		$force = $request->param( 'force' ) === '1';

		try {
			if ( $force ) {
				$result = $handler->handleForceUpload( $_FILES['package'], $request->param( 'slug' ) );
			} else {
				$result = $handler->handleUpload( $_FILES['package'], $request->param( 'slug' ) );
			}

			$this->outputAsJson(
				[
					'success'  => true,
					'slug'     => $result['slug'],
					'version'  => $result['version'],
					'metadata' => $result['metadata'],
				],
			);
		} catch ( RuntimeException $exception ) {
			$statusCode = \str_contains( $exception->getMessage(), 'already exists' ) ? 409 : 400;
			$this->exitWithError( \htmlentities( $exception->getMessage() ), $statusCode );
		}
		exit();
	}

	/**
	 * Verify that the upload request carries a valid API key.
	 *
	 * @param Request $request The current API request.
	 */
	protected function authenticateUploadRequest( Request $request ): void {
		$apiKeys = $this->config->get( 'upload.api_keys', [] );
		if ( empty( $apiKeys ) ) {
			$this->exitWithError( 'Upload API is not configured.', 403 );
		}

		$key = $this->extractBearerToken( $request );
		if ( $key === null ) {
			$this->exitWithError( 'Missing API key. Use Authorization: Bearer header.', 401 );
		}

		if ( ! isset( $apiKeys[ $key ] ) ) {
			$this->exitWithError( 'Invalid API key.', 403 );
		}

		$slug = $request->param( 'slug' );
		$keyConfig = $apiKeys[ $key ];
		$allowedSlugs = $keyConfig['allowed_slugs'] ?? [ '*' ];

		if ( $slug !== null
			&& ! \in_array( '*', $allowedSlugs, true )
			&& ! \in_array( $slug, $allowedSlugs, true )
		) {
			$this->exitWithError( 'API key is not authorized for this package.', 403 );
		}
	}

	/**
	 * Extract a Bearer token from the Authorization header.
	 *
	 * @param Request $request The current API request.
	 */
	protected function extractBearerToken( Request $request ): ?string {
		$authHeader = $request->headers->get( 'Authorization', '' );
		if ( \stripos( $authHeader, 'Bearer ' ) === 0 ) {
			return \trim( \substr( $authHeader, 7 ) );
		}
		return null;
	}

	/**
	 * Output package metadata as a JSON response.
	 *
	 * @param Request $request The current API request.
	 */
	protected function actionGetMetadata( Request $request ): void {
		$meta = $request->package->getMetadata();
		$meta['download_url'] = $this->generateDownloadUrl( $request->package );
		$meta['banners'] = $this->getBanners( $request->package );
		$meta['icons'] = $this->getIcons( $request->package );

		$meta = $this->filterMetadata( $meta, $request );

		$meta['request_time_elapsed'] = \sprintf( '%.3f', \microtime( true ) - $this->startTime );

		$this->outputAsJson( $meta );
		exit();
	}

	/**
	 * Filter plugin metadata before output.
	 *
	 * Override this method to customize update API responses.
	 *
	 * @param array<string, mixed> $meta Package metadata key-value pairs.
	 * @param Request              $request The current API request.
	 * @return array<string, mixed> Filtered metadata.
	 */
	protected function filterMetadata( array $meta, Request $request ): array {
		return \array_filter( $meta, static fn( $value ) => $value !== null );
	}

	/**
	 * Stream the package ZIP file as a download response.
	 *
	 * @param Request $request The current API request.
	 */
	protected function actionDownload( Request $request ): void {
		$package = $request->package;
		\header( 'Content-Type: application/zip' );
		\header( 'Content-Disposition: attachment; filename="' . $package->slug . '.zip"' );
		\header( 'Content-Transfer-Encoding: binary' );
		\header( 'Content-Length: ' . $package->getFileSize() );

		\readfile( $package->getFilename() );
	}

	/**
	 * Look up a package by slug, optional version, and release channel.
	 *
	 * @param string      $slug    Package slug identifier.
	 * @param string|null $version Specific version to retrieve, or null for latest.
	 * @param string      $channel Release channel (stable, rc, beta, alpha).
	 */
	protected function findPackage(
		string $slug,
		?string $version = null,
		string $channel = 'stable',
	): ?Package {
		return $this->packageRepository->findPackage( $slug, $version, $channel );
	}

	/**
	 * Validate license key authorization for the current request.
	 *
	 * @param Request $request The current API request.
	 */
	protected function checkAuthorization( Request $request ): void {
		if ( ! $this->config->get( 'auth.require_license', false ) ) {
			return;
		}
		if ( empty( $request->slug ) ) {
			return;
		}

		$publicPackages = $this->config->get( 'auth.public_packages', [] );
		if ( \in_array( $request->slug, $publicPackages, true ) ) {
			return;
		}

		$provider = $this->getLicenseProvider();
		if ( $provider === null ) {
			return;
		}

		$key = $this->extractLicenseKey( $request );
		if ( $key === null ) {
			$this->exitWithError( 'A license key is required.', 401 );
		}

		if ( ! $provider->validate( $key, $request->slug ) ) {
			$this->exitWithError( 'Invalid or expired license key.', 403 );
		}
	}

	/**
	 * Extract the license key from query params or the Authorization header.
	 *
	 * @param Request $request The current API request.
	 */
	protected function extractLicenseKey( Request $request ): ?string {
		$key = $request->param( 'license_key' );
		if ( $key !== null ) {
			return $key;
		}

		$authHeader = $request->headers->get( 'Authorization', '' );
		if ( \stripos( $authHeader, 'Bearer ' ) === 0 ) {
			return \trim( \substr( $authHeader, 7 ) );
		}

		return null;
	}

	/**
	 * Create a LicenseProvider from the configured licenses file.
	 */
	protected function getLicenseProvider(): ?LicenseProvider {
		$licensesFile = $this->config->get( 'auth.licenses_file', 'licenses.json' );

		if ( ! \str_starts_with( $licensesFile, '/' ) ) {
			$licensesFile = $this->serverDirectory . '/' . $licensesFile;
		}

		if ( ! \is_file( $licensesFile ) ) {
			return null;
		}

		return new FileLicenseProvider( $licensesFile );
	}

	/**
	 * Build the public download URL for a package.
	 *
	 * @param Package $package The package to generate a URL for.
	 */
	protected function generateDownloadUrl( Package $package ): string {
		$query = [
			'action' => 'download',
			'slug'   => $package->slug,
		];
		$version = $package->getVersion();
		if ( $version !== null ) {
			$query['version'] = $version;
		}

		return self::addQueryArg( $query, $this->serverUrl );
	}

	/**
	 * Locate banner image URLs (low and high resolution) for a package.
	 *
	 * @param Package $package The package to find banners for.
	 * @return array<string, string>|null Banner URLs keyed by 'low'/'high', or null.
	 */
	protected function getBanners( Package $package ): ?array {
		$smallBanner = $this->findFirstAsset( $package, 'banners', '-772x250' );
		if ( ! empty( $smallBanner ) ) {
			$banners = [ 'low' => $smallBanner ];

			$bigBanner = $this->findFirstAsset( $package, 'banners', '-1544x500' );
			if ( ! empty( $bigBanner ) ) {
				$banners['high'] = $bigBanner;
			}

			return $banners;
		}

		return null;
	}

	/**
	 * Build the public URL for a banner image file.
	 *
	 * @deprecated Use generateAssetUrl() instead.
	 *
	 * @param string $relativeFileName Banner filename relative to the banners directory.
	 */
	protected function generateBannerUrl( string $relativeFileName ): string {
		return $this->generateAssetUrl( 'banners', $relativeFileName );
	}

	/**
	 * Locate icon image URLs (1x, 2x, SVG) for a package.
	 *
	 * @param Package $package The package to find icons for.
	 * @return array<string, string>|null Icon URLs keyed by '1x'/'2x'/'svg', or null.
	 */
	protected function getIcons( Package $package ): ?array {
		$icons = [
			'1x'  => $this->findFirstAsset( $package, 'icons', '-128x128' ),
			'2x'  => $this->findFirstAsset( $package, 'icons', '-256x256' ),
			'svg' => $this->findFirstAsset( $package, 'icons', '', 'svg' ),
		];

		$icons = \array_filter( $icons );
		return ! empty( $icons ) ? $icons : null;
	}

	/**
	 * Find the first matching asset file for a package and return its public URL.
	 *
	 * @param Package         $package    The package to find an asset for.
	 * @param string          $assetType  Asset category key (e.g. 'banners', 'icons').
	 * @param string          $suffix     Filename suffix before the extension (e.g. '-772x250').
	 * @param string[]|string $extensions File extension(s) to search for.
	 */
	protected function findFirstAsset(
		Package $package,
		string $assetType = 'banners',
		string $suffix = '',
		array|string $extensions = [ 'png', 'jpg', 'jpeg' ],
	): ?string {
		$pattern = $this->assetDirectories[ $assetType ] . '/' . $package->slug . $suffix;

		if ( \is_array( $extensions ) ) {
			$extensionPattern = '{' . \implode( ',', $extensions ) . '}';
		} else {
			$extensionPattern = $extensions;
		}

		$assets = \glob( $pattern . '.' . $extensionPattern, \GLOB_BRACE | \GLOB_NOESCAPE );
		if ( ! empty( $assets ) ) {
			$firstFile = \basename( \reset( $assets ) );
			return $this->generateAssetUrl( $assetType, $firstFile );
		}
		return null;
	}

	/**
	 * Build the public URL for an asset file.
	 *
	 * @param string $assetType        Asset category key (e.g. 'banners', 'icons').
	 * @param string $relativeFileName Filename relative to the asset directory.
	 */
	protected function generateAssetUrl( string $assetType, string $relativeFileName ): string {
		$directory = $this->normalizeFilePath( $this->assetDirectories[ $assetType ] );
		if ( \str_starts_with( $directory, $this->serverDirectory ) ) {
			$subDirectory = \substr( $directory, \strlen( $this->serverDirectory ) + 1 );
		} else {
			$subDirectory = \basename( $directory );
		}

		$subDirectory = \trim( $subDirectory, '/\\' );
		return $this->serverUrl . $subDirectory . '/' . $relativeFileName;
	}

	/**
	 * Normalize directory separators to forward slashes.
	 *
	 * @param string $path Filesystem path to normalize.
	 */
	protected function normalizeFilePath( string $path ): string {
		return \str_replace( [ \DIRECTORY_SEPARATOR, '\\' ], '/', $path );
	}

	/**
	 * Send a JSON response with the appropriate Content-Type header.
	 *
	 * @param mixed $response Data to encode as JSON.
	 */
	protected function outputAsJson( mixed $response ): void {
		\header( 'Content-Type: application/json; charset=utf-8' );
		$output = \json_encode( $response, \JSON_PRETTY_PRINT );
		echo $output;
	}

	/**
	 * Send an HTML error response and terminate execution.
	 *
	 * @param string $message    Human-readable error message.
	 * @param int    $httpStatus HTTP status code to send.
	 */
	protected function exitWithError( string $message = '', int $httpStatus = 500 ): never {
		$statusMessages = [
			400 => '400 Bad Request',
			401 => '401 Unauthorized',
			402 => '402 Payment Required',
			403 => '403 Forbidden',
			404 => '404 Not Found',
			405 => '405 Method Not Allowed',
			406 => '406 Not Acceptable',
			407 => '407 Proxy Authentication Required',
			408 => '408 Request Timeout',
			409 => '409 Conflict',
			410 => '410 Gone',
			411 => '411 Length Required',
			412 => '412 Precondition Failed',
			413 => '413 Request Entity Too Large',
			414 => '414 Request-URI Too Long',
			415 => '415 Unsupported Media Type',
			416 => '416 Requested Range Not Satisfiable',
			417 => '417 Expectation Failed',
			500 => '500 Internal Server Error',
			501 => '501 Not Implemented',
			502 => '502 Bad Gateway',
			503 => '503 Service Unavailable',
			504 => '504 Gateway Timeout',
			505 => '505 HTTP Version Not Supported',
		];

		$protocol = isset( $_SERVER['SERVER_PROTOCOL'] ) && $_SERVER['SERVER_PROTOCOL'] !== ''
			? (string) $_SERVER['SERVER_PROTOCOL']
			: 'HTTP/1.1';

		if ( isset( $statusMessages[ $httpStatus ] ) ) {
			\header( $protocol . ' ' . $statusMessages[ $httpStatus ] );
			$title = $statusMessages[ $httpStatus ];
		} else {
			\header( 'X-Ws-Update-Server-Error: ' . $httpStatus, true, $httpStatus );
			$title = 'HTTP ' . $httpStatus;
		}

		if ( $message === '' ) {
			$message = $title;
		}

		\printf(
			'<html><head><title>%1$s</title></head><body><h1>%1$s</h1><p>%2$s</p></body></html>',
			\htmlentities( $title ),
			$message,
		);
		exit();
	}
}
