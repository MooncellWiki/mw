<?php

namespace MediaWiki\Tests\Unit;

use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\EntryPointEnvironment;
use MediaWiki\MediaWikiEntryPoint;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\WebRequest;
use MediaWiki\Request\WebResponse;
use MediaWikiUnitTestCase;
use RuntimeException;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\Tracing\SpanContext;

/** @covers \MediaWiki\MediaWikiEntryPoint */
class MediaWikiEntryPointSentryTest extends MediaWikiUnitTestCase {

	/** @dataProvider provideSampling */
	public function testRequestCorrelation( float $rate, bool $setupFails ) {
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
			$previousSpan = new \Sentry\Tracing\Span( SpanContext::make() );
			$hub->setSpan( $previousSpan );
			$request = $this->createMock( WebRequest::class );
			$request->method( 'getMethod' )->willReturn( 'GET' );
			$request->method( 'getText' )->willReturn( 'view' );
			$context = $this->createMock( IContextSource::class );
			$context->method( 'getRequest' )->willReturn( $request );
			$context->method( 'getConfig' )->willReturn( $this->createMock( Config::class ) );
			$mw = $this->getMockBuilder( MediaWikiEntryPoint::class )
				->setConstructorArgs( [ $context, $this->createMock( EntryPointEnvironment::class ),
					$this->createMock( MediaWikiServices::class ) ] )
				->onlyMethods( [ 'doSetup', 'execute', 'doPrepareForOutput', 'doPostOutputShutdown',
					'flushOutputBuffer', 'getResponse' ] )
				->getMockForAbstractClass();
			$mw->method( 'getResponse' )->willReturn( $this->createMock( WebResponse::class ) );
			$root = null;
			$mw->method( 'doSetup' )->willReturnCallback( static function () use ( &$root, $setupFails ) {
				$root = SentrySdk::getCurrentHub()->getTransaction();
				if ( $setupFails ) {
					throw new RuntimeException( 'setup failed' );
				}
			} );
			$mw->method( 'execute' )->willReturnCallback( static function () use ( $rate ) {
				if ( $rate === 1.0 && extension_loaded( 'excimer' ) ) {
					// Give the real sampling profiler enough CPU work to produce a profile.
					$until = microtime( true ) + 0.03;
					while ( microtime( true ) < $until ) {
						hash( 'sha256', 'profile correlation' );
					}
				}
				\Sentry\logger()->info( 'execute log' );
				\Sentry\captureException( new RuntimeException( 'execute error' ) );
			} );
			// Model an exception caught and reported inside post-send processing (e.g. SMW).
			$mw->method( 'doPostOutputShutdown' )->willReturnCallback( static function () {
				\Sentry\logger()->info( 'deferred log' );
				\Sentry\captureException( new RuntimeException( 'deferred error' ) );
			} );
			try {
				$mw->run();
				$this->assertFalse( $setupFails );
			} catch ( RuntimeException $e ) {
				$this->assertTrue( $setupFails );
				$this->assertSame( 'setup failed', $e->getMessage() );
			}
			$this->assertSame( $previousSpan, $hub->getSpan(), 'Restore caller scope/span' );
			$this->assertNotNull( $root->getEndTimestamp(), 'Finish even after setup throws' );
			$this->assertCount( $setupFails ? 0 : 2, $events );
			foreach ( $events as $event ) {
				$this->assertSame( (string)$root->getTraceId(), $event->getContexts()['trace']['trace_id'] );
				$this->assertSame( WebRequest::getRequestId(), $event->getTags()['mw_request_id'] );
			}
			$this->assertCount( $setupFails ? 0 : 2, $logs );
			foreach ( $logs as $log ) {
				$this->assertSame( (string)$root->getTraceId(), $log->getTraceId() );
			}
			$this->assertCount( $rate === 1.0 ? 1 : 0, $transactions );
			if ( $transactions ) {
				$this->assertSame( WebRequest::getRequestId(), $transactions[0]->getTags()['mw_request_id'] );
				if ( !$setupFails && extension_loaded( 'excimer' ) ) {
					$profile = $transactions[0]->getSdkMetadata( 'profile' );
					$this->assertNotNull( $profile );
					$data = $profile->getFormattedData( $transactions[0] );
					$this->assertNotNull( $data );
					$this->assertSame( (string)$root->getTraceId(), $data['transaction']['trace_id'] );
					$this->assertSame( (string)$transactions[0]->getId(), $data['transaction']['id'] );
				}
				$spans = $transactions[0]->getSpans();
				$this->assertCount( $setupFails ? 1 : 4, $spans );
				foreach ( $events as $i => $event ) {
					$this->assertSame( (string)$spans[$i === 0 ? 1 : 3]->getSpanId(),
						$event->getContexts()['trace']['span_id'] );
				}
			}
		} finally {
			SentrySdk::setCurrentHub( $previousHub );
		}
	}

	public static function provideSampling(): array {
		return [ [ 1.0, false ], [ 0.0, false ], [ 1.0, true ], [ 0.0, true ] ];
	}
}
