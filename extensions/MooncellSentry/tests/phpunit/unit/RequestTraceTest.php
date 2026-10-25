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
			$trace->markError();

			if ( $rate === 1.0 && extension_loaded( 'excimer' ) ) {
				// Give the real sampling profiler enough CPU work to produce a profile.
				$until = microtime( true ) + 0.03;
				while ( microtime( true ) < $until ) {
					hash( 'sha256', 'profile correlation' );
				}
			}
			$trace->finish();
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
	 * @dataProvider provideModes
	 */
	public function testWebLifecycle( string $mode ): void {
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.proc_open
		$process = proc_open(
			[ PHP_BINARY, dirname( __DIR__ ) . '/fixtures/web-request.php', $mode ],
			[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			$pipes
		);
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $error );
		$result = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		$this->assertTrue( $result['suppressed_ignored'] );
		$this->assertSame( $mode !== 'disabled', $result['client_initialized'] );
		$this->assertSame( $mode !== 'disabled' && $mode !== 'cli', $result['trace_started'] );
		$events = $result['events'];
		if ( $mode === 'disabled' ) {
			$this->assertSame( [], $events );
			return;
		}
		$this->assertSame( 1.0, $result['error_rate'] * 1.0 );
		if ( $mode !== 'legacy' ) {
			$this->assertSame( 0.1, $result['profile_rate'] );
			$this->assertTrue( $result['logs_enabled'] );
		}
		if ( $mode === 'cli' ) {
			$this->assertSame( [ 'error', 'error', 'error' ], array_column( $events, 'type' ) );
			return;
		}
		$this->assertSame( [ 'error', 'error', 'error', 'transaction' ], array_column( $events, 'type' ) );
		$this->assertCount( 1, array_unique( array_column( $events, 'trace' ) ) );
		$this->assertNotEmpty( $events[0]['trace'] );
		$this->assertCount( 1, array_unique( array_column( $events, 'request_id' ) ) );
		$this->assertNotEmpty( $events[0]['request_id'] );
		$this->assertSame( 'index', $events[3]['name'] );
		$this->assertSame( 'internal_error', $events[3]['status'] );
	}

	public static function provideSampling(): array {
		return [ [ 1.0 ], [ 0.0 ] ];
	}

	public static function provideModes(): array {
		return [ [ 'managed' ], [ 'legacy' ], [ 'disabled' ], [ 'cli' ] ];
	}
}
