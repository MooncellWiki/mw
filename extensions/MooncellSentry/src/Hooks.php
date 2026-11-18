<?php

namespace MediaWiki\Extension\MooncellSentry;

use MediaWiki\Context\RequestContext;
use MediaWiki\Request\WebRequest;
use Sentry\Event;
use Sentry\EventType;
use Sentry\SentrySdk;
use Sentry\State\Scope;

final class Hooks {
	private static ?RequestTrace $requestTrace = null;
	/** Null until a client exists, false when the legacy site hook owns capture. */
	private static ?bool $captureExceptions = null;
	private static bool $shutdownRegistered = false;
	private static int $shutdownErrorType = 0;

	/** Called after site settings, before MediaWiki installs its PHP handlers. */
	public static function onRegistration(): void {
		global $wgMooncellSentryDsn, $wgMooncellSentryOptions;

		if ( self::$captureExceptions !== null ) {
			return;
		}
		$dsn = $wgMooncellSentryDsn ?? '';
		if ( $dsn === '' && SentrySdk::getCurrentHub()->getClient() === null ) {
			return;
		}
		// The SDK's fatal reporter and MW session cleanup can themselves issue warnings.
		register_shutdown_function( static function () {
			self::$shutdownErrorType = error_get_last()['type'] ?? 0;
		} );
		if ( $dsn !== '' ) {
			// MW later takes over warnings and exceptions. The SDK's independent fatal
			// shutdown listener continues to report engine failures, including OOMs.
			\Sentry\init( [ 'dsn' => $dsn ] + ( $wgMooncellSentryOptions ?? [] ) + [
				'sample_rate' => 1.0,
				'traces_sample_rate' => 0.01,
				'profiles_sample_rate' => 0.1,
				'enable_logs' => true,
			] );
		}
		\Sentry\configureScope( static function ( Scope $scope ) {
			$scope->setTag( 'mw_request_id', WebRequest::getRequestId() );
			if ( MW_ENTRY_POINT === 'cli' ) {
				// JobRunner adopts each job's request ID. SDK fatal events bypass LogException.
				$scope->addEventProcessor( static function ( Event $event ): Event {
					if ( $event->getType() === EventType::event() ) {
						$event->setTag( 'mw_request_id', WebRequest::getRequestId() );
					}
					return $event;
				} );
			}
		} );
		self::$captureExceptions = $dsn !== '';
		register_shutdown_function( static function () {
			// Setup can terminate before ExtensionFunctions gets to register the normal finalizer.
			if ( !self::$shutdownRegistered ) {
				self::finishRequest();
			}
		} );
	}

	public static function onSetup(): void {
		$hub = SentrySdk::getCurrentHub();
		if ( self::$shutdownRegistered || $hub->getClient() === null ) {
			return;
		}
		if ( MW_ENTRY_POINT !== 'cli' ) {
			self::$requestTrace = new RequestTrace( $hub, MW_ENTRY_POINT, RequestContext::getMain()->getRequest() );
		}
		// Include post-send updates and the shutdown handlers already registered by MW and the SDK.
		register_shutdown_function( self::finishRequest( ... ) );
		self::$shutdownRegistered = true;
	}

	private static function finishRequest(): void {
		try {
			if ( self::$requestTrace !== null ) {
				self::$requestTrace->finish( http_response_code() ?: 200, self::$shutdownErrorType );
			} else {
				\Sentry\logger()->flush();
			}
		} finally {
			SentrySdk::getCurrentHub()->getClient()?->flush();
		}
	}

	public static function onLogException( \Throwable $e, bool $suppressed ): void {
		if ( $suppressed || !self::$captureExceptions ) {
			return;
		}
		\Sentry\withScope( static fn () => \Sentry\captureException( $e ) );
	}
}
