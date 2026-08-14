<?php

// Safe local-development defaults. Copy to etc/config.php and adjust with env vars.
$wgSitename = 'PRTS Wiki (local)';
$wgMetaNamespace = 'PRTS_Wiki';
$wgMaxArticleSize = 4096;
$wgTmpDirectory = sys_get_temp_dir();
$wgLogos = [ 'icon' => "$wgResourceBasePath/resources/assets/change-your-logo.svg" ];

$wgServer = getenv( 'PRTS_SERVER' ) ?: 'http://127.0.0.1:8080';
$wgCanonicalServer = $wgServer;
$wgCookieSecure = str_starts_with( $wgServer, 'https://' );
$wgCookiePrefix = 'prts_dev';
$wgCookieDomain = '';

$wgDBserver = getenv( 'PRTS_DB_SERVER' ) ?: '127.0.0.1:3307';
$wgDBname = getenv( 'PRTS_DB_NAME' ) ?: 'ak';
$wgDBuser = getenv( 'PRTS_DB_USER' ) ?: 'akdev';
$wgDBpassword = getenv( 'PRTS_DB_PASSWORD' ) ?: 'akdev';
$wgDBprefix = 'ak';

// Production uses CDN, Memcached and Redis. Local development is deliberately standalone.
$wgMainCacheType = CACHE_NONE;
$wgSessionCacheType = CACHE_DB;

// These values are development-only and must never be reused in production.
$wgSecretKey = getenv( 'PRTS_SECRET_KEY' )
	?: 'local-only-secret-key-change-before-exposing-this-installation';
$wgUpgradeKey = getenv( 'PRTS_UPGRADE_KEY' ) ?: 'local-only-upgrade-key';
$wgAuthenticationTokenVersion = 'local-v1';

define( 'NS_样板', 3002 );
define( 'NS_样板讨论', 3003 );
define( 'NS_泰拉大典', 3000 );
define( 'NS_泰拉大典讨论', 3001 );
$wgExtraNamespaces[NS_样板] = '样板';
$wgExtraNamespaces[NS_样板讨论] = '样板讨论';
$wgExtraNamespaces[NS_泰拉大典] = '泰拉大典';
$wgExtraNamespaces[NS_泰拉大典讨论] = '泰拉大典讨论';
$wgNamespaceProtection[NS_泰拉大典] = [ 'edit-terra' ];
$wgNamespacesToBeSearchedDefault[NS_泰拉大典] = true;
$wgNamespacesToBeSearchedDefault[NS_FILE] = true;

// Shared frontend assets. Analytics and advertising scripts are intentionally omitted.
$wgHeadScriptCode = <<<'START_END_MARKER'
<script>this.globalThis || (this.globalThis = this)</script>
<link rel="dns-prefetch" href="https://static.prts.wiki/">
<link rel="modulepreload" href="https://static.prts.wiki/widgets/production/sentry.l-4LUkec.js" as="script">
<script type="module" crossorigin src="https://static.prts.wiki/widgets/production/sentry.l-4LUkec.js"></script>
<link rel="modulepreload" href="https://static.prts.wiki/widgets/production/DisplayController.js" as="script">
<script type="module" crossorigin src="https://static.prts.wiki/widgets/production/DisplayController.js"></script>
<link rel="manifest" href="/manifest.json">
<link href="https://static.prts.wiki/mdi/7.4.47/css/materialdesignicons.min.css" type="text/css" rel="stylesheet" />
<link href="https://static.prts.wiki/npm/@fortawesome/fontawesome-free@5.15.4/css/all.min.css" type="text/css" rel="stylesheet" />
<link href="https://static.prts.wiki/npm/@fortawesome/fontawesome-free@5.15.4/css/v4-shims.min.css" type="text/css" rel="stylesheet" />
<link href="https://static.prts.wiki/npm/animate.css@3.7.2/animate.min.css" type="text/css" rel="stylesheet" />
<meta name="theme-color" content="#343434">
<link rel="apple-touch-icon" href="/ioslogo.png">
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<script>if('serviceWorker'in navigator){window.addEventListener('load',function(){navigator.serviceWorker.register('/sw.js').then(function(registration){console.log('ServiceWorker registration successful with scope: ',registration.scope)}).catch(function(err){console.log('ServiceWorker registration failed: ',err)})})}</script>
START_END_MARKER;

// Aliyun OSS uses the AWS-compatible backend. Credentials must come from the environment.
$wgAWSCredentials = [
	'key' => getenv( 'PRTS_OSS_ACCESS_KEY_ID' ) ?: '',
	'secret' => getenv( 'PRTS_OSS_ACCESS_KEY_SECRET' ) ?: '',
	'token' => false,
];
$wgAWSRegion = 'oss-cn-hangzhou';
$wgAWSBucketName = 'ak-media';
$wgAWSBucketDomain = 'media.prts.wiki';
$wgAWSRepoHashLevels = '2';
$wgAWSRepoDeletedHashLevels = '3';
$wgFileBackends['s3']['endpoint'] = 'https://oss-accelerate.aliyuncs.com';

$wgEnableUploads = true;
$wgDebugTimestamps = false;
$wgDBerrorLog = true;

if ( getenv( 'PRTS_DEBUG' ) === '1' ) {
	$wgShowExceptionDetails = true;
	$wgShowDBErrorBacktrace = true;
}
