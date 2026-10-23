<?php

namespace MediaWiki\Tests\Logger;

use MediaWiki\Logger\MonologSpi;
use RuntimeException;

/**
 * Exercise the deployed logging configuration without booting a wiki or connecting to a database.
 *
 * @coversNothing
 */
class MooncellLoggingTest extends \MediaWikiUnitTestCase {

	/**
	 * Load the settings in a local scope. Writing the real globals would leak the production logger
	 * into later tests: MediaWikiUnitTestCase restores bootstrap globals by reference.
	 */
	private function loadSettings( string $debugLogFile = '' ): array {
		return ( static function () use ( $debugLogFile ): array {
			// phpcs:disable MediaWiki.VariableAnalysis.MisleadingGlobalNames
			$wgDebugLogFile = $debugLogFile;
			$wgDebugLogGroups = [];
			$wgExtensionFunctions = [];
			// phpcs:enable
			require MW_INSTALL_PATH . '/LoggingSettings.php';
			return get_defined_vars();
		} )();
	}

	private function loadConfig(): array {
		$settings = $this->loadSettings();
		$this->assertFalse( $settings['wgDBerrorLog'] );
		return $settings['wgMWLoggerDefaultSpi']['args'][0];
	}

	private function newSpi( $stream, ?array $serverData = null ): MonologSpi {
		// WikiProcessor deliberately reads only these globals, without using LBFactory.
		$GLOBALS['wgDBname'] = 'logging-test';
		$GLOBALS['wgDBmwschema'] = null;
		$GLOBALS['wgDBprefix'] = '';
		$GLOBALS['wgOverrideHostname'] = 'logging-host';
		$GLOBALS['wgAllowExternalReqID'] = false;
		$GLOBALS['wgShowDebug'] = false;
		$GLOBALS['wgDebugToolbar'] = false;

		$config = $this->loadConfig();
		$config['handlers']['stderr']['args'][0] = $stream;
		$config['processors']['web']['args'][0] = $serverData;
		return new MonologSpi( $config );
	}

	private function readLines( $stream ): array {
		rewind( $stream );
		$output = stream_get_contents( $stream );
		fclose( $stream );
		$this->assertStringEndsWith( "\n", $output );
		return explode( "\n", substr( $output, 0, -1 ) );
	}

	public function testWarningsAndErrorsFromAnyChannelAreStructured(): void {
		$stream = fopen( 'php://memory', 'w+' );
		$spi = $this->newSpi( $stream );
		$spi->getLogger( 'extension-example' )->info( 'routine information' );
		$spi->getLogger( 'extension-example' )->warning( 'Failed {operation}', [
			'operation' => 'thumbnail',
			'elapsed_ms' => 1234,
		] );
		$spi->getLogger( 'rdbms' )->error( 'Database connection failed' );
		$spi->getLogger( 'wfLogDBError' )->error( 'Legacy database error' );

		rewind( $stream );
		$lines = explode( "\n", trim( stream_get_contents( $stream ) ) );
		fclose( $stream );
		$this->assertCount( 3, $lines );
		$records = array_map( static fn ( $line ) => json_decode( $line, true, 512, JSON_THROW_ON_ERROR ), $lines );
		$this->assertSame( [ 'extension-example', 'rdbms', 'wfLogDBError' ], array_column( $records, 'channel' ) );
		$this->assertSame( [ 'WARNING', 'ERROR', 'ERROR' ], array_column( $records, 'level' ) );
		$this->assertSame( 'Failed thumbnail', $records[0]['message'] );
		$this->assertSame( 1234, $records[0]['context_elapsed_ms'] );
		$this->assertSame( 'logging-test', $records[0]['wiki'] );
		$this->assertSame( 'logging-host', $records[0]['host'] );
		$this->assertSame( MW_VERSION, $records[0]['mwversion'] );
		$this->assertSame( getmypid(), $records[0]['process_id'] );
		$this->assertNotEmpty( $records[0]['reqId'] );
		$this->assertSame( $records[0]['reqId'], $records[1]['reqId'] );
		$this->assertArrayHasKey( '@timestamp', $records[0] );
		$this->assertArrayNotHasKey( 'cli_argv', $records[0] );
		$this->assertArrayNotHasKey( 'truncated', $records[0] );
	}

	public function testRecordsIncludeTheRequestButNotTheClientIp(): void {
		$stream = fopen( 'php://memory', 'w+' );
		$spi = $this->newSpi( $stream, [
			'REQUEST_URI' => '/api.php?action=parse',
			'REQUEST_METHOD' => 'POST',
			'SERVER_NAME' => 'prts.wiki',
			'REMOTE_ADDR' => '203.0.113.7',
			'HTTP_REFERER' => 'https://prts.wiki/w/Example',
		] );
		$spi->getLogger( 'DBPerformance' )->warning( 'Expectation not met' );

		$record = json_decode( $this->readLines( $stream )[0], true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( '/api.php?action=parse', $record['url'] );
		$this->assertSame( 'POST', $record['http_method'] );
		$this->assertSame( 'prts.wiki', $record['server'] );
		$this->assertArrayNotHasKey( 'ip', $record );
		$this->assertArrayNotHasKey( 'referrer', $record );
	}

	public function testOversizedRecordsStayOnOneJsonLine(): void {
		$stream = fopen( 'php://memory', 'w+' );
		$spi = $this->newSpi( $stream );
		$spi->getLogger( 'exception' )->error( 'Deep recursion', [
			'exception' => new RuntimeException( str_repeat( 'frame ', 20000 ) ),
		] );
		$spi->getLogger( 'extension-example' )->warning( 'Many fields',
			array_fill_keys( range( 1, 800 ), str_repeat( 'x', 1000 ) ) );

		$lines = $this->readLines( $stream );
		$this->assertCount( 2, $lines );
		foreach ( $lines as $line ) {
			$this->assertLessThan( 60000, strlen( $line ) );
		}
		$records = array_map( static fn ( $line ) => json_decode( $line, true, 512, JSON_THROW_ON_ERROR ), $lines );
		$this->assertSame( [ true, true ], array_column( $records, 'truncated' ) );
		$this->assertSame( [ 'Deep recursion', 'Many fields' ], array_column( $records, 'message' ) );
		$this->assertStringStartsWith( 'frame frame', $records[0]['context_exception']['message'] );
		$this->assertSame( 'logging-test', $records[1]['wiki'] );
		$this->assertArrayNotHasKey( 'context_1', $records[1] );
	}

	public function testExceptionsKeepRedactedStacksWithoutDuplicateOrSilencedEvents(): void {
		$oldIgnoreArgs = ini_set( 'zend.exception_ignore_args', '0' );
		try {
			$exception = ( static function ( $privateArgument ) {
				return new RuntimeException( "Rendering failed\nwith a multiline message", 0,
					new RuntimeException( 'Underlying failure' ) );
			} )( 'do-not-log-this-argument' );
		} finally {
			ini_set( 'zend.exception_ignore_args', $oldIgnoreArgs );
		}
		// Prove the fixture really contains argument values before the formatter runs.
		$this->assertSame( 'do-not-log-this-argument', $exception->getTrace()[0]['args'][0] );

		$stream = fopen( 'php://memory', 'w+' );
		$spi = $this->newSpi( $stream );
		$spi->getLogger( 'exception' )->error( $exception->getMessage(), [ 'exception' => $exception ] );
		$spi->getLogger( 'exception-json' )->error( 'duplicate exception' );
		// MWExceptionHandler logs @-suppressed errors at DEBUG.
		$spi->getLogger( 'silenced-error' )->debug( 'suppressed PHP warning' );
		rewind( $stream );
		$output = stream_get_contents( $stream );
		fclose( $stream );

		$this->assertSame( 1, substr_count( $output, "\n" ) );
		$this->assertStringNotContainsString( 'do-not-log-this-argument', $output );
		$this->assertStringNotContainsString( 'duplicate exception', $output );
		$this->assertStringNotContainsString( 'suppressed PHP warning', $output );
		$record = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( RuntimeException::class, $record['context_exception']['class'] );
		$this->assertSame( $exception->getMessage(), $record['context_exception']['message'] );
		$this->assertStringContainsString( __FILE__, $record['context_exception']['trace'] );
		$this->assertSame( 'Underlying failure', $record['context_exception']['previous']['message'] );
	}

	public function testPhpStopsLoggingErrorsOnceMediaWikiHandlesThem(): void {
		$settings = $this->loadSettings();
		// $wgPropagateErrors must stay true so that PHP still fills error_get_last().
		$this->assertArrayNotHasKey( 'wgPropagateErrors', $settings );
		$this->assertCount( 1, $settings['wgExtensionFunctions'] );
		$oldLogErrors = ini_get( 'log_errors' );
		try {
			$settings['wgExtensionFunctions'][0]();
			$this->assertSame( '0', ini_get( 'log_errors' ) );
		} finally {
			ini_set( 'log_errors', $oldLogErrors );
		}
	}

	public function testDebugLogFileKeepsTheLegacyLogger(): void {
		$settings = $this->loadSettings( '/tmp/debug.log' );
		$this->assertArrayNotHasKey( 'wgMWLoggerDefaultSpi', $settings );
		$this->assertSame( [], $settings['wgExtensionFunctions'] );
		$this->assertFalse( $settings['wgDBerrorLog'] );
	}

	public function testPhpConfigurationKeepsWarningsAndNativeErrorLogging(): void {
		$config = parse_ini_file( MW_INSTALL_PATH . '/docker/php/config/php.ini' );
		$mask = (int)$config['error_reporting'];
		$this->assertSame( E_WARNING, $mask & E_WARNING );
		$this->assertSame( E_ERROR, $mask & E_ERROR );
		$this->assertSame( 0, $mask & ( E_NOTICE | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED ) );
		$this->assertSame( '1', $config['log_errors'] );
		$this->assertSame( '', $config['display_errors'] );
	}
}
