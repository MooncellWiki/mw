<?php

namespace MediaWiki\Extension\MooncellSentry;

use ErrorException;
use MediaWiki\Context\RequestContext;
use MediaWiki\Request\WebRequest;
use MediaWiki\SpecialPage\SpecialPage;
use Sentry\Event;
use Sentry\EventType;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Throwable;

final class Hooks {
	private static ?RequestTrace $requestTrace = null;

	/** True when this extension initialized the SDK and owns exception capture. */
	private static bool $captureExceptions = false;
	private static bool $shutdownRegistered = false;
	private static int $shutdownErrorType = 0;

	/** Called after site settings, before MediaWiki installs its PHP handlers. */
	public static function onRegistration(): void {
		global $wgExtensionFunctions, $wgMooncellSentryDsn, $wgMooncellSentryOptions;

		// MW has already merged all manifests' setup functions. Register the finalizer after them.
		$wgExtensionFunctions[] = [ self::class, 'onSetup' ];
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
		} catch ( Throwable $e ) {
			error_log( 'MooncellSentry could not finish request telemetry: ' . $e->getMessage() );
		}
		try {
			SentrySdk::getCurrentHub()->getClient()?->flush();
		} catch ( Throwable $e ) {
			error_log( 'MooncellSentry could not flush its transport: ' . $e->getMessage() );
		}
	}

	public static function onSpecialPageBeforeExecute( SpecialPage $specialPage ): void {
		if ( MW_ENTRY_POINT === 'index' && $specialPage->getName() === 'RunJobs' ) {
			self::$requestTrace?->markRunJobs();
		}
	}

	public static function onLogException( Throwable $e, bool $suppressed ): void {
		if ( $suppressed || !self::$captureExceptions ) {
			return;
		}
		if ( $e instanceof ErrorException ) {
			$errorTypes = SentrySdk::getCurrentHub()->getClient()?->getOptions()->getErrorTypes() ?? 0;
			if ( ( $errorTypes & $e->getSeverity() ) === 0 ) {
				return;
			}
		}
		\Sentry\withScope( static fn () => \Sentry\captureException( $e ) );
	}
}
