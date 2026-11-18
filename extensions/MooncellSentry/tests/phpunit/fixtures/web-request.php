<?php

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Request\WebRequest;
use Psr\Http\Message\ServerRequestInterface;
use Sentry\Event;
use Sentry\Integration\RequestFetcherInterface;
use Sentry\Integration\RequestIntegration;
use Sentry\Logs\Log;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

if ( PHP_SAPI !== 'cli' ) {
	exit;
}

[ , $mode, $scenario ] = $argv;
define( 'MW_ENTRY_POINT', $mode === 'cli' ? 'cli' : 'index' );
define( 'MW_NO_SESSION', true );
define( 'MW_CONFIG_CALLBACK', 'wfMooncellSentryTestSettings' );
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/index.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// Keep transport records separate from MW's error pages and PHP's fatal error output.
function wfMooncellSentryTestRecord( array $record ): void {
	static $records;
	$records ??= fopen( 'php://fd/3', 'w' );
	fwrite( $records, json_encode( $record, JSON_THROW_ON_ERROR ) . "\n" );
}

function wfMooncellSentryTestSettings( $settings ): void {
	[ , $mode, $scenario ] = $GLOBALS['argv'];
	$settings->loadArray( [ 'config' => [
		'Sitename' => 'Sentry lifecycle test',
		'Server' => 'http://localhost',
		'SecretKey' => 'test-only',
		'DBtype' => 'sqlite',
		'DBname' => 'sentry-tests',
		'SQLiteDataDir' => '/tmp',
		'DebugLogGroups' => [ 'exception' => '/dev/null' ],
	] ] );

	$dsn = 'https://public@example.invalid/1';
	$options = [
		'traces_sample_rate' => $scenario === 'unsampled' ? 0.0 : 1.0,
		'transport' => new class implements TransportInterface {
			public function send( Event $event ): Result {
				wfMooncellSentryTestRecord( [
					'type' => (string)$event->getType(),
					'trace' => $event->getTraceId(),
					'request_id' => $event->getTags()['mw_request_id'] ?? null,
					'job_id' => $event->getTags()['mw_job_request_id'] ?? null,
					'message' => ( $event->getExceptions()[0] ?? null )?->getValue(),
					'name' => $event->getTransaction(),
					'status' => $event->getContexts()['trace']['status'] ?? null,
					'logs' => array_map( static fn ( Log $log ) => [
						'body' => $log->getBody(), 'trace' => $log->getTraceId(),
					], $event->getLogs() ),
				] );
				return new Result( ResultStatus::success(), $event );
			}

			public function close( ?int $timeout = null ): Result {
				wfMooncellSentryTestRecord( [ 'type' => 'transport_closed' ] );
				return new Result( ResultStatus::success() );
			}
		},
	];
	if ( $scenario === 'custom' ) {
		$options['integrations'] = [ new RequestIntegration( new class implements RequestFetcherInterface {
			public function fetchRequest(): ?ServerRequestInterface {
				wfMooncellSentryTestRecord( [ 'type' => 'custom_fetch' ] );
				return null;
			}
		} ) ];
	}
	if ( $mode === 'legacy' ) {
		\Sentry\init( $options + [ 'dsn' => $dsn, 'enable_logs' => true ] );
		$GLOBALS['wgHooks']['LogException'][] = static function ( $e, $suppressed ) {
			if ( !$suppressed ) {
				\Sentry\withScope( static function ( Scope $scope ) use ( $e ) {
					$scope->setTag( 'mw_request_id', WebRequest::getRequestId() );
					\Sentry\captureException( $e );
				} );
			}
		};
	} elseif ( $mode !== 'disabled' ) {
		$GLOBALS['wgMooncellSentryDsn'] = $dsn;
		$GLOBALS['wgMooncellSentryOptions'] = $options;
	}

	if ( $scenario === 'manifest' ) {
		wfLoadExtension( 'MooncellSentry' );
	} else {
		require dirname( __DIR__, 3 ) . '/bootstrap.php';
	}
	if ( $scenario === 'registration' ) {
		\Sentry\logger()->info( 'startup log' );
		throw new RuntimeException( 'failure before extension registration' );
	}
	if ( $scenario === 'startup' || $scenario === 'manifest' ) {
		$GLOBALS['wgHooks']['SetupAfterCache'][] = static function () {
			\Sentry\logger()->info( 'startup log' );
			throw new RuntimeException( 'failure in SetupAfterCache' );
		};
	}
}

// Model a reporter registered before the extension's normal shutdown finalizer.
if ( $scenario === 'request' || $scenario === 'unsampled' ) {
	register_shutdown_function( static function () {
		MWExceptionHandler::logException( new RuntimeException( 'shutdown error' ) );
		\Sentry\logger()->info( 'shutdown log' );
	} );
}

require dirname( __DIR__, 5 ) . '/includes/WebStart.php';
$options = SentrySdk::getCurrentHub()->getClient()?->getOptions();
wfMooncellSentryTestRecord( [
	'type' => 'setup',
	'client' => $options !== null,
	'tracing' => SentrySdk::getCurrentHub()->getTransaction() !== null,
	'request_id' => WebRequest::getRequestId(),
	'error_rate' => $options?->getSampleRate(),
	'profile_rate' => $options?->getProfilesSampleRate(),
	'logs_enabled' => $options?->getEnableLogs(),
] );
http_response_code( 200 );
switch ( $scenario ) {
	case 'oom':
		// One allocation exceeds this child process's limit, independent of available host memory.
		ini_set( 'memory_limit', (string)( memory_get_usage( true ) + 8 * 1024 * 1024 ) );
		echo strlen( str_repeat( 'x', 16 * 1024 * 1024 ) );
		break;
	case 'job':
		$requestId = WebRequest::getRequestId();
		WebRequest::overrideRequestId( 'job-origin' );
		MWExceptionHandler::logException( new RuntimeException( 'job error' ) );
		WebRequest::overrideRequestId( $requestId );
		MWExceptionHandler::logException( new RuntimeException( 'request error' ) );
		break;
	case 'http-error':
		http_response_code( 503 );
		break;
	case 'request':
	case 'unsampled':
		@trigger_error( 'suppressed warning', E_USER_WARNING );
		trigger_error( 'recoverable warning', E_USER_WARNING );
		DeferredUpdates::addCallableUpdate( static function () {
			MWExceptionHandler::logException( new RuntimeException( 'deferred error' ) );
			\Sentry\logger()->info( 'deferred log' );
		} );
		DeferredUpdates::doUpdates();
		break;
}
