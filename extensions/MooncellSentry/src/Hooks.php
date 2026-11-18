<?php

namespace MediaWiki\Extension\MooncellSentry;

use MediaWiki\Context\RequestContext;
use MediaWiki\Request\WebRequest;
use Sentry\SentrySdk;
use Sentry\State\Scope;

final class Hooks {
	private static ?RequestTrace $requestTrace = null;
	/** Null until a client exists, false when the legacy site hook owns capture. */
	private static ?bool $captureExceptions = null;
	private static bool $shutdownRegistered = false;

	/** Called after site settings, before MediaWiki installs its PHP handlers. */
	public static function onRegistration(): void {
		global $wgMooncellSentryDsn, $wgMooncellSentryOptions;

		if ( self::$captureExceptions !== null ) {
			return;
		}
		$dsn = $wgMooncellSentryDsn ?? '';
		if ( $dsn !== '' ) {
			// MW later takes over warnings and exceptions. The SDK's independent fatal
			// shutdown listener continues to report engine failures, including OOMs.
			\Sentry\init( [ 'dsn' => $dsn ] + ( $wgMooncellSentryOptions ?? [] ) + [
				'sample_rate' => 1.0,
				'traces_sample_rate' => 0.01,
				'profiles_sample_rate' => 0.1,
				'enable_logs' => true,
			] );
			\Sentry\configureScope( static function ( Scope $scope ) {
				$scope->setTag( 'mw_request_id', WebRequest::getRequestId() );
			} );
		} elseif ( SentrySdk::getCurrentHub()->getClient() === null ) {
			return;
		}
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
				self::$requestTrace->finish( http_response_code() ?: 200 );
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
		\Sentry\withScope( static function ( Scope $scope ) use ( $e ) {
			// CLI workers can change the request ID between jobs.
			if ( self::$requestTrace === null ) {
				$scope->setTag( 'mw_request_id', WebRequest::getRequestId() );
			}
			\Sentry\captureException( $e );
		} );
	}
}
