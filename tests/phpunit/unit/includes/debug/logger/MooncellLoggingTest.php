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

	private function loadConfig(): array {
		global $wgDBerrorLog, $wgPropagateErrors, $wgMWLoggerDefaultSpi;

		require MW_INSTALL_PATH . '/LoggingSettings.php';
		$this->assertFalse( $wgDBerrorLog );
		$this->assertFalse( $wgPropagateErrors );
		return $wgMWLoggerDefaultSpi['args'][0];
	}

	private function newSpi( $stream ): MonologSpi {
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
		return new MonologSpi( $config );
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
		$spi->getLogger( 'silenced-error' )->warning( 'suppressed PHP warning' );
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
