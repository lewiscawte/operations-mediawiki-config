<?php
# WARNING: This file is publically viewable on the web. Do not put private data here.

# This file holds the MediaWiki CirrusSearch configuration which is specific
# to the 'production' realm.
# It should be loaded AFTER CirrusSearch-common.php

// Our cluster often has issues completing master actions
// within the default 30s timeout. Upgrade that to 2m to
// help things along.
$wgCirrusSearchMasterTimeout = '2m';

$wgCirrusSearchClusters = [
	'eqiad' => $wmfAllServices['eqiad']['search'],
	'codfw' => $wmfAllServices['codfw']['search'],
];
if ( defined( 'HHVM_VERSION' ) ) {
	$wgCirrusSearchClusters['eqiad'] = array_map( function ( $host ) {
		return [
			'transport' => 'CirrusSearch\\Elastica\\PooledHttps',
			'port' => '9243',
			'host' => $host,
			'config' => [
				'pool' => 'cirrus-eqiad',
			],
		];
	}, $wgCirrusSearchClusters['eqiad'] );
	$wgCirrusSearchClusters['codfw'] = array_map( function ( $host ) {
		return [
			'transport' => 'CirrusSearch\\Elastica\\PooledHttps',
			'port' => '9243',
			'host' => $host,
			'config' => [
				'pool' => 'cirrus-codfw',
			],
		];
	}, $wgCirrusSearchClusters['codfw'] );
}

if ( $wgDBname === 'labswiki' || $wgDBname === 'labtestwiki' ) {
	$wgCirrusSearchClusters = [
		'eqiad' => $wmfAllServices['eqiad']['search'],
		'codfw' => $wmfAllServices['codfw']['search'],
	];
}

$wgCirrusSearchConnectionAttempts = 3;

$wgCirrusSearchBackup['backups'] = [
	'type' => 'swift',
	'swift_url' => $wmfSwiftEqiadConfig['cirrusAuthUrl'],
	'swift_container' => 'global-data-elastic-backups',
	'swift_username' => $wmfSwiftEqiadConfig['cirrusUser'],
	'swift_password' => $wmfSwiftEqiadConfig['cirrusKey'],
	'max_snapshot_bytes_per_sec' => '10mb',
	'compress' => false,
	'chunk_size' => '1g',
];

$wgCirrusSearchInterwikiCacheTime = 60;

if ( $wgDBname == 'enwiki' ) {
	$wgCirrusSearchPoolCounterKey .= '_enwiki';
}

$wgCirrusSearchEnableSearchLogging = true;

// The default configuration is a single-cluster configuration, expand
// that here into the necessary multi-cluster config
$wgCirrusSearchShardCount = [
	'eqiad' => $wmgCirrusSearchShardCount,
	'codfw' => $wmgCirrusSearchShardCount,
];

if ( ! isset( $wmgCirrusSearchReplicas['eqiad'] ) ) {
	$wgCirrusSearchReplicas = [
		'eqiad' => $wmgCirrusSearchReplicas,
		'codfw' => $wmgCirrusSearchReplicas,
	];
}

// 5 second timeout for local cluster, 10 seconds for remote.
$wgCirrusSearchClientSideConnectTimeout = [
	'eqiad' => $wmfDatacenter === 'eqiad' ? 5 : 10,
	'codfw' => $wmfDatacenter === 'codfw' ? 5 : 10,
];

$wgCirrusSearchDropDelayedJobsAfter = [
	'eqiad' => $wgCirrusSearchDropDelayedJobsAfter,
	'codfw' => $wgCirrusSearchDropDelayedJobsAfter,
];

$wgCirrusSearchRecycleCompletionSuggesterIndex = $wmgCirrusSearchRecycleCompletionSuggesterIndex;

// cache morelike queries to ObjectCache for 24 hours
$wgCirrusSearchMoreLikeThisTTL = 86400;

// This was causing race conditions and is a temporary fix. A better fix is coming soon (T133793)
$wgCirrusSearchCreateFrozenIndex = false;

// Index deletes into archive
$wgCirrusSearchIndexDeletes = $wmgCirrusSearchIndexDeletes;
// Enable searching archive
$wgCirrusSearchEnableArchive = $wmgCirrusSearchEnableArchive;

// LTR Rescore profile
$wgCirrusSearchRescoreProfiles['enwiki-500t-v1-20rs'] = [
	'i18n_msg' => 'cirrussearch-qi-profile-wsum-inclinks-pv',
	'supported_namespaces' => 'content',
	'unsupported_syntax' => [ 'full_text_querystring', 'query_string', 'filter_only' ],
	'fallback_profile' => 'wsum_inclinks_pv',
	'rescore' => [
		[
			'window' => 8192,
			'window_size_override' => 'CirrusSearchFunctionRescoreWindowSize',
			'query_weight' => 1.0,
			'rescore_query_weight' => 1.0,
			'score_mode' => 'total',
			'type' => 'function_score',
			'function_chain' => 'wsum_inclinks_pv'
		],
		[
			'window' => 8192,
			'window_size_override' => 'CirrusSearchFunctionRescoreWindowSize',
			'query_weight' => 1.0,
			'rescore_query_weight' => 1.0,
			'score_mode' => 'multiply',
			'type' => 'function_score',
			'function_chain' => 'optional_chain'
		],
		[
			'window' => 20,
			'query_weight' => 1.0,
			'rescore_query_weight' => 10000.0,
			'score_mode' => 'total',
			'type' => 'ltr',
			'model' => 'enwiki_32153k_500t',
		],
	],
];

$wgCirrusSearchRescoreProfiles['enwiki-500t-v1-1024rs'] = $wgCirrusSearchRescoreProfiles['enwiki-500t-v1-20rs'];
$wgCirrusSearchRescoreProfiles['enwiki-500t-v1-1024rs']['rescore'][2]['window'] = 1024;
