<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer;

class Request {

	public array $query = [];
	public string $clientIp;
	public string $httpMethod;
	public string $action;
	public string $slug;
	public ?Package $package = null;
	public Headers $headers;

	public ?string $wpVersion = null;
	public ?string $wpSiteUrl = null;

	protected array $props = [];

	public function __construct(array $query, array $headers, string $clientIp = '0.0.0.0', string $httpMethod = 'GET') {
		$this->query = $query;
		$this->headers = new Headers($headers);
		$this->clientIp = $clientIp;
		$this->httpMethod = strtoupper($httpMethod);

		$this->action = preg_replace('@[^a-z0-9\-_]@i', '', $this->param('action', ''));
		$this->slug = preg_replace('@[:?/\\\]@i', '', $this->param('slug', ''));

		// If the request was made via the WordPress HTTP API we can usually
		// get WordPress version and site URL from the user agent.
		$userAgent = $this->headers->get('User-Agent', '');
		$defaultRegex = '@WordPress/(?P<version>\d[^;]*?);\s+(?P<url>https?://.+?)(?:\s|;|$)@i';
		$wpComRegex = '@WordPress\.com;\s+(?P<url>https?://.+?)(?:\s|;|$)@i';
		if (preg_match($defaultRegex, $userAgent, $matches)) {
			$this->wpVersion = $matches['version'];
			$this->wpSiteUrl = $matches['url'];
		} elseif (preg_match($wpComRegex, $userAgent, $matches)) {
			$this->wpSiteUrl = $matches['url'];
		}
	}

	/**
	 * Get the value of a query parameter.
	 */
	public function param(string $name, mixed $default = null): mixed {
		if (array_key_exists($name, $this->query)) {
			return $this->query[$name];
		}
		return $default;
	}

	public function __get(string $name): mixed {
		return $this->props[$name] ?? null;
	}

	public function __set(string $name, mixed $value): void {
		$this->props[$name] = $value;
	}

	public function __isset(string $name): bool {
		return isset($this->props[$name]);
	}

	public function __unset(string $name): void {
		unset($this->props[$name]);
	}
}
