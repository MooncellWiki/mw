<?php

use MediaWiki\Logger\Monolog\LogstashFormatter;
use MediaWiki\Logger\Monolog\WikiProcessor;
use MediaWiki\Logger\MonologSpi;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\ProcessIdProcessor;
use Monolog\Processor\PsrLogMessageProcessor;

if ( !defined( 'MEDIAWIKI' ) ) {
	exit;
}

// Three sites share this output. FPM forwards worker stderr to the container log;
// maintenance scripts keep their normal stderr (collected by the host's cron logger).
$wgMWLoggerDefaultSpi = [
	'class' => MonologSpi::class,
	'args' => [ [
		'loggers' => [
			'@default' => [
				'processors' => [ 'wiki', 'pid', 'psr' ],
				'handlers' => [ 'stderr' ],
			],
			// The exception channel already includes the Throwable and its redacted stack.
			'exception-json' => [],
			// Respect @ and error_reporting(), including disabled notice/deprecation levels.
			'silenced-error' => [],
		],
		'processors' => [
			'wiki' => [
				'factory' => static function () {
					$processor = new WikiProcessor();
					return static function ( array $record ) use ( $processor ): array {
						$record = $processor( $record );
						// CLI arguments can contain credentials (e.g. maintenance/install.php).
						unset( $record['extra']['cli_argv'] );
						return $record;
					};
				},
			],
			'pid' => [ 'class' => ProcessIdProcessor::class ],
			'psr' => [ 'class' => PsrLogMessageProcessor::class ],
		],
		'handlers' => [
			'stderr' => [
				'class' => StreamHandler::class,
				'args' => [ 'php://stderr', Logger::WARNING ],
				'formatter' => 'json',
			],
		],
		'formatters' => [
			'json' => [
				// Use MW's formatter: it replaces stack argument values with their types.
				'class' => LogstashFormatter::class,
				'args' => [ 'mediawiki', null, '', 'context_', LogstashFormatter::V1 ],
			],
		],
	] ],
];

// rdbms/wfLogDBError now use the same structured output; true is not a log path.
$wgDBerrorLog = false;
// MW already logs handled PHP errors. Avoid a second, unstructured PHP warning.
// log_errors still covers failures before MW's handler is installed and fatal errors.
$wgPropagateErrors = false;
