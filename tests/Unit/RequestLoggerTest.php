<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer\Tests\Unit;

use Apermo\WpUpdateServer\Logging\RequestLogger;
use Apermo\WpUpdateServer\Request;
use PHPUnit\Framework\TestCase;

class RequestLoggerTest extends TestCase {

	private string $logDir;

	protected function setUp(): void {
		$this->logDir = \sys_get_temp_dir() . '/wpus-test-logs-' . \uniqid();
		\mkdir( $this->logDir, 0755, true );
	}

	protected function tearDown(): void {
		foreach ( \glob( $this->logDir . '/*' ) as $file ) {
			\unlink( $file );
		}
		\rmdir( $this->logDir );
	}

	public function testLogCreatesFile(): void {
		$logger = new RequestLogger( $this->logDir );
		$request = new Request(
			[ 'action' => 'get_metadata', 'slug' => 'hello-dolly' ],
			[],
			'192.168.1.100',
		);

		$logger->log( $request );

		$logFile = $this->logDir . '/request.log';
		$this->assertFileExists( $logFile );

		$content = \file_get_contents( $logFile );
		$this->assertStringContainsString( '192.168.1.100', $content );
		$this->assertStringContainsString( 'get_metadata', $content );
		$this->assertStringContainsString( 'hello-dolly', $content );
	}

	public function testLogAppendsToExistingFile(): void {
		$logger = new RequestLogger( $this->logDir );
		$request1 = new Request( [ 'action' => 'get_metadata', 'slug' => 'plugin-a' ], [] );
		$request2 = new Request( [ 'action' => 'download', 'slug' => 'plugin-b' ], [] );

		$logger->log( $request1 );
		$logger->log( $request2 );

		$lines = \file( $this->logDir . '/request.log', \FILE_IGNORE_NEW_LINES );
		$this->assertCount( 2, $lines );
		$this->assertStringContainsString( 'plugin-a', $lines[0] );
		$this->assertStringContainsString( 'plugin-b', $lines[1] );
	}

	public function testIpAnonymizationMasksIpv4(): void {
		$logger = new RequestLogger( $this->logDir );
		$logger->enableIpAnonymization();

		$request = new Request(
			[ 'action' => 'get_metadata', 'slug' => 'test' ],
			[],
			'192.168.1.123',
		);
		$logger->log( $request );

		$content = \file_get_contents( $this->logDir . '/request.log' );
		$this->assertStringContainsString( '192.168.1.0', $content );
		$this->assertStringNotContainsString( '192.168.1.123', $content );
	}

	public function testIpAnonymizationMasksIpv6(): void {
		$logger = new RequestLogger( $this->logDir );
		$logger->enableIpAnonymization();

		$request = new Request(
			[ 'action' => 'get_metadata', 'slug' => 'test' ],
			[],
			'2001:0db8:85a3:0000:0000:8a2e:0370:7334',
		);
		$logger->log( $request );

		$content = \file_get_contents( $this->logDir . '/request.log' );
		// Last 80 bits should be zeroed.
		$this->assertStringNotContainsString( '7334', $content );
		$this->assertStringContainsString( '2001:db8:85a3', $content );
	}

	public function testLogRotationCreatesDateSuffixedFiles(): void {
		$logger = new RequestLogger( $this->logDir );
		$logger->enableLogRotation( RequestLogger::FILE_PER_DAY );

		$request = new Request( [ 'action' => 'get_metadata', 'slug' => 'test' ], [] );
		$logger->log( $request );

		$expectedFile = $this->logDir . '/request-' . \date( 'Y-m-d' ) . '.log';
		$this->assertFileExists( $expectedFile );
		$this->assertFileDoesNotExist( $this->logDir . '/request.log' );
	}

	public function testLogRotationDeletesOldFiles(): void {
		// Pre-create 3 log files.
		\touch( $this->logDir . '/request-2025-01.log' );
		\touch( $this->logDir . '/request-2025-02.log' );
		\touch( $this->logDir . '/request-2025-03.log' );

		$logger = new RequestLogger( $this->logDir );
		$logger->enableLogRotation( RequestLogger::FILE_PER_MONTH, 2 );

		// Logging creates a new file (current month), triggering rotation.
		$request = new Request( [ 'action' => 'get_metadata', 'slug' => 'test' ], [] );
		$logger->log( $request );

		$remaining = \glob( $this->logDir . '/request*.log' );
		$this->assertLessThanOrEqual( 2, \count( $remaining ) );
	}

	public function testEscapesNonPrintableCharacters(): void {
		$logger = new RequestLogger( $this->logDir );

		$request = new Request(
			[ 'action' => 'get_metadata', 'slug' => "evil\ttab" ],
			[],
		);
		$logger->log( $request );

		$content = \file_get_contents( $this->logDir . '/request.log' );
		// Tab inside slug should be escaped, not literal.
		$this->assertStringContainsString( 'evil\\x09tab', $content );
	}

	public function testEscapesBackslashes(): void {
		$logger = new RequestLogger( $this->logDir );

		$request = new Request(
			[ 'action' => 'get_metadata', 'slug' => 'back\\slash' ],
			[],
		);
		$logger->log( $request );

		$content = \file_get_contents( $this->logDir . '/request.log' );
		$this->assertStringContainsString( 'back\\\\slash', $content );
	}

	public function testDefaultRotationPeriodIsMonthly(): void {
		$logger = new RequestLogger( $this->logDir );
		$logger->enableLogRotation();

		$request = new Request( [ 'action' => 'get_metadata', 'slug' => 'test' ], [] );
		$logger->log( $request );

		$expectedFile = $this->logDir . '/request-' . \date( 'Y-m' ) . '.log';
		$this->assertFileExists( $expectedFile );
	}

	public function testLogIncludesTimestamp(): void {
		$logger = new RequestLogger( $this->logDir );

		$request = new Request( [ 'action' => 'get_metadata', 'slug' => 'test' ], [] );
		$logger->log( $request );

		$content = \file_get_contents( $this->logDir . '/request.log' );
		$this->assertMatchesRegularExpression( '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $content );
	}

	public function testLogIncludesWordPressUserAgentInfo(): void {
		$logger = new RequestLogger( $this->logDir );

		$request = new Request(
			[ 'action' => 'get_metadata', 'slug' => 'test' ],
			[ 'User-Agent' => 'WordPress/6.5; https://example.com' ],
		);
		$logger->log( $request );

		$content = \file_get_contents( $this->logDir . '/request.log' );
		$this->assertStringContainsString( '6.5', $content );
		$this->assertStringContainsString( 'https://example.com', $content );
	}
}
