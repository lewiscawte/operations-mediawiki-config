<?php
# WARNING: This file is publicly viewable on the web. Do not put private data here.

// phpcs:disable MediaWiki.Classes.UnsortedUseStatements.UnsortedUse
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

wfLoadExtension( 'LdapAuthentication' );
$wgAuthManagerAutoConfig['primaryauth'] += [
	LdapPrimaryAuthenticationProvider::class => [
		'class' => LdapPrimaryAuthenticationProvider::class,
		'args' => [ [
			'authoritative' => true, // don't allow local non-LDAP accounts
		] ],
		'sort' => 50, // must be smaller than local pw provider
	],
];
$wgLDAPDomainNames = [ 'labs' ];
switch ( $wgDBname ) {
case 'labswiki' :
	$wgLDAPServerNames = [ 'labs' => 'ldap-labs.eqiad.wikimedia.org' ];
		break;
case 'labtestwiki' :
	$wgLDAPServerNames = [ 'labs' => 'cloudservices2002-dev.wikimedia.org' ];
		break;
}
// T165795: require exact case matching of username via :caseExactMatch:
$wgLDAPSearchAttributes = [ 'labs' => 'cn:caseExactMatch:' ];
$wgLDAPBaseDNs = [ 'labs' => 'dc=wikimedia,dc=org' ];
$wgLDAPUserBaseDNs = [ 'labs' => 'ou=people,dc=wikimedia,dc=org' ];
$wgLDAPEncryptionType = [ 'labs' => 'tls' ];
$wgLDAPWriteLocation = [ 'labs' => 'ou=people,dc=wikimedia,dc=org' ];
$wgLDAPAddLDAPUsers = [ 'labs' => true ];
$wgLDAPUpdateLDAP = [ 'labs' => true ];
$wgLDAPPasswordHash = [ 'labs' => 'clear' ];
// 'invaliddomain' is set to true so that mail password options
// will be available on user creation and password mailing
// Force strict mode. T218589
// $wgLDAPMailPassword = [ 'labs' => true, 'invaliddomain' => true ];
$wgLDAPPreferences = [ 'labs' => [ "email" => "mail" ] ];
$wgLDAPUseFetchedUsername = [ 'labs' => true ];
$wgLDAPLowerCaseUsernameScheme = [ 'labs' => false, 'invaliddomain' => false ];
$wgLDAPLowerCaseUsername = [ 'labs' => false, 'invaliddomain' => false ];
// Only enable UseLocal if you need to promote an LDAP user
// $wgLDAPUseLocal = true;
// T168692: Attempt to lock LDAP accounts when blocked
$wgLDAPLockOnBlock = true;
$wgLDAPLockPasswordPolicy = 'cn=disabled,ou=ppolicies,dc=wikimedia,dc=org';

// Local debug logging for troubleshooting LDAP issues
// phpcs:disable Generic.CodeAnalysis.UnconditionalIfStatement.Found
if ( false ) {
	$wgLDAPDebug = 5;
	$monolog = LoggerFactory::getProvider();
	$monolog->mergeConfig( [
		'loggers' => [
			'ldap' => [
				'handlers' => [ 'wikitech-ldap' ],
				'processors' => array_keys( $wmgMonologProcessors ),
			],
		],
		'handlers' => [
			'wikitech-ldap' => [
				'class' => '\\Monolog\\Handler\\StreamHandler',
				'args' => [ '/tmp/ldap-s-1-debug.log' ],
				'formatter' => 'line',
			],
		],
	] );
}

wfLoadExtension( 'OpenStackManager' );

// Dummy setting for conduit api token to be used by the BlockIpComplete hook
// that tries to disable Phabricator accounts. Real value should be provided
// by /etc/mediawiki/WikitechPrivateSettings.php
$wmfPhabricatorApiToken = false;

// Dummy settings for Gerrit api access to be used by the BlockIpComplete hook
// that tries to disable Gerrit accounts. Real values should be provided by
// /etc/mediawiki/WikitechPrivateSettings.php
$wmfGerritApiUser = false;
$wmfGerritApiPassword = false;

# This must be loaded AFTER OSM, to overwrite it's defaults
# Except when we're not an OSM host and we're running like a maintenance script.
if ( file_exists( '/etc/mediawiki/WikitechPrivateSettings.php' ) ) {
	require_once '/etc/mediawiki/WikitechPrivateSettings.php';
}

# wgCdnReboundPurgeDelay is set to 11 in reverse-proxy.php but
# since we aren't using the shared jobqueue, we don't support delays
$wgCdnReboundPurgeDelay = 0;

# Wikitech on labweb is behind the misc-web varnishes so we need a different
# multicast IP for cache invalidation.  This file is loaded
# after the standard MW equivalent (in reverse-proxy.php)
# so we can just override it here.
$wgHTCPRouting = [
	'' => [
		'host' => '239.128.0.115',
		'port' => 4827
	]
];

// T218654
$wgHooks['BlockIpComplete'][] = function ( $block, $performer, $priorBlock ) {
	global $wgBlockDisablesLogin;
	if ( $wgBlockDisablesLogin && $block->getTarget() instanceof User && $block->getExpiry() === 'infinity' && $block->isSitewide() ) {
		MediaWikiServices::getInstance()->getAuthManager()
			->revokeAccessForUser( $block->getTarget()->getName() );
	}
};

// Attempt to disable related accounts when a developer account is
// permablocked.
$wgHooks['BlockIpComplete'][] = function ( $block, $user, $prior ) use ( $wmfPhabricatorApiToken ) {
	if ( !$wmfPhabricatorApiToken
		|| $block->getType() !== /* Block::TYPE_USER */ 1
		|| $block->getExpiry() !== 'infinity'
		|| !$block->isSitewide()
	) {
		// Nothing to do if we don't have config or if the block is not
		// a site-wide indefinite block of a named user.
		return;
	}
	try {
		// Lookup and block phab user tied to developer account
		$phabClient = function ( $path, $query ) use ( $wmfPhabricatorApiToken ) {
			$query['__conduit__'] = [ 'token' => $wmfPhabricatorApiToken ];
			$post = [
				'params' => json_encode( $query ),
				'output' => 'json',
			];
			$phabUrl = 'https://phabricator.wikimedia.org';
			$ch = curl_init( "{$phabUrl}/api/{$path}" );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $post );
			$ret = curl_exec( $ch );
			curl_close( $ch );
			if ( $ret ) {
				$resp = json_decode( $ret, true );
				if ( !$resp['error_code'] ) {
					return $resp['result'];
				}
			}
			wfDebugLog(
				'WikitechPhabBan',
				"Phab {$path} error " . var_export( $ret, true )
			);
			return false;
		};

		$username = $block->getTarget()->getName();
		$resp = $phabClient( 'user.ldapquery', [
			'ldapnames' => [ $username ],
			'offset' => 0,
			'limit' => 1,
		] );
		if ( $resp ) {
			$phid = $resp[0]['phid'];
			$phabClient( 'user.disable', [
				'phids' => [ $phid ],
			] );
		}
	} catch ( Throwable $t ) {
		wfDebugLog(
			'WikitechPhabBan',
			"Unhandled error blocking Phabricator user: {$t}"
		);
	}
};
$wgHooks['BlockIpComplete'][] = function ( $block, $user, $prior ) use ( $wmfGerritApiUser, $wmfGerritApiPassword ) {
	if ( !$wmfGerritApiUser
		|| !$wmfGerritApiPassword
		|| $block->getType() !== /* Block::TYPE_USER */ 1
		|| $block->getExpiry() !== 'infinity'
		|| !$block->isSitewide()
	) {
		// Nothing to do if we don't have config or if the block is not
		// a site-wide indefinite block of a named user.
		return;
	}
	try {
		// Disable gerrit user tied to developer account
		$gerritUrl = 'https://gerrit.wikimedia.org';
		$username = strtolower( $block->getTarget()->getName() );
		$ch = curl_init(
			"{$gerritUrl}/r/a/accounts/" . urlencode( $username ) . '/active'
		);
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'DELETE' );
		curl_setopt(
			$ch, CURLOPT_USERPWD,
			"{$wmfGerritApiUser}:{$wmfGerritApiPassword}"
		);
		if ( !curl_exec( $ch ) ) {
			wfDebugLog(
				'WikitechGerritBan',
				"Gerrit block of {$username} failed: " . curl_error( $ch )
			);
		} else {
			$status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			if ( $status !== 204 ) {
				wfDebugLog(
					'WikitechGerritBan',
					"Gerrit block of {$username} failed with status {$status}"
				);
			}
		}
		curl_close( $ch );
	} catch ( Throwable $t ) {
		wfDebugLog(
			'WikitechGerritBan',
			"Unhandled error blocking Gerrit user: {$t}"
		);
	}
};

/**
 * Invalidates sessions of a blocked user and therefore logs them out.
 */
$wgHooks['BlockIpComplete'][] = function ( $block, $user, $prior ) {
	if ( $block->getType() !== /* Block::TYPE_USER */ 1
		|| $block->getExpiry() !== 'infinity'
		|| !$block->isSitewide()
	) {
		// Nothing to do if we don't have config or if the block is not
		// a site-wide indefinite block of a named user.
		return;
	}
	$blockedUser = $block->getTarget();
	if ( !( $blockedUser instanceof User ) ) {
		return;
	}
	MediaWiki\Session\SessionManager::singleton()->invalidateSessionsForUser( $blockedUser );
};
