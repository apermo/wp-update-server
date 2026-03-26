<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer;

/**
 * Represents an incoming update API request.
 *
 * Parses query parameters, HTTP headers, and WordPress User-Agent information.
 * Supports arbitrary dynamic properties via magic methods for extensibility.
 */
class Request {

	/** @var array<string, mixed> Query parameters from the request. */
	public array $query = [];

	/** @var string Client IP address. */
	public string $clientIp;

	/** @var string HTTP method (GET, POST, etc.). */
	public string $httpMethod;

	/** @var string Sanitized action name from query parameters. */
	public string $action;

	/** @var string Sanitized package slug from query parameters. */
	public string $slug;

	/** @var Package|null The resolved package for this request. */
	public ?Package $package = null;

	/** @var Headers Parsed HTTP headers. */
	public Headers $headers;

	/** @var string|null WordPress version extracted from the User-Agent header. */
	public ?string $wpVersion = null;

	/** @var string|null WordPress site URL extracted from the User-Agent header. */
	public ?string $wpSiteUrl = null;

	/** @var array<string, mixed> Dynamic properties for extensibility. */
	protected array $props = [];

	/**
	 * Create a new request from query parameters and headers.
	 *
	 * @param array<string, mixed>  $query      Query parameters (typically $_GET).
	 * @param array<string, string> $headers    HTTP headers.
	 * @param string                $clientIp   Client IP address.
	 * @param string                $httpMethod HTTP method.
	 */
	public function __construct( array $query, array $headers, string $clientIp = '0.0.0.0', string $httpMethod = 'GET' ) {
		$this->query = $query;
		$this->headers = new Headers( $headers );
		$this->clientIp = $clientIp;
		$this->httpMethod = \strtoupper( $httpMethod );

		$this->action = \preg_replace( '@[^a-z0-9\-_]@i', '', $this->param( 'action', '' ) );
		$this->slug = \preg_replace( '@[:?/\\\]@i', '', $this->param( 'slug', '' ) );

		// If the request was made via the WordPress HTTP API we can usually
		// get WordPress version and site URL from the user agent.
		$userAgent = $this->headers->get( 'User-Agent', '' );
		$defaultRegex = '@WordPress/(?P<version>\d[^;]*?);\s+(?P<url>https?://.+?)(?:\s|;|$)@i';
		$wpComRegex = '@WordPress\.com;\s+(?P<url>https?://.+?)(?:\s|;|$)@i';
		if ( \preg_match( $defaultRegex, $userAgent, $matches ) ) {
			$this->wpVersion = $matches['version'];
			$this->wpSiteUrl = $matches['url'];
		} elseif ( \preg_match( $wpComRegex, $userAgent, $matches ) ) {
			$this->wpSiteUrl = $matches['url'];
		}
	}

	/**
	 * Get the value of a query parameter.
	 *
	 * @param string $name    Parameter name.
	 * @param mixed  $default Value returned when the parameter is not set.
	 */
	public function param( string $name, mixed $default = null ): mixed {
		if ( \array_key_exists( $name, $this->query ) ) {
			return $this->query[ $name ];
		}
		return $default;
	}

	/**
	 * Get a dynamic property value.
	 *
	 * @param string $name Property name.
	 */
	public function __get( string $name ): mixed {
		return $this->props[ $name ] ?? null;
	}

	/**
	 * Set a dynamic property value.
	 *
	 * @param string $name  Property name.
	 * @param mixed  $value Property value.
	 */
	public function __set( string $name, mixed $value ): void {
		$this->props[ $name ] = $value;
	}

	/**
	 * Check if a dynamic property is set.
	 *
	 * @param string $name Property name.
	 */
	public function __isset( string $name ): bool {
		return isset( $this->props[ $name ] );
	}

	/**
	 * Unset a dynamic property.
	 *
	 * @param string $name Property name.
	 */
	public function __unset( string $name ): void {
		unset( $this->props[ $name ] );
	}
}
