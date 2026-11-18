<?php

use MediaWiki\Extension\MooncellSentry\Hooks;

if ( !defined( 'MEDIAWIKI' ) ) {
	exit;
}

// Start reporting before MW reads and validates the queued extension manifests.
require_once __DIR__ . '/src/Hooks.php';
Hooks::onRegistration();
wfLoadExtension( 'MooncellSentry' );
