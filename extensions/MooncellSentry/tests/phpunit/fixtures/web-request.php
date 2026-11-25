<?php

use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\HookContainer\HookRunner;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\WebRequest;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use Psr\Http\Message\ServerRequestInterface;
use Sentry\Event;
use Sentry\Integration\RequestFetcherInterface;
use Sentry\Integration\RequestIntegration;
use Sentry\Logs\Log;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\Tracing\SamplingContext;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

if ( PHP_SAPI !== 'cli' ) {
	exit;
}

[ , $mode, $scenario ] = $argv;
define( 'MW_ENTRY_POINT', $argv[3] ?? ( $mode === 'cli' ? 'cli' : 'index' ) );
define( 'MW_NO_SESSION', true );
define( 'MW_CONFIG_CALLBACK', 'wfMooncellSentryTestSettings' );
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/' . MW_ENTRY_POINT . '.php';
$_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// Keep transport records separate from MW's error pages and PHP's fatal error output.
function wfMooncellSentryTestRecord( array $record ): void {
	static $records;
	$records ??= fopen( 'php://fd/3', 'w' );
	fwrite( $records, json_encode( $record, JSON_THROW_ON_ERROR ) . "\n" );
}

function wfMooncellSentryTestFailAfterRegistration(): void {
	\Sentry\logger()->info( 'startup log' );
	throw new RuntimeException( 'failure after extension registration' );
}

function wfMooncellSentryTestPeerRegistration(): void {
	if ( $GLOBALS['argv'][2] === 'peer-registration' ) {
		\Sentry\logger()->info( 'startup log' );
		throw new RuntimeException( 'failure in peer registration' );
	}
}

function wfMooncellSentryTestPeerSetup(): void {
	if ( in_array( $GLOBALS['argv'][2], [ 'request', 'unsampled' ], true ) ) {
		register_shutdown_function( static function () {
			MWExceptionHandler::logException( new RuntimeException( 'shutdown error' ) );
			\Sentry\logger()->info( 'shutdown log' );
		} );
	}
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

	wfLoadExtension( 'MooncellSentry' );
	wfLoadExtension( 'MooncellSentryTestPeer', __DIR__ . '/peer.json' );
	$dsn = 'https://public@example.invalid/1';
	$options = [
		'traces_sample_rate' => $scenario === 'unsampled' ? 0.0 : 1.0,
		'transport' => new class implements TransportInterface {
			public function send( Event $event ): Result {
				wfMooncellSentryTestRecord( [
					'type' => (string)$event->getType(),
					'trace' => $event->getTraceId(),
					'sample_rand' => $event->getSdkMetadata( 'dynamic_sampling_context' )?->get( 'sample_rand' ),
					'request_id' => $event->getTags()['mw_request_id'] ?? null,
					'job_id' => $event->getTags()['mw_job_request_id'] ?? null,
					'special_page' => $event->getTags()['mw.special'] ?? null,
					'message' => ( $event->getExceptions()[0] ?? null )?->getValue(),
					'name' => $event->getTransaction(),
					'status' => $event->getContexts()['trace']['status'] ?? null,
					'http_status' => $event->getContexts()['response']['status_code'] ?? null,
					'data' => $event->getContexts()['trace']['data'] ?? [],
					'logs' => array_map( static fn ( Log $log ) => [
						'body' => $log->getBody(), 'trace' => $log->getTraceId(),
					], $event->getLogs() ),
				] );
				return new Result( ResultStatus::success(), $event );
			}

			public function close( ?int $timeout = null ): Result {
				wfMooncellSentryTestRecord( [ 'type' => 'transport_closed' ] );
				if ( $GLOBALS['argv'][2] === 'flush-failure' ) {
					throw new RuntimeException( 'transport close failed' );
				}
				return new Result( ResultStatus::success() );
			}
		},
	];
	if ( $scenario === 'error-mask' ) {
		$options['error_types'] = E_ERROR | E_USER_NOTICE;
	}
	if ( $scenario === 'bootstrap-sampler' ) {
		$GLOBALS['wgHooks']['SetupAfterCache'][] = static function () {
			\Sentry\continueTrace( 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-1',
				'sentry-sampled=true,sentry-sample_rate=0.5,sentry-sample_rand=0.25' );
		};
		$options['traces_sampler'] = static function ( SamplingContext $context ): float {
			wfMooncellSentryTestRecord( [
				'type' => 'sampling',
				'parent_sampled' => $context->getParentSampled(),
				'parent_rate' => $context->getTransactionContext()->getMetadata()->getParentSamplingRate(),
				'sample_rand' => $context->getSampleRand(),
			] );
			return 0.0;
		};
	}
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

	if ( $scenario === 'after-registration' ) {
		define( 'MW_FINAL_SETUP_CALLBACK', 'wfMooncellSentryTestFailAfterRegistration' );
	}
	if ( $scenario === 'startup' ) {
		$GLOBALS['wgHooks']['SetupAfterCache'][] = static function () {
			\Sentry\logger()->info( 'startup log' );
			throw new RuntimeException( 'failure in SetupAfterCache' );
		};
	}
	if ( $scenario === 'request' || $scenario === 'unsampled' ) {
		$GLOBALS['wgHooks']['SetupAfterCache'][] = static function () {
			MWExceptionHandler::logException( new RuntimeException( 'setup error' ) );
			\Sentry\logger()->info( 'setup log' );
		};
	}
	if ( $scenario === 'explicit-action' ) {
		$GLOBALS['wgHooks']['SetupAfterCache'][] = static function () {
			RequestContext::getMain()->getRequest()->setVal( 'action', 'edit' );
		};
	}
	if ( $scenario === 'oom' ) {
		$GLOBALS['wgHooks']['SetupAfterCache'][] = static function () {
			// Like session cleanup, this runs after the fatal listeners and before the transaction ends.
			register_shutdown_function( static function () {
				trigger_error( 'cleanup warning', E_USER_WARNING );
			} );
		};
	}
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
	case 'error-mask':
		error_reporting( E_ALL );
		trigger_error( 'excluded warning', E_USER_WARNING );
		trigger_error( 'included notice', E_USER_NOTICE );
		MWExceptionHandler::logException( new RuntimeException( 'application exception' ) );
		break;
	case 'oom':
		if ( MW_ENTRY_POINT === 'cli' ) {
			WebRequest::overrideRequestId( 'job-origin' );
		} else {
			echo 'OK';
			ob_end_flush();
			flush();
		}
		// One allocation exceeds this child process's limit, independent of available host memory.
		ini_set( 'memory_limit', (string)( memory_get_usage( true ) + 8 * 1024 * 1024 ) );
		echo strlen( str_repeat( 'x', 16 * 1024 * 1024 ) );
		break;
	case 'job':
		RequestContext::getMain()->setTitle( Title::makeTitle( NS_SPECIAL, 'RunJobs' ) );
		( new HookRunner( MediaWikiServices::getInstance()->getHookContainer() ) )
			->onSpecialPageBeforeExecute( new SpecialPage( 'RunJobs' ), null );
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
		if ( MW_ENTRY_POINT === 'index' ) {
			RequestContext::getMain()->setTitle( Title::makeTitle( NS_MAIN, 'RunJobs' ) );
		}
		@trigger_error( 'suppressed warning', E_USER_WARNING );
		trigger_error( 'recoverable warning', E_USER_WARNING );
		DeferredUpdates::addCallableUpdate( static function () {
			MWExceptionHandler::logException( new RuntimeException( 'deferred error' ) );
			\Sentry\logger()->info( 'deferred log' );
		} );
		DeferredUpdates::doUpdates();
		break;
}
