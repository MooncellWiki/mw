<?php

namespace MediaWiki\Extension\MooncellSentry;

use MediaWiki\Context\RequestContext;
use Sentry\SentrySdk;

/** Extension bootstrap; Sentry initialization and exception capture remain site configuration. */
final class Hooks {
	private static ?RequestTrace $requestTrace = null;

	public static function onSetup(): void {
		$hub = SentrySdk::getCurrentHub();
		if ( MW_ENTRY_POINT === 'cli' || $hub->getClient() === null || self::$requestTrace !== null ) {
			return;
		}

		self::$requestTrace = new RequestTrace( $hub, MW_ENTRY_POINT, RequestContext::getMain()->getRequest() );
		// 1.43 has no request-complete hook. PHP shutdown runs after normal post-send updates,
		// including exceptions reported by MediaWiki's previously registered fatal-error handler.
		register_shutdown_function( [ self::$requestTrace, 'finish' ] );
	}

	/**
	 * Mark even caught deferred-update errors without capturing the same exception twice.
	 * @param \Throwable $e
	 * @param bool $suppressed
	 */
	public static function onLogException( $e, $suppressed ): void {
		if ( !$suppressed && self::$requestTrace !== null ) {
			self::$requestTrace->markError();
		}
	}
}
