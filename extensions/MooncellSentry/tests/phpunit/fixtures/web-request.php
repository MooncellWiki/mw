<?php
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\WebRequest;
use Sentry\SentrySdk;

// Run only as an isolated CLI fixture, never as a public web endpoint.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}

define( 'MW_ENTRY_POINT', ( $argv[1] ?? '' ) === 'cli' ? 'cli' : 'index' );
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
	$options = [
		'traces_sample_rate' => 1.0,
		'before_send_log' => static function () {
			return null;
		},
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
				'request_id' => $event->getTags()['mw_request_id'] ?? null,
			];
			return null;
		},
	];
	$mode = $GLOBALS['argv'][1] ?? 'managed';
	if ( $mode === 'disabled' ) {
		return;
	}
	if ( $mode !== 'legacy' ) {
		$GLOBALS['wgMooncellSentryDsn'] = 'https://public@example.invalid/1';
		$GLOBALS['wgMooncellSentryOptions'] = $options;
		return;
	}

	// Old docker-config is supported until the site overlay switches to extension settings.
	\Sentry\init( $options + [ 'dsn' => 'https://public@example.invalid/1' ] );
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
	if ( SentrySdk::getCurrentHub()->getClient() ) {
		MWExceptionHandler::logException( new RuntimeException( 'shutdown error' ) );
	}
} );
require dirname( __DIR__, 5 ) . '/includes/WebStart.php';
// Keep native warning text out of the fixture JSON; MW still receives the warning.
ini_set( 'display_errors', '0' );
ini_set( 'log_errors', '0' );
MediaWikiServices::getInstance()->getHookContainer()->run(
	'LogException', [ new RuntimeException( 'suppressed error' ), true ]
);
@trigger_error( 'suppressed PHP warning', E_USER_WARNING );
$transaction = SentrySdk::getCurrentHub()->getTransaction();
$GLOBALS['sentrySuppressedIgnored'] = !$GLOBALS['sentryTestEvents'] &&
	( $transaction === null || $transaction->getStatus() === null );
$GLOBALS['sentryTraceStarted'] = $transaction !== null;
if ( ( $argv[1] ?? '' ) === 'legacy' ) {
	MWExceptionHandler::logException( new RuntimeException( 'execute error' ) );
} else {
	trigger_error( 'execute PHP warning', E_USER_WARNING );
}
DeferredUpdates::addCallableUpdate( static function () {
	MWExceptionHandler::logException( new RuntimeException( 'deferred error' ) );
} );
DeferredUpdates::doUpdates();
register_shutdown_function( static function () {
	$client = SentrySdk::getCurrentHub()->getClient();
	$options = $client ? $client->getOptions() : null;
	echo json_encode( [
		'events' => $GLOBALS['sentryTestEvents'],
		'suppressed_ignored' => $GLOBALS['sentrySuppressedIgnored'],
		'trace_started' => $GLOBALS['sentryTraceStarted'],
		'client_initialized' => $client !== null,
		'error_rate' => $options ? $options->getSampleRate() : null,
		'profile_rate' => $options ? $options->getProfilesSampleRate() : null,
		'logs_enabled' => $options ? $options->getEnableLogs() : null,
	], JSON_PRETTY_PRINT ), "\n";
} );
