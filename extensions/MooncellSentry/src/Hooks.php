<?php

namespace MediaWiki\Extension\MooncellSentry;

use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\WebRequest;
use Sentry\Integration\AbstractErrorListenerIntegration;
use Sentry\SentrySdk;

/** Own SDK setup, exception capture and request tracing without patching core. */
final class Hooks {
	private static ?RequestTrace $requestTrace = null;
	private static bool $captureExceptions = false;

	public static function onSetup(): void {
		if ( self::$requestTrace !== null || self::$captureExceptions ) {
			return;
		}
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$dsn = $config->get( 'MooncellSentryDsn' );
		if ( $dsn !== '' ) {
			$options = array_replace( [
				'sample_rate' => 1.0,
				'traces_sample_rate' => 0.01,
				'profiles_sample_rate' => 0.1,
				'enable_logs' => true,
			], $config->get( 'MooncellSentryOptions' ), [ 'dsn' => $dsn ] );
			// MW owns PHP/uncaught/fatal handlers and reports through LogException. Installing
			// SDK listeners after MW setup would otherwise capture the same error twice.
			$extraIntegrations = $options['integrations'] ?? [];
			$options['integrations'] = static function ( array $defaults ) use ( $extraIntegrations ): array {
				$integrations = is_callable( $extraIntegrations )
					? $extraIntegrations( $defaults )
					: array_merge( $defaults, $extraIntegrations );
				return array_values( array_filter( $integrations,
					static fn ( $integration ) => !$integration instanceof AbstractErrorListenerIntegration
				) );
			};
			\Sentry\init( $options );
			self::$captureExceptions = true;
		}

		$hub = SentrySdk::getCurrentHub();
		// During migration, old post-config.php can still initialize/capture by itself.
		// With no extension DSN, bind its client for tracing but do not capture twice.
		if ( MW_ENTRY_POINT === 'cli' || $hub->getClient() === null ) {
			return;
		}
		self::$requestTrace = new RequestTrace( $hub, MW_ENTRY_POINT, RequestContext::getMain()->getRequest() );
		// 1.43 has no request-complete hook. Finish after normal post-send updates and
		// MediaWiki's previously registered fatal-error reporter.
		register_shutdown_function( [ self::$requestTrace, 'finish' ] );
	}

	/**
	 * Capture unsuppressed errors once, including errors caught in deferred updates.
	 * @param \Throwable $e
	 * @param bool $suppressed
	 */
	public static function onLogException( $e, $suppressed ): void {
		if ( $suppressed ) {
			return;
		}
		if ( self::$requestTrace !== null ) {
			self::$requestTrace->markError();
		}
		if ( self::$captureExceptions ) {
			\Sentry\withScope( static function ( \Sentry\State\Scope $scope ) use ( $e ) {
				$scope->setTag( 'mw_request_id', WebRequest::getRequestId() );
				\Sentry\captureException( $e );
			} );
		}
	}
}
