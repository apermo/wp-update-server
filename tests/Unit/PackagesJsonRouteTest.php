<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer\Tests\Unit;

use Apermo\WpUpdateServer\UpdateServer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for the /packages.json path detection.
 */
class PackagesJsonRouteTest extends TestCase {

	/**
	 * Call the protected isPackagesJsonRequest() method via reflection.
	 */
	private function callIsPackagesJsonRequest(): bool {
		$server = $this->createPartialMock( UpdateServer::class, [] );
		$method = new ReflectionMethod( UpdateServer::class, 'isPackagesJsonRequest' );

		return $method->invoke( $server );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'requestUriProvider' )]
	public function testDetectsPackagesJsonPath( string $requestUri, bool $expected ): void {
		$original = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = $requestUri;

		try {
			$this->assertSame( $expected, $this->callIsPackagesJsonRequest() );
		} finally {
			if ( $original === null ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $original;
			}
		}
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function requestUriProvider(): array {
		return [
			'bare path'                    => [ '/packages.json', true ],
			'with query string'            => [ '/packages.json?foo=bar', true ],
			'subdirectory path'            => [ '/wp-update-server/packages.json', true ],
			'root index.php'               => [ '/index.php', false ],
			'action query'                 => [ '/?action=get_metadata&slug=test', false ],
			'similar but not exact name'   => [ '/my-packages.json', false ],
			'packages in query not path'   => [ '/?file=packages.json', false ],
			'empty request uri'            => [ '', false ],
		];
	}

	public function testMissingRequestUriReturnsFalse(): void {
		$original = $_SERVER['REQUEST_URI'] ?? null;
		unset( $_SERVER['REQUEST_URI'] );

		try {
			$this->assertFalse( $this->callIsPackagesJsonRequest() );
		} finally {
			if ( $original !== null ) {
				$_SERVER['REQUEST_URI'] = $original;
			}
		}
	}
}
