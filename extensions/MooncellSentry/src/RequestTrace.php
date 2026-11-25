<?php

namespace MediaWiki\Extension\MooncellSentry;

use MediaWiki\Request\WebRequest;
use Sentry\Event;
use Sentry\EventType;
use Sentry\State\HubInterface;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Throwable;

/** Owns the Sentry scope/transaction until PHP shutdown, after MediaWiki's post-send work. */
final class RequestTrace {
	private const DEFAULT_ACTIONS = [ 'index' => 'view', 'api' => 'help' ];
	private HubInterface $hub;
	private Transaction $transaction;
	private bool $finished = false;

	public function __construct( HubInterface $hub, string $entryPoint, WebRequest $request ) {
		$this->hub = $hub;
		$scope = $hub->pushScope();
		try {
			$context = TransactionContext::make()->setName( $entryPoint )->setOp( 'http.server' );
			$parent = $scope->getSpan();
			if ( $parent !== null ) {
				$context->setTraceId( $parent->getTraceId() )->setParentSpanId( $parent->getSpanId() );
			} else {
				// Bootstrap errors and logs already reference this scope's trace and span.
				$propagation = $scope->getPropagationContext();
				$context->setTraceId( $propagation->getTraceId() )
					->setSpanId( $propagation->getSpanId() )
					->setParentSpanId( $propagation->getParentSpanId() );
			}
			$this->transaction = $hub->startTransaction( $context );
			// Also bind unsampled transactions: errors/logs still need a consistent trace ID.
			$hub->setSpan( $this->transaction );
			$requestId = WebRequest::getRequestId();
			$scope->setTag( 'mw_request_id', $requestId );
			$scope->addEventProcessor( static function ( Event $event ) use ( $requestId ): Event {
				// Special:RunJobs temporarily adopts the originating request's ID. The legacy
				// site hook can also write that ID onto its child scope.
				$currentId = WebRequest::getRequestId();
				if ( $event->getType() === EventType::event() && $currentId !== $requestId ) {
					$event->setTag( 'mw_request_id', $requestId );
					$event->setTag( 'mw_job_request_id', $currentId );
				}
				return $event;
			} );
			$data = [ 'http.request.method' => $request->getMethod() ];
			if ( isset( self::DEFAULT_ACTIONS[$entryPoint] ) ) {
				$data['mw.action'] = $request->getText( 'action', self::DEFAULT_ACTIONS[$entryPoint] );
			}
			$this->transaction->setData( $data );
		} catch ( Throwable $e ) {
			$hub->popScope();
			throw $e;
		}
	}

	public function markRunJobs(): void {
		$this->transaction->setTags( [ 'mw.special' => 'RunJobs' ] );
	}

	public function finish( int $httpStatus = 200, int $shutdownErrorType = 0 ): void {
		if ( $this->finished ) {
			return;
		}
		$this->finished = true;
		try {
			$errorTypes = $shutdownErrorType | ( error_get_last()['type'] ?? 0 );
			$this->transaction->setHttpStatus( $httpStatus );
			if ( $errorTypes & ( E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR |
				E_USER_ERROR | E_RECOVERABLE_ERROR ) ) {
				// PHP can terminate after a successful response has already been sent.
				$this->transaction->setStatus( SpanStatus::internalError() );
			}
			// The SDK stops profiling and attaches the profile with this trace/transaction ID.
			$this->transaction->finish();
		} finally {
			try {
				// Flush with the request scope still active, including logs from transaction callbacks.
				\Sentry\logger()->flush();
			} finally {
				$this->hub->popScope();
			}
		}
	}
}
