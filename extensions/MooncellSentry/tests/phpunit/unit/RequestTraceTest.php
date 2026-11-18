<?php

namespace MediaWiki\Extension\MooncellSentry\Tests;

use MediaWiki\Extension\MooncellSentry\RequestTrace;
use MediaWiki\Request\WebRequest;
use MediaWikiUnitTestCase;
use RuntimeException;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;

/** @covers \MediaWiki\Extension\MooncellSentry\RequestTrace */
class RequestTraceTest extends MediaWikiUnitTestCase {

	/** @dataProvider provideSampling */
	public function testRequestCorrelation( float $rate ) {
		$previousHub = SentrySdk::getCurrentHub();
		$events = [];
		$logs = [];
		$transactions = [];
		SentrySdk::setCurrentHub( new Hub() );
		try {
			\Sentry\init( [
				'dsn' => 'https://public@example.invalid/1',
				'traces_sample_rate' => $rate,
				'profiles_sample_rate' => 1.0,
				'enable_logs' => true,
				'before_send_log' => static function ( \Sentry\Logs\Log $log ) use ( &$logs ) {
					$logs[] = $log;
					return null;
				},
				'default_integrations' => false,
				'integrations' => [ new \Sentry\Integration\EnvironmentIntegration() ],
				'before_send' => static function ( Event $event ) use ( &$events ) {
					$events[] = $event;
					return null;
				},
				'before_send_transaction' => static function ( Event $event ) use ( &$transactions ) {
					$transactions[] = $event;
					return null;
				},
			] );
			$hub = SentrySdk::getCurrentHub();
			$previousSpan = new Span( SpanContext::make() );
			$hub->setSpan( $previousSpan );
			$request = $this->createMock( WebRequest::class );
			$request->method( 'getMethod' )->willReturn( 'GET' );
			$request->method( 'getText' )->willReturn( 'view' );
			$trace = new RequestTrace( $hub, 'index', $request );
			$root = $hub->getTransaction();
			$this->assertNotNull( $root );
			$this->assertNull( $root->getEndTimestamp() );

			\Sentry\logger()->info( 'execute log' );
			\Sentry\captureException( new RuntimeException( 'execute error' ) );
			// The transaction remains active after output; deferred errors use the same scope.
			\Sentry\logger()->info( 'deferred log' );
			\Sentry\captureException( new RuntimeException( 'deferred error' ) );

			if ( $rate === 1.0 && extension_loaded( 'excimer' ) ) {
				// Give the real sampling profiler enough CPU work to produce a profile.
				$until = microtime( true ) + 0.03;
				while ( microtime( true ) < $until ) {
					hash( 'sha256', 'profile correlation' );
				}
			}
			$trace->finish( 500 );
			$trace->finish();
			$this->assertSame( $previousSpan, $hub->getSpan(), 'Restore caller scope/span' );
			$this->assertNotNull( $root->getEndTimestamp() );
			$this->assertEquals( SpanStatus::internalError(), $root->getStatus() );
			$this->assertCount( 2, $events );
			foreach ( $events as $event ) {
				$this->assertSame( (string)$root->getTraceId(), $event->getContexts()['trace']['trace_id'] );
				$this->assertSame( (string)$root->getSpanId(), $event->getContexts()['trace']['span_id'] );
				$this->assertSame( WebRequest::getRequestId(), $event->getTags()['mw_request_id'] );
			}
			$this->assertCount( 2, $logs );
			foreach ( $logs as $log ) {
				$this->assertSame( (string)$root->getTraceId(), $log->getTraceId() );
			}
			$this->assertCount( $rate === 1.0 ? 1 : 0, $transactions );
			if ( $transactions ) {
				$this->assertSame( 'index', $transactions[0]->getTransaction() );
				$this->assertSame( WebRequest::getRequestId(), $transactions[0]->getTags()['mw_request_id'] );
				if ( extension_loaded( 'excimer' ) ) {
					$profile = $transactions[0]->getSdkMetadata( 'profile' );
					$this->assertNotNull( $profile );
					$data = $profile->getFormattedData( $transactions[0] );
					$this->assertNotNull( $data );
					$this->assertSame( (string)$root->getTraceId(), $data['transaction']['trace_id'] );
					$this->assertSame( (string)$transactions[0]->getId(), $data['transaction']['id'] );
				}
			}
		} finally {
			SentrySdk::setCurrentHub( $previousHub );
		}
	}

	/**
	 * @covers \MediaWiki\Extension\MooncellSentry\Hooks
	 * @dataProvider provideRequests
	 */
	public function testRequestShutdown( string $mode, string $scenario ): void {
		$records = $this->runFixture( $mode, $scenario );
		$setup = $records['setup'][0];
		$this->assertSame( $mode !== 'disabled', $setup['client'] );
		$this->assertSame( !in_array( $mode, [ 'cli', 'disabled' ], true ), $setup['tracing'] );
		if ( $mode === 'disabled' ) {
			$this->assertSame( [ 'setup' ], array_keys( $records ) );
			return;
		}
		$this->assertSame( 1.0, (float)$setup['error_rate'] );
		$this->assertTrue( $setup['logs_enabled'] );
		if ( $mode !== 'legacy' ) {
			$this->assertSame( 0.1, $setup['profile_rate'] );
		}
		$this->assertSame( [ 'PHP Warning: recoverable warning', 'deferred error', 'shutdown error' ],
			array_column( $records['event'], 'message' ) );
		$this->assertSame( [ $setup['request_id'] ], array_values( array_unique(
			array_column( $records['event'], 'request_id' ) ) ) );

		$this->assertCount( 1, $records['transport_closed'] );
		$this->assertCount( 1, $records['log'] );
		$logs = $records['log'][0]['logs'];
		$this->assertSame( [ 'deferred log', 'shutdown log' ], array_column( $logs, 'body' ) );
		$trace = $records['event'][0]['trace'];
		$this->assertNotEmpty( $trace );
		$this->assertSame( [ $trace ], array_values( array_unique( array_column( $records['event'], 'trace' ) ) ) );
		$this->assertSame( [ $trace ], array_values( array_unique( array_column( $logs, 'trace' ) ) ) );
		if ( $mode === 'cli' || $scenario === 'unsampled' ) {
			$this->assertArrayNotHasKey( 'transaction', $records );
			return;
		}
		$this->assertCount( 1, $records['transaction'] );
		$transaction = $records['transaction'][0];
		$this->assertSame( 'ok', $transaction['status'] );
		$this->assertSame( 'index', $transaction['name'] );
		$this->assertSame( $setup['request_id'], $transaction['request_id'] );
		$this->assertSame( $trace, $transaction['trace'] );
	}

	/**
	 * @covers \MediaWiki\Extension\MooncellSentry\Hooks
	 * @dataProvider provideBootFailures
	 */
	public function testBootFailure( string $mode, string $scenario, string $message ): void {
		$records = $this->runFixture( $mode, $scenario, 255 );
		$this->assertSame( [ $message ], array_column( $records['event'], 'message' ) );
		$this->assertNotEmpty( $records['event'][0]['request_id'] );
		$this->assertSame( 'startup log', $records['log'][0]['logs'][0]['body'] );
		$this->assertCount( 1, $records['transport_closed'] );
		$this->assertArrayNotHasKey( 'transaction', $records );
	}

	/** @covers \MediaWiki\Extension\MooncellSentry\Hooks */
	public function testNativeFatal(): void {
		$records = $this->runFixture( 'managed', 'oom', 255 );
		$this->assertCount( 1, $records['event'] );
		$error = $records['event'][0];
		$this->assertStringContainsString( 'Allowed memory size', $error['message'] );
		$transaction = $records['transaction'][0];
		$this->assertSame( $transaction['trace'], $error['trace'] );
		$this->assertSame( $records['setup'][0]['request_id'], $error['request_id'] );
		$this->assertSame( 'internal_error', $transaction['status'] );
	}

	/**
	 * @covers \MediaWiki\Extension\MooncellSentry\Hooks
	 * @dataProvider provideJobModes
	 */
	public function testJobRequestId( string $mode ): void {
		$records = $this->runFixture( $mode, 'job' );
		[ $job, $other ] = $records['event'];
		$transaction = $records['transaction'][0];
		$this->assertSame( $records['setup'][0]['request_id'], $transaction['request_id'] );
		$this->assertSame( $transaction['request_id'], $job['request_id'] );
		$this->assertSame( $transaction['trace'], $job['trace'] );
		$this->assertSame( 'job-origin', $job['job_id'] );
		$this->assertNull( $other['job_id'] );
		$this->assertNull( $transaction['job_id'] );
	}

	/** @covers \MediaWiki\Extension\MooncellSentry\Hooks */
	public function testCustomIntegration(): void {
		$records = $this->runFixture( 'managed', 'custom' );
		$this->assertCount( 1, $records['custom_fetch'] );
	}

	/** @covers \MediaWiki\Extension\MooncellSentry\Hooks */
	public function testHttpFailureStatus(): void {
		$records = $this->runFixture( 'managed', 'http-error' );
		$this->assertSame( 'unavailable', $records['transaction'][0]['status'] );
	}

	private function runFixture( string $mode, string $scenario, int $expectedExit = 0 ): array {
		$diagnostics = tmpfile();
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.proc_open
		$process = proc_open(
			[ PHP_BINARY, dirname( __DIR__ ) . '/fixtures/web-request.php', $mode, $scenario ],
			[ 1 => $diagnostics, 2 => $diagnostics, 3 => [ 'pipe', 'w' ] ], $pipes
		);
		$lines = preg_split( '/\n/', stream_get_contents( $pipes[3] ), -1, PREG_SPLIT_NO_EMPTY );
		fclose( $pipes[3] );
		$exit = proc_close( $process );
		rewind( $diagnostics );
		$output = stream_get_contents( $diagnostics );
		fclose( $diagnostics );
		$this->assertSame( $expectedExit, $exit, $output );
		$records = [];
		foreach ( $lines as $line ) {
			$record = json_decode( $line, true, 512, JSON_THROW_ON_ERROR );
			$records[$record['type']][] = $record;
		}
		return $records;
	}

	public static function provideSampling(): array {
		return [ [ 1.0 ], [ 0.0 ] ];
	}

	public static function provideRequests(): array {
		return [
			[ 'managed', 'request' ], [ 'legacy', 'request' ], [ 'cli', 'request' ],
			[ 'disabled', 'request' ], [ 'managed', 'unsampled' ],
		];
	}

	public static function provideBootFailures(): array {
		return [
			[ 'managed', 'registration', 'failure before extension registration' ],
			[ 'managed', 'startup', 'failure in SetupAfterCache' ],
			[ 'legacy', 'startup', 'failure in SetupAfterCache' ],
			[ 'managed', 'manifest', 'failure in SetupAfterCache' ],
		];
	}

	public static function provideJobModes(): array {
		return [ [ 'managed' ], [ 'legacy' ] ];
	}
}
