<?php
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\WebRequest;
use Sentry\SentrySdk;

// Run only as an isolated CLI fixture, never as a public web endpoint.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}

define( 'MW_ENTRY_POINT', 'index' );
define( 'MW_NO_SESSION', true );
define( 'MW_CONFIG_CALLBACK', 'wfMooncellSentryTestSettings' );
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/index.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$GLOBALS['sentryTestEvents'] = [];

function wfMooncellSentryTestSettings( $settings ) {
	$settings->loadArray( [ 'config' => [
		'ShowExceptionDetails' => true,
		'Sitename' => 'Sentry lifecycle test',
		'Server' => 'http://localhost',
		'SecretKey' => 'test-only',
		'DBtype' => 'sqlite',
		'DBname' => 'sentry-tests',
		'SQLiteDataDir' => '/tmp',
		'DebugLogGroups' => [ 'exception' => '/dev/null' ],
	] ] );
	wfLoadExtension( 'MooncellSentry' );
	\Sentry\init( [
		'dsn' => 'https://public@example.invalid/1',
		'traces_sample_rate' => 1.0,
		'default_integrations' => false,
		'before_send' => static function ( $event ) {
			$GLOBALS['sentryTestEvents'][] = [
				'type' => 'error',
				'trace' => $event->getTraceId(),
				'request_id' => $event->getTags()['mw_request_id'] ?? null,
			];
			return null;
		},
		'before_send_transaction' => static function ( $event ) {
			$GLOBALS['sentryTestEvents'][] = [
				'type' => 'transaction',
				'trace' => $event->getTraceId(),
				'name' => $event->getTransaction(),
				'status' => (string)$event->getContexts()['trace']['status'],
				'suppressed_ignored' => $GLOBALS['sentrySuppressedIgnored'],
				'request_id' => $event->getTags()['mw_request_id'] ?? null,
			];
			return null;
		},
	] );
	// Match docker-config's fgo/prts/mooncell post-config.php exception hook.
	$GLOBALS['wgHooks']['LogException'][] = static function ( $e, $suppressed ) {
		if ( $suppressed ) {
			return;
		}
		\Sentry\withScope( static function ( \Sentry\State\Scope $scope ) use ( $e ) {
			$scope->setTag( 'mw_request_id', WebRequest::getRequestId() );
			\Sentry\captureException( $e );
		} );
	};
}

// Model a reporter registered before our finalizer, such as MediaWiki's fatal handler.
register_shutdown_function( static function () {
	if ( SentrySdk::getCurrentHub()->getTransaction() ) {
		MWExceptionHandler::logException( new RuntimeException( 'shutdown error' ) );
	}
} );
require dirname( __DIR__, 5 ) . '/includes/WebStart.php';
MediaWikiServices::getInstance()->getHookContainer()->run(
	'LogException', [ new RuntimeException( 'suppressed error' ), true ]
);
$GLOBALS['sentrySuppressedIgnored'] = !$GLOBALS['sentryTestEvents'] &&
	SentrySdk::getCurrentHub()->getTransaction()->getStatus() === null;
MWExceptionHandler::logException( new RuntimeException( 'execute error' ) );
DeferredUpdates::addCallableUpdate( static function () {
	MWExceptionHandler::logException( new RuntimeException( 'deferred error' ) );
} );
DeferredUpdates::doUpdates();
register_shutdown_function( static function () {
	echo json_encode( $GLOBALS['sentryTestEvents'], JSON_PRETTY_PRINT ), "\n";
} );
