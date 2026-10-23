<?php

use MediaWiki\Logger\Monolog\LogstashFormatter;
use MediaWiki\Logger\Monolog\WikiProcessor;
use MediaWiki\Logger\MonologSpi;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\ProcessIdProcessor;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Processor\WebProcessor;

if ( !defined( 'MEDIAWIKI' ) ) {
	exit;
}

// rdbms/wfLogDBError now use the same structured output; true is not a log path.
$wgDBerrorLog = false;

// Only the default LegacySpi reads these. Keep them working as a debugging switch.
if ( $wgDebugLogFile || $wgDebugLogGroups ) {
	return;
}

// Three sites share this output. FPM forwards worker stderr to the container log;
// maintenance scripts keep their normal stderr (collected by the host's cron logger).
$wgMWLoggerDefaultSpi = [
	'class' => MonologSpi::class,
	'args' => [ [
		'loggers' => [
			'@default' => [
				'processors' => [ 'wiki', 'web', 'pid', 'psr' ],
				'handlers' => [ 'stderr' ],
			],
			// The exception channel already includes the Throwable and its redacted stack.
			'exception-json' => [],
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
			// Lets records from any channel be traced back to a request. Leaves out the client IP.
			'web' => [
				'class' => WebProcessor::class,
				'args' => [ null, [ 'url', 'http_method', 'server' ] ],
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
				'factory' => static function () {
					return new class( 'mediawiki', null, '', 'context_', LogstashFormatter::V1 )
						extends LogstashFormatter
					{
						// Below log_limit in php-fpm.conf: FPM splits longer lines and neither part is valid JSON.
						private const MAX_BYTES = 60000;

						public function format( array $record ): string {
							$line = parent::format( $record );
							if ( strlen( $line ) <= self::MAX_BYTES ) {
								return $line;
							}
							// Shorten the longest strings (usually stack traces) until the record fits.
							$data = json_decode( $line, true );
							$data['truncated'] = true;
							for ( $limit = 16384; $limit >= 128; $limit = intdiv( $limit, 2 ) ) {
								array_walk_recursive( $data, static function ( &$value ) use ( $limit ) {
									if ( is_string( $value ) && strlen( $value ) > $limit ) {
										$value = mb_strcut( $value, 0, $limit ) . '...';
									}
								} );
								$line = $this->toJson( $data ) . "\n";
								if ( strlen( $line ) <= self::MAX_BYTES ) {
									return $line;
								}
							}
							// Still too many fields: drop the context and keep the record's own fields.
							return $this->toJson( array_filter( $data, static function ( $value, $key ) {
								return is_scalar( $value ) && !str_starts_with( $key, 'context_' );
							}, ARRAY_FILTER_USE_BOTH ) ) . "\n";
						}
					};
				},
			],
		],
	] ],
];

// MW already logs handled PHP errors, so stop PHP's second, unstructured copy (fatal errors included:
// MW logs those on shutdown). Extension functions run after MW installs its handlers, so log_errors
// still covers earlier startup failures; ini_set() only lasts for the current FPM request.
// Not $wgPropagateErrors = false: PHP would then stop filling error_get_last(), which Shellbox and
// ParamValidator read after @-suppressed calls.
$wgExtensionFunctions[] = static function () {
	ini_set( 'log_errors', '0' );
};
