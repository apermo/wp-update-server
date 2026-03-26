<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer;

use Apermo\WpUpdateServer\Auth\FileLicenseProvider;
use Apermo\WpUpdateServer\Auth\LicenseProvider;
use Apermo\WpUpdateServer\Cache\CacheInterface;
use Apermo\WpUpdateServer\Cache\FileCache;
use Apermo\WpUpdateServer\Exception\InvalidPackageException;

class UpdateServer {

	public const FILE_PER_DAY = 'Y-m-d';
	public const FILE_PER_MONTH = 'Y-m';

	protected string $serverDirectory;
	protected string $packageDirectory;
	protected string $bannerDirectory;
	protected array $assetDirectories = [];

	protected string $logDirectory;
	protected bool $logRotationEnabled = false;
	protected ?string $logDateSuffix = null;
	protected int $logBackupCount = 0;

	protected CacheInterface $cache;
	protected Config $config;
	protected PackageRepository $packageRepository;
	protected string $serverUrl;
	protected float $startTime = 0;

	/** @var callable */
	protected $packageFileLoader = [Package::class, 'fromArchive'];

	protected bool $ipAnonymizationEnabled = false;
	protected string $ip4Mask = '';
	protected string $ip6Mask = '';

	private const VALID_CHANNELS = ['stable', 'rc', 'beta', 'alpha'];
	private const SLUG_OPTIONAL_ACTIONS = ['composer_packages', 'upload'];

	public function __construct(?string $serverUrl = null, ?string $serverDirectory = null) {
		if ($serverDirectory === null) {
			$serverDirectory = realpath(__DIR__ . '/..');
		}
		$this->serverDirectory = $this->normalizeFilePath($serverDirectory);
		if ($serverUrl === null) {
			$serverUrl = self::guessServerUrl();
		}

		$this->serverUrl = $serverUrl;
		$this->packageDirectory = $serverDirectory . '/packages';
		$this->logDirectory = $serverDirectory . '/logs';

		$this->bannerDirectory = $serverDirectory . '/package-assets/banners';
		$this->assetDirectories = [
			'banners' => $this->bannerDirectory,
			'icons'   => $serverDirectory . '/package-assets/icons',
		];

		$this->ip4Mask = pack('H*', 'ffffff00');
		$this->ip6Mask = pack('H*', 'ffffffffffff00000000000000000000');

		$this->cache = new FileCache($serverDirectory . '/cache');
		$this->config = Config::fromFile($this->serverDirectory . '/config.php');
		$this->packageRepository = new PackageRepository(
			$this->packageDirectory,
			$this->cache,
			(bool) $this->config->get('legacy_flat_packages', false),
			$this->packageFileLoader,
		);
		$this->applyConfig();
	}

	protected function applyConfig(): void {
		if ($this->config->get('logging.anonymize_ip', false)) {
			$this->enableIpAnonymization();
		}
		if ($this->config->get('logging.rotation.enabled', false)) {
			$this->enableLogRotation(
				$this->config->get('logging.rotation.period', self::FILE_PER_MONTH),
				(int) $this->config->get('logging.rotation.keep', 10)
			);
		}
	}

	public function getConfig(string $key, mixed $default = null): mixed {
		return $this->config->get($key, $default);
	}

	public static function guessServerUrl(): string {
		if (!isset($_SERVER['HTTP_HOST']) || !isset($_SERVER['SCRIPT_NAME'])) {
			return '/';
		}

		$serverUrl = (self::isSsl() ? 'https' : 'http');
		$serverUrl .= '://' . (string) $_SERVER['HTTP_HOST'];
		$path = (string) $_SERVER['SCRIPT_NAME'];

		if (basename($path) === 'index.php') {
			$path = dirname($path);
			if (DIRECTORY_SEPARATOR === '/') {
				$path = str_replace('\\', '/', $path);
			}
			if (!str_ends_with($path, '/')) {
				$path .= '/';
			}
		}

		$serverUrl .= $path;
		return $serverUrl;
	}

	public static function isSsl(): bool {
		if (isset($_SERVER['HTTPS'])) {
			if (($_SERVER['HTTPS'] == '1') || (strtolower($_SERVER['HTTPS']) === 'on')) {
				return true;
			}
		} elseif (isset($_SERVER['SERVER_PORT']) && ('443' == $_SERVER['SERVER_PORT'])) {
			return true;
		}
		return false;
	}

	/**
	 * Process an update API request.
	 *
	 * @param array|null $query Query parameters. Defaults to the current GET request parameters.
	 * @param array|null $headers HTTP headers. Defaults to the headers received for the current request.
	 */
	public function handleRequest(?array $query = null, ?array $headers = null): void {
		$this->startTime = microtime(true);

		$request = $this->initRequest($query, $headers);
		$this->logRequest($request);

		$this->loadPackageFor($request);
		$this->validateRequest($request);
		$this->checkAuthorization($request);
		$this->dispatch($request);
		exit;
	}

	protected function initRequest(?array $query = null, ?array $headers = null): Request {
		if ($query === null) {
			$query = $_GET;
		}
		if ($headers === null) {
			$headers = Headers::parseCurrent();
		}

		$clientIp = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
		$httpMethod = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';

		return new Request($query, $headers, $clientIp, $httpMethod);
	}

	protected function loadPackageFor(Request $request): void {
		if (empty($request->slug)) {
			return;
		}

		$version = $request->param('version');
		$channel = $request->param('channel', 'stable');

		if (!in_array($channel, self::VALID_CHANNELS, true)) {
			$this->exitWithError(
				'Invalid channel. Must be one of: ' . implode(', ', self::VALID_CHANNELS),
				400,
			);
		}

		try {
			$request->package = $this->findPackage($request->slug, $version, $channel);
		} catch (InvalidPackageException $ex) {
			$this->exitWithError(sprintf(
				'Package "%s" exists, but it is not a valid plugin or theme. '
				. 'Make sure it has the right format (Zip) and directory structure.',
				htmlentities($request->slug)
			));
			exit;
		}
	}

	protected function validateRequest(Request $request): void {
		if ($request->action === '') {
			$this->exitWithError('You must specify an action.', 400);
		}
		if (in_array($request->action, self::SLUG_OPTIONAL_ACTIONS, true)) {
			return;
		}
		if ($request->slug === '') {
			$this->exitWithError('You must specify a package slug.', 400);
		}
		if ($request->package === null) {
			$this->exitWithError('Package not found', 404);
		}
	}

	protected function dispatch(Request $request): void {
		match ($request->action) {
			'get_metadata'      => $this->actionGetMetadata($request),
			'download'          => $this->actionDownload($request),
			'composer_packages' => $this->actionComposerPackages($request),
			'upload'            => $this->actionUpload($request),
			default             => $this->exitWithError(
				sprintf('Invalid action "%s".', htmlentities($request->action)),
				400,
			),
		};
	}

	protected function actionComposerPackages(Request $request): void {
		$endpoint = new ComposerEndpoint(
			$this->packageRepository,
			$this->serverUrl,
			(string) $this->config->get('vendor_prefix', 'wpup'),
		);

		$this->outputAsJson($endpoint->generatePackagesJson());
		exit;
	}

	protected function actionUpload(Request $request): void {
		if ($request->httpMethod !== 'POST') {
			$this->exitWithError('Upload requires POST method.', 405);
		}

		$this->authenticateUploadRequest($request);

		if (empty($_FILES['package'])) {
			$this->exitWithError('No package file uploaded.', 400);
		}

		$maxSize = (int) $this->config->get('upload.max_size', 50 * 1024 * 1024);
		if (isset($_FILES['package']['size']) && $_FILES['package']['size'] > $maxSize) {
			$this->exitWithError('File exceeds maximum upload size.', 413);
		}

		$handler = new UploadHandler($this->packageDirectory, $this->cache);
		$force = $request->param('force') === '1';

		try {
			if ($force) {
				$result = $handler->handleForceUpload($_FILES['package'], $request->param('slug'));
			} else {
				$result = $handler->handleUpload($_FILES['package'], $request->param('slug'));
			}

			$this->outputAsJson([
				'success'  => true,
				'slug'     => $result['slug'],
				'version'  => $result['version'],
				'metadata' => $result['metadata'],
			]);
		} catch (\RuntimeException $ex) {
			$statusCode = str_contains($ex->getMessage(), 'already exists') ? 409 : 400;
			$this->exitWithError(htmlentities($ex->getMessage()), $statusCode);
		}
		exit;
	}

	protected function authenticateUploadRequest(Request $request): void {
		$apiKeys = $this->config->get('upload.api_keys', []);
		if (empty($apiKeys)) {
			$this->exitWithError('Upload API is not configured.', 403);
		}

		$key = $this->extractBearerToken($request);
		if ($key === null) {
			$this->exitWithError('Missing API key. Use Authorization: Bearer header.', 401);
		}

		if (!isset($apiKeys[$key])) {
			$this->exitWithError('Invalid API key.', 403);
		}

		$slug = $request->param('slug');
		$keyConfig = $apiKeys[$key];
		$allowedSlugs = $keyConfig['allowed_slugs'] ?? ['*'];

		if ($slug !== null
			&& !in_array('*', $allowedSlugs, true)
			&& !in_array($slug, $allowedSlugs, true)
		) {
			$this->exitWithError('API key is not authorized for this package.', 403);
		}
	}

	protected function extractBearerToken(Request $request): ?string {
		$authHeader = $request->headers->get('Authorization', '');
		if (stripos($authHeader, 'Bearer ') === 0) {
			return trim(substr($authHeader, 7));
		}
		return null;
	}

	protected function actionGetMetadata(Request $request): void {
		$meta = $request->package->getMetadata();
		$meta['download_url'] = $this->generateDownloadUrl($request->package);
		$meta['banners'] = $this->getBanners($request->package);
		$meta['icons'] = $this->getIcons($request->package);

		$meta = $this->filterMetadata($meta, $request);

		$meta['request_time_elapsed'] = sprintf('%.3f', microtime(true) - $this->startTime);

		$this->outputAsJson($meta);
		exit;
	}

	/**
	 * Filter plugin metadata before output.
	 *
	 * Override this method to customize update API responses.
	 */
	protected function filterMetadata(array $meta, Request $request): array {
		return array_filter($meta, fn($value) => $value !== null);
	}

	protected function actionDownload(Request $request): void {
		$package = $request->package;
		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename="' . $package->slug . '.zip"');
		header('Content-Transfer-Encoding: binary');
		header('Content-Length: ' . $package->getFileSize());

		readfile($package->getFilename());
	}

	protected function findPackage(
		string $slug,
		?string $version = null,
		string $channel = 'stable',
	): ?Package {
		return $this->packageRepository->findPackage($slug, $version, $channel);
	}

	/**
	 * Validate license key authorization for the current request.
	 */
	protected function checkAuthorization(Request $request): void {
		if (!$this->config->get('auth.require_license', false)) {
			return;
		}
		if (empty($request->slug)) {
			return;
		}

		$publicPackages = $this->config->get('auth.public_packages', []);
		if (in_array($request->slug, $publicPackages, true)) {
			return;
		}

		$provider = $this->getLicenseProvider();
		if ($provider === null) {
			return;
		}

		$key = $this->extractLicenseKey($request);
		if ($key === null) {
			$this->exitWithError('A license key is required.', 401);
		}
		if (!$provider->validate($key, $request->slug)) {
			$this->exitWithError('Invalid or expired license key.', 403);
		}
	}

	protected function extractLicenseKey(Request $request): ?string {
		$key = $request->param('license_key');
		if ($key !== null) {
			return $key;
		}

		$authHeader = $request->headers->get('Authorization', '');
		if (stripos($authHeader, 'Bearer ') === 0) {
			return trim(substr($authHeader, 7));
		}

		return null;
	}

	protected function getLicenseProvider(): ?LicenseProvider {
		$licensesFile = $this->config->get('auth.licenses_file', 'licenses.json');

		if (!str_starts_with($licensesFile, '/')) {
			$licensesFile = $this->serverDirectory . '/' . $licensesFile;
		}

		if (!is_file($licensesFile)) {
			return null;
		}

		return new FileLicenseProvider($licensesFile);
	}

	protected function generateDownloadUrl(Package $package): string {
		$query = [
			'action' => 'download',
			'slug'   => $package->slug,
		];
		$version = $package->getVersion();
		if ($version !== null) {
			$query['version'] = $version;
		}
		return self::addQueryArg($query, $this->serverUrl);
	}

	protected function getBanners(Package $package): ?array {
		$smallBanner = $this->findFirstAsset($package, 'banners', '-772x250');
		if (!empty($smallBanner)) {
			$banners = ['low' => $smallBanner];

			$bigBanner = $this->findFirstAsset($package, 'banners', '-1544x500');
			if (!empty($bigBanner)) {
				$banners['high'] = $bigBanner;
			}

			return $banners;
		}

		return null;
	}

	/**
	 * @deprecated Use generateAssetUrl() instead.
	 */
	protected function generateBannerUrl(string $relativeFileName): string {
		return $this->generateAssetUrl('banners', $relativeFileName);
	}

	protected function getIcons(Package $package): ?array {
		$icons = [
			'1x'  => $this->findFirstAsset($package, 'icons', '-128x128'),
			'2x'  => $this->findFirstAsset($package, 'icons', '-256x256'),
			'svg' => $this->findFirstAsset($package, 'icons', '', 'svg'),
		];

		$icons = array_filter($icons);
		return !empty($icons) ? $icons : null;
	}

	protected function findFirstAsset(
		Package $package,
		string $assetType = 'banners',
		string $suffix = '',
		array|string $extensions = ['png', 'jpg', 'jpeg'],
	): ?string {
		$pattern = $this->assetDirectories[$assetType] . '/' . $package->slug . $suffix;

		if (is_array($extensions)) {
			$extensionPattern = '{' . implode(',', $extensions) . '}';
		} else {
			$extensionPattern = $extensions;
		}

		$assets = glob($pattern . '.' . $extensionPattern, GLOB_BRACE | GLOB_NOESCAPE);
		if (!empty($assets)) {
			$firstFile = basename(reset($assets));
			return $this->generateAssetUrl($assetType, $firstFile);
		}
		return null;
	}

	protected function generateAssetUrl(string $assetType, string $relativeFileName): string {
		$directory = $this->normalizeFilePath($this->assetDirectories[$assetType]);
		if (str_starts_with($directory, $this->serverDirectory)) {
			$subDirectory = substr($directory, strlen($this->serverDirectory) + 1);
		} else {
			$subDirectory = basename($directory);
		}
		$subDirectory = trim($subDirectory, '/\\');
		return $this->serverUrl . $subDirectory . '/' . $relativeFileName;
	}

	protected function normalizeFilePath(string $path): string {
		return str_replace([DIRECTORY_SEPARATOR, '\\'], '/', $path);
	}

	protected function logRequest(Request $request): void {
		$logFile = $this->getLogFileName();

		$mustRotate = $this->logRotationEnabled && !file_exists($logFile);

		$handle = fopen($logFile, 'a');
		if ($handle && flock($handle, LOCK_EX)) {
			$loggedIp = $request->clientIp;
			if ($this->ipAnonymizationEnabled) {
				$loggedIp = $this->anonymizeIp($loggedIp);
			}

			$columns = [
				'ip'                => $loggedIp,
				'http_method'       => $request->httpMethod,
				'action'            => $request->param('action', '-'),
				'slug'              => $request->param('slug', '-'),
				'installed_version' => $request->param('installed_version', '-'),
				'wp_version'        => $request->wpVersion ?? '-',
				'site_url'          => $request->wpSiteUrl ?? '-',
				'query'             => http_build_query($request->query, '', '&'),
			];
			$columns = $this->filterLogInfo($columns, $request);
			$columns = $this->escapeLogInfo($columns);

			if (isset($columns['ip'])) {
				$columns['ip'] = str_pad($columns['ip'], 15, ' ');
			}
			if (isset($columns['http_method'])) {
				$columns['http_method'] = str_pad($columns['http_method'], 4, ' ');
			}

			$configuredTz = ini_get('date.timezone');
			if (empty($configuredTz)) {
				date_default_timezone_set(@date_default_timezone_get());
			}

			$line = date('[Y-m-d H:i:s O]') . ' ' . implode("\t", $columns) . "\n";

			fwrite($handle, $line);

			if ($mustRotate) {
				$this->rotateLogs();
			}
			flock($handle, LOCK_UN);
		}
		if ($handle) {
			fclose($handle);
		}
	}

	protected function getLogFileName(): string {
		$path = $this->logDirectory . '/request';
		if ($this->logRotationEnabled) {
			$path .= '-' . date($this->logDateSuffix);
		}
		return $path . '.log';
	}

	/**
	 * Adjust information that will be logged. Override in subclasses.
	 */
	protected function filterLogInfo(array $columns, ?Request $request = null): array {
		return $columns;
	}

	/**
	 * @param string[] $columns
	 * @return string[] Escaped columns.
	 */
	protected function escapeLogInfo(array $columns): array {
		return array_map([$this, 'escapeLogValue'], $columns);
	}

	protected function escapeLogValue(?string $value): ?string {
		if ($value === null) {
			return null;
		}

		$regex = '/[[:^graph:]]/';

		if (function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8')) {
			$regex .= 'u';
		}

		$value = str_replace('\\', '\\\\', $value);
		$value = preg_replace_callback(
			$regex,
			function (array $matches): string {
				$length = strlen($matches[0]);
				$escaped = '';
				for ($i = 0; $i < $length; $i++) {
					$hexCode = dechex(ord($matches[0][$i]));
					$escaped .= '\x' . strtoupper(str_pad($hexCode, 2, '0', STR_PAD_LEFT));
				}
				return $escaped;
			},
			$value
		);

		return $value;
	}

	public function enableLogRotation(?string $rotationPeriod = null, int $filesToKeep = 10): void {
		if ($rotationPeriod === null) {
			$rotationPeriod = self::FILE_PER_MONTH;
		}

		$this->logDateSuffix = $rotationPeriod;
		$this->logBackupCount = $filesToKeep;
		$this->logRotationEnabled = true;
	}

	protected function rotateLogs(): void {
		if ($this->logBackupCount === 0) {
			return;
		}

		$logFiles = glob($this->logDirectory . '/request*.log', GLOB_NOESCAPE);
		if (count($logFiles) <= $this->logBackupCount) {
			return;
		}

		usort($logFiles, 'strcmp');
		$logFiles = array_reverse($logFiles);

		foreach (array_slice($logFiles, $this->logBackupCount) as $fileName) {
			@unlink($fileName);
		}
	}

	public function enableIpAnonymization(): void {
		$this->ipAnonymizationEnabled = true;
	}

	protected function anonymizeIp(string $ip): string {
		$binaryIp = @inet_pton($ip);
		if (strlen($binaryIp) === 4) {
			$anonBinaryIp = $binaryIp & $this->ip4Mask;
		} elseif (strlen($binaryIp) === 16) {
			$anonBinaryIp = $binaryIp & $this->ip6Mask;
		} else {
			return $ip;
		}
		return inet_ntop($anonBinaryIp);
	}

	protected function outputAsJson(mixed $response): void {
		header('Content-Type: application/json; charset=utf-8');
		$output = json_encode($response, JSON_PRETTY_PRINT);
		echo $output;
	}

	protected function exitWithError(string $message = '', int $httpStatus = 500): never {
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

		$protocol = isset($_SERVER['SERVER_PROTOCOL']) && $_SERVER['SERVER_PROTOCOL'] !== ''
			? (string) $_SERVER['SERVER_PROTOCOL']
			: 'HTTP/1.1';

		if (isset($statusMessages[$httpStatus])) {
			header($protocol . ' ' . $statusMessages[$httpStatus]);
			$title = $statusMessages[$httpStatus];
		} else {
			header('X-Ws-Update-Server-Error: ' . $httpStatus, true, $httpStatus);
			$title = 'HTTP ' . $httpStatus;
		}

		if ($message === '') {
			$message = $title;
		}

		printf(
			'<html><head><title>%1$s</title></head><body><h1>%1$s</h1><p>%2$s</p></body></html>',
			htmlentities($title),
			$message
		);
		exit;
	}

	protected static function addQueryArg(array $args, ?string $url = null): string {
		if ($url === null) {
			$url = self::guessServerUrl();
		}
		if (str_contains($url, '?')) {
			$parts = explode('?', $url, 2);
			$base = $parts[0] . '?';
			parse_str($parts[1], $query);
		} else {
			$base = $url . '?';
			$query = [];
		}

		$query = array_merge($query, $args);

		$query = array_filter($query, fn($value) => ($value !== null) && ($value !== false));

		return $base . http_build_query($query, '', '&');
	}
}
