<?php

namespace MediaWiki\Extension\MooncellSentry;

use MediaWiki\Request\WebRequest;
use Sentry\State\HubInterface;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Throwable;

/** Owns the Sentry scope/transaction until PHP shutdown, after MediaWiki's post-send work. */
final class RequestTrace {
	private HubInterface $hub;
	private Transaction $transaction;
	private bool $finished = false;

	public function __construct( HubInterface $hub, string $entryPoint, WebRequest $request ) {
		$this->hub = $hub;
		$scope = $hub->pushScope();
		try {
			$this->transaction = $hub->startTransaction(
				TransactionContext::make()->setName( $entryPoint )->setOp( 'http.server' )
			);
			// Also bind unsampled transactions: errors/logs still need a consistent trace ID.
			$hub->setSpan( $this->transaction );
			$scope->setTag( 'mw_request_id', WebRequest::getRequestId() );
			$this->transaction->setData( [
				'http.request.method' => $request->getMethod(),
				'mw.action' => $request->getText( 'action', 'view' ),
			] );
		} catch ( Throwable $e ) {
			$hub->popScope();
			throw $e;
		}
	}

	public function markError(): void {
		$this->transaction->setStatus( SpanStatus::internalError() );
	}

	public function finish(): void {
		if ( $this->finished ) {
			return;
		}
		$this->finished = true;
		try {
			// The SDK stops profiling and attaches the profile with this trace/transaction ID.
			$this->transaction->finish();
		} finally {
			$this->hub->popScope();
		}
	}
}
