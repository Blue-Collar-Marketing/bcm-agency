<?php
/**
 * BCM Agency Tools 1.4: hub signed-request tests.
 *
 *     php tests/hub/run.php
 *
 * Copies the plugin to a temporary folder with freshly generated TEST keys in
 * hub-keys.php (the real keys are never needed), loads it on stubbed WordPress
 * and sends signed requests through the REST permission check and handler.
 * If Node.js is available, also checks that requests signed by the JavaScript
 * reference signer (tests/hub/sign.mjs, the hub's own code) verify here.
 */

$repo   = dirname( __DIR__, 2 );
$tmp    = sys_get_temp_dir() . '/bcm-hub-test-' . getmypid();
$plugin = $tmp . '/bcm-agency';
@mkdir( $tmp . '/wproot/wp-admin/includes', 0777, true );
exec( 'cp -R ' . escapeshellarg( $repo . '/bcm-agency' ) . ' ' . escapeshellarg( $plugin ) );
register_shutdown_function( function () use ( $tmp ) { exec( 'rm -rf ' . escapeshellarg( $tmp ) ); } );

// Test keys.
function b64url( $bin ) { return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' ); }
$kp = array();
foreach ( array( 'hub-1', 'hub-2', 'approve-1', 'rogue' ) as $id ) {
	$pair       = sodium_crypto_sign_keypair();
	$kp[ $id ]  = array(
		'secret' => sodium_crypto_sign_secretkey( $pair ),
		'public' => sodium_crypto_sign_publickey( $pair ),
	);
}
$node_keys = null;
$node      = trim( (string) shell_exec( 'command -v node' ) );
if ( $node ) {
	$node_keys = json_decode( shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/sign.mjs' ) . ' keygen' ), true );
}
$keys_php = "<?php\ndefined( 'ABSPATH' ) || exit;\nreturn array(\n\t'signing' => array(\n"
	. "\t\t'hub-1' => '" . b64url( $kp['hub-1']['public'] ) . "',\n"
	. "\t\t'hub-2' => '" . b64url( $kp['hub-2']['public'] ) . "',\n"
	. ( $node_keys ? "\t\t'hub-js' => '" . $node_keys['public'] . "',\n" : '' )
	. "\t\t'bad-placeholder' => 'REPLACE_ME',\n"
	. "\t),\n\t'approval' => array(\n"
	. "\t\t'approve-1' => '" . b64url( $kp['approve-1']['public'] ) . "',\n"
	. "\t),\n);\n";
file_put_contents( $plugin . '/includes/hub-keys.php', $keys_php );
file_put_contents( $tmp . '/wproot/wp-admin/includes/upgrade.php', '<?php function dbDelta( $sql ) { global $wpdb; if ( false !== strpos( $sql, "bcm_agency_hub_nonces" ) ) { $wpdb->nonce_table_exists = true; } $wpdb->ddl[] = $sql; }' );

define( 'ABSPATH', $tmp . '/wproot/' );
define( 'BCM_AGENCY_UPDATE_URL', '' );
require __DIR__ . '/stubs.php';
require $plugin . '/bcm-agency.php';

do_action( 'plugins_loaded' );
do_action( 'wp_abilities_api_categories_init' );
do_action( 'wp_abilities_api_init' );
do_action( 'rest_api_init' );

/* Fake abilities that report what they ran as -------------------------- */
$probe = function () {
	return array(
		'user'            => get_current_user_id(),
		'kses_on'         => $GLOBALS['__kses_on'],
		'unfiltered_html' => current_user_can( 'unfiltered_html' ),
		'install_plugins' => current_user_can( 'install_plugins' ),
		'create_users'    => current_user_can( 'create_users' ),
		'list_users'      => current_user_can( 'list_users' ),
		'manage_options'  => current_user_can( 'manage_options' ),
		'update_plugins'  => current_user_can( 'update_plugins' ),
	);
};
$obj = array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'approved' => array( 'type' => 'boolean' ) ), 'additionalProperties' => true );
bcm_agency_register( 'bcm/get-post', array( 'label' => 'Get', 'description' => 'd', 'input_schema' => $obj, 'execute_callback' => $probe, 'permission_callback' => function () { return current_user_can( 'edit_posts' ); } ), true );
bcm_agency_register(
	'bcm/update-post',
	array(
		'label'               => 'Update',
		'description'         => 'd',
		'input_schema'        => $obj,
		'execute_callback'    => function ( $in ) use ( $probe ) {
			if ( empty( $in['approved'] ) ) {
				return new WP_Error( 'bcm_needs_approval', 'Confirm with the person, then call again with approved: true.' );
			}
			return array( 'id' => 5, 'probe' => $probe(), '_log' => array( 'before' => 'Old', 'after' => 'New' ) );
		},
		'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
	)
);
bcm_agency_register( 'bcm/update-form-email', array( 'label' => 'Form', 'description' => 'd', 'input_schema' => $obj, 'execute_callback' => function () { return array( 'changed' => true ); }, 'permission_callback' => function () { return current_user_can( 'edit_pages' ); } ) );
$reg = function ( $name, $readonly, $schema = true ) use ( $probe, $obj ) {
	$args = array(
		'label'               => $name,
		'description'         => 'd',
		'category'            => 'x',
		'execute_callback'    => function ( $in = null ) use ( $probe ) { return array( 'ran' => true, 'probe' => $probe() ); },
		'permission_callback' => function () { return current_user_can( 'edit_pages' ); },
		'meta'                => array( 'annotations' => array( 'readonly' => $readonly ) ),
	);
	if ( $schema ) {
		$args['input_schema'] = $obj;
	}
	wp_register_ability( $name, $args );
};
$reg( 'elementor/manage-elements', false );
bcm_agency_register( 'bcm/add-tracking-code', array( 'label' => 'Add code', 'description' => 'd', 'input_schema' => $obj, 'execute_callback' => function ( $in ) { return array( 'added' => isset( $in['service'] ) ? $in['service'] : '' ); }, 'permission_callback' => function () { return current_user_can( 'manage_options' ); } ) );
bcm_agency_register( 'bcm/remove-tracking-code', array( 'label' => 'Remove code', 'description' => 'd', 'input_schema' => $obj, 'execute_callback' => function () { return array( 'removed' => true ); }, 'permission_callback' => function () { return current_user_can( 'manage_options' ); } ) );
$reg( 'elementor/manage-site-parts', false );
$reg( 'elementor/list-form-submissions', true );
$reg( 'elementor/manage-experiments', false );
$reg( 'elementor/get-page-structure', true, false ); // No input schema at all.
$reg( 'elementor/new-thing', false );
$reg( 'elementor/new-read', true );
$reg( 'core/get-site-info', true );
$reg( 'yoast-seo/update-post-seo-data', false );
// Elementor-style reference documents: MCP resources with no readonly annotation.
$res = function ( $name, $annotations ) {
	wp_register_ability( $name, array( 'label' => $name, 'description' => 'd', 'category' => 'x', 'input_schema' => array( 'type' => 'object', 'default' => array() ), 'execute_callback' => function () { return 'reference text'; }, 'permission_callback' => function () { return current_user_can( 'edit_pages' ); }, 'meta' => array( 'annotations' => $annotations, 'mcp' => array( 'type' => 'resource', 'uri' => 'elementor://x' ) ) ) );
};
$res( 'elementor/style-best-practices', array( 'readonly' => null ) );
$res( 'elementor/resource-that-writes', array( 'readonly' => false ) );

/* Request helpers ------------------------------------------------------- */
function hub_request( array $o = array() ) {
	global $kp;
	$host   = isset( $o['host'] ) ? $o['host'] : 'kozyhomes.ca';
	$key_id = isset( $o['key_id'] ) ? $o['key_id'] : 'hub-1';
	$secret = isset( $o['secret'] ) ? $o['secret'] : $kp[ isset( $kp[ $key_id ] ) ? $key_id : 'hub-1' ]['secret'];
	$time   = isset( $o['time'] ) ? (string) $o['time'] : (string) time();
	$nonce  = isset( $o['nonce'] ) ? $o['nonce'] : b64url( random_bytes( 24 ) );
	$actor  = isset( $o['actor'] ) ? $o['actor'] : 'a7f3';
	$body   = isset( $o['raw_body'] ) ? $o['raw_body'] : json_encode( isset( $o['body'] ) ? $o['body'] : array( 'action' => 'ping' ) );
	$route  = '/bcm-agency/v1/hub';
	$msg    = BCM_Hub::message( 'POST', $host, $route, $key_id, $time, $nonce, $actor, hash( 'sha256', $body ) );
	$sig    = b64url( sodium_crypto_sign_detached( $msg, $secret ) );

	$req = new WP_REST_Request( 'POST', $route );
	$req->set_header( 'Host', isset( $o['send_host'] ) ? $o['send_host'] : $host );
	$headers = array(
		'X-BCM-Hub-Key'       => $key_id,
		'X-BCM-Hub-Time'      => $time,
		'X-BCM-Hub-Nonce'     => $nonce,
		'X-BCM-Hub-Actor'     => $actor,
		'X-BCM-Hub-Signature' => $sig,
	);
	if ( isset( $o['approval'] ) ) {
		$a       = $o['approval'];
		$a_id    = isset( $a['key_id'] ) ? $a['key_id'] : 'approve-1';
		$a_sec   = isset( $a['secret'] ) ? $a['secret'] : $kp['approve-1']['secret'];
		$a_time  = isset( $a['time'] ) ? (string) $a['time'] : (string) time();
		$a_body  = isset( $a['body'] ) ? $a['body'] : $body;
		$a_nonce = isset( $a['nonce'] ) ? $a['nonce'] : $nonce;
		$a_msg   = implode( "\n", array( 'BCM-HUB-APPROVAL-V1', $a_id, $host, $a_nonce, $actor, $a_time, hash( 'sha256', $a_body ) ) );
		$headers += array(
			'X-BCM-Hub-Approval-Key'  => $a_id,
			'X-BCM-Hub-Approval-Time' => $a_time,
			'X-BCM-Hub-Approval'      => b64url( sodium_crypto_sign_detached( $a_msg, $a_sec ) ),
		);
	}
	if ( isset( $o['headers'] ) ) {
		$headers = array_merge( $headers, $o['headers'] );
	}
	foreach ( $headers as $k => $v ) {
		if ( null !== $v ) {
			$req->set_header( $k, $v );
		}
	}
	$req->set_body( isset( $o['send_body'] ) ? $o['send_body'] : $body );
	return $req;
}
function send( array $o = array() ) { return bcm_test_dispatch( hub_request( $o ) ); }
function code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : 'ok'; }
function data( $r ) { return $r instanceof WP_REST_Response ? $r->get_data() : null; }
function run_body( $ability, $input = null ) {
	$b = array( 'action' => 'run', 'ability' => $ability );
	if ( null !== $input ) {
		$b['input'] = $input;
	}
	return $b;
}
function last_log() { global $wpdb; return end( $wpdb->log ); }
function log_count() { global $wpdb; return count( $wpdb->log ); }

$pass = 0;
$fail = 0;
function t( $name, $cond, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
	} else {
		$fail++;
		echo "FAIL: {$name}" . ( '' !== $detail ? "\n      " . $detail : '' ) . "\n";
	}
}
function is_code( $name, $r, $want ) { t( $name, code( $r ) === $want, 'got ' . code( $r ) . ( is_wp_error( $r ) ? ' (' . $r->get_error_message() . ')' : '' ) ); }

/* ===================================================================== */

// Setup.
t( 'route registered', isset( $GLOBALS['__routes']['/bcm-agency/v1/hub'] ) );
t( 'route is POST only', 'POST' === $GLOBALS['__routes']['/bcm-agency/v1/hub']['methods'] );
t( 'maybe_upgrade created nonce table', $wpdb->nonce_table_exists );
t( 'log table has via + actor columns', (bool) preg_match( '/via varchar.*actor varchar/s', implode( "\n", $wpdb->ddl ) ) );
t( 'DB_VERSION bumped', '2' === BCM_Log::DB_VERSION && '2' === get_option( 'bcm_agency_log_db_version' ) );
t( 'placeholder key ignored', ! isset( BCM_Hub::trusted_keys( 'signing' )['bad-placeholder'] ) );
t( 'version: header and constant match', (bool) preg_match( '/Version:\s+' . preg_quote( BCM_AGENCY_VERSION, '/' ) . '\s/', file_get_contents( $plugin . '/bcm-agency.php' ) ) && version_compare( BCM_AGENCY_VERSION, '1.4.0', '>=' ) );

// Unsigned and inert.
$req = new WP_REST_Request( 'POST', '/bcm-agency/v1/hub' );
$req->set_header( 'Host', 'kozyhomes.ca' );
$req->set_body( '{"action":"ping"}' );
$before = log_count();
is_code( 'no headers -> unsigned', bcm_test_dispatch( $req ), 'bcm_hub_unsigned' );
t( 'unsigned requests are not logged', log_count() === $before );
t( 'GET is not routed', 'rest_no_route' === code( bcm_test_dispatch( new WP_REST_Request( 'GET', '/bcm-agency/v1/hub' ) ) ) );
t( 'handle() without a passed check refuses', 'bcm_hub_unsigned' === code( BCM_Hub::handle( hub_request() ) ) );

// Happy ping.
$r = send();
is_code( 'ping ok', $r, 'ok' );
$d = data( $r );
t( 'ping reports version, home host, user ready', $d && BCM_AGENCY_VERSION === $d['plugin_version'] && 'kozyhomes.ca' === $d['home_host'] && true === $d['hub_user_ready'] );
t( 'ping counts abilities by family', $d && $d['abilities']['bcm'] > 30 && $d['abilities']['elementor'] >= 3 && 1 === $d['abilities']['yoast-seo'] );
t( 'ping does not leave a user signed in', 0 === get_current_user_id() );
t( 'last contact recorded', (int) get_option( 'bcm_agency_hub_last_contact' ) > 0 );

// 1. Switch.
update_option( 'bcm_agency_hub_enabled', '0' );
is_code( 'switched off -> disabled', send(), 'bcm_hub_disabled' );
t( 'refusal logged with code', 'bcm_hub_disabled' === json_decode( last_log()['summary'], true )['hub_refused'] && 'hub' === last_log()['via'] );
update_option( 'bcm_agency_hub_enabled', '1' );

// 2. Keys.
is_code( 'unknown key id', send( array( 'key_id' => 'hub-9', 'secret' => $kp['hub-1']['secret'] ) ), 'bcm_hub_unknown_key' );
is_code( 'placeholder key id is not trusted', send( array( 'key_id' => 'bad-placeholder', 'secret' => $kp['hub-1']['secret'] ) ), 'bcm_hub_unknown_key' );
is_code( 'approval key cannot sign requests', send( array( 'key_id' => 'approve-1' ) ), 'bcm_hub_unknown_key' );
is_code( 'next key (hub-2) works: rotation', send( array( 'key_id' => 'hub-2' ) ), 'ok' );
is_code( 'hub-1 id with hub-2 secret', send( array( 'key_id' => 'hub-1', 'secret' => $kp['hub-2']['secret'] ) ), 'bcm_hub_bad_signature' );
is_code( 'rogue key under a trusted id', send( array( 'secret' => $kp['rogue']['secret'] ) ), 'bcm_hub_bad_signature' );

// 3. Signature and tampering.
is_code( 'tampered body', send( array( 'send_body' => '{"action":"list"}' ) ), 'bcm_hub_bad_signature' );
is_code( 'tampered actor', send( array( 'headers' => array( 'X-BCM-Hub-Actor' => 'b8c9' ) ) ), 'bcm_hub_bad_signature' );
is_code( 'tampered time', send( array( 'headers' => array( 'X-BCM-Hub-Time' => (string) ( time() - 1 ) ) ) ), 'bcm_hub_bad_signature' );
is_code( 'tampered nonce', send( array( 'headers' => array( 'X-BCM-Hub-Nonce' => b64url( random_bytes( 24 ) ) ) ) ), 'bcm_hub_bad_signature' );
is_code( 'tampered key id (other trusted key)', send( array( 'headers' => array( 'X-BCM-Hub-Key' => 'hub-2' ) ) ), 'bcm_hub_bad_signature' );
$sig = hub_request()->get_header( 'X-BCM-Hub-Signature' );
$flip = substr( $sig, 0, 10 ) . ( 'A' === $sig[10] ? 'B' : 'A' ) . substr( $sig, 11 );
is_code( 'one character of the signature changed', send( array( 'headers' => array( 'X-BCM-Hub-Signature' => $flip ) ) ), 'bcm_hub_bad_signature' );
is_code( 'signature not base64url', send( array( 'headers' => array( 'X-BCM-Hub-Signature' => str_repeat( '*', 86 ) ) ) ), 'bcm_hub_bad_request' );
is_code( 'short signature', send( array( 'headers' => array( 'X-BCM-Hub-Signature' => b64url( random_bytes( 63 ) ) ) ) ), 'bcm_hub_bad_request' );
is_code( 'missing nonce header', send( array( 'headers' => array( 'X-BCM-Hub-Nonce' => null ) ) ), 'bcm_hub_bad_request' );
is_code( 'short nonce', send( array( 'nonce' => 'abc' ) ), 'bcm_hub_bad_request' );
is_code( 'actor with an @ (emails never allowed)', send( array( 'actor' => 'maddy@bcm.ca' ) ), 'bcm_hub_bad_request' );
is_code( 'time not numeric', send( array( 'time' => '2026-11-12' ) ), 'bcm_hub_bad_request' );
$before = log_count();
send( array( 'send_body' => '{"action":"list"}' ) );
t( 'bad-signature refusal logged without actor', '' === last_log()['actor'] && log_count() === $before + 1 );
is_code( 'body over 1 MB', send( array( 'raw_body' => json_encode( array( 'action' => 'ping', 'pad' => str_repeat( 'x', 1048576 ) ) ) ) ), 'bcm_hub_too_large' );

// 4. Time.
is_code( 'time 301 s old', send( array( 'time' => time() - 301 ) ), 'bcm_hub_expired' );
is_code( 'time 301 s ahead', send( array( 'time' => time() + 301 ) ), 'bcm_hub_expired' );
is_code( 'time 290 s old is fine', send( array( 'time' => time() - 290 ) ), 'ok' );
send( array( 'time' => time() - 400 ) );
t( 'expired refusal logged WITH actor (signature was valid)', 'a7f3' === last_log()['actor'] );

// 5. Nonce.
$req1 = hub_request( array( 'nonce' => 'replay-nonce-0123456789abc' ) );
$req2 = hub_request( array( 'nonce' => 'replay-nonce-0123456789abc' ) );
is_code( 'first use of nonce', bcm_test_dispatch( $req1 ), 'ok' );
is_code( 'identical request replayed', bcm_test_dispatch( $req2 ), 'bcm_hub_replay' );
is_code( 'nonce reused with a different body', send( array( 'nonce' => 'replay-nonce-0123456789abc', 'body' => array( 'action' => 'list' ) ) ), 'bcm_hub_replay' );
$wpdb->nonces['old-nonce-0123456789abcdef'] = time() - 2000;
mt_srand( 1 );
for ( $i = 0; $i < 40; $i++ ) { send(); }
t( 'old nonces are cleaned up', ! isset( $wpdb->nonces['old-nonce-0123456789abcdef'] ) );
$wpdb->nonce_table_exists = false;
is_code( 'missing nonce table is recreated, request still checked', send(), 'ok' );
$n = 'self-heal-nonce-0123456789ab';
send( array( 'nonce' => $n ) );
is_code( 'replay still caught after self-heal', send( array( 'nonce' => $n ) ), 'bcm_hub_replay' );

// 6. Host.
$r = send( array( 'host' => 'otherclient.ca' ) );
is_code( 'signed for another site', $r, 'bcm_hub_wrong_host' );
t( 'wrong-host refusal says the real home host', is_wp_error( $r ) && 'kozyhomes.ca' === $r->get_error_data()['home_host'] );
is_code( 'signed for this site, sent with another Host header', send( array( 'send_host' => 'otherclient.ca' ) ), 'bcm_hub_bad_signature' );
is_code( 'www variant accepted', send( array( 'host' => 'www.kozyhomes.ca' ) ), 'ok' );
is_code( 'Host header with port and capitals', send( array( 'send_host' => 'KozyHomes.ca:443' ) ), 'ok' );
is_code( 'look-alike host', send( array( 'host' => 'kozyhomes.ca.evil.com' ) ), 'bcm_hub_wrong_host' );
is_code( 'subdomain is not this site', send( array( 'host' => 'staging.kozyhomes.ca' ) ), 'bcm_hub_wrong_host' );

// 7a. Body.
is_code( 'not JSON', send( array( 'raw_body' => 'action=ping' ) ), 'bcm_hub_bad_request' );
is_code( 'JSON list', send( array( 'raw_body' => '["ping"]' ) ), 'bcm_hub_bad_request' );
is_code( 'unknown action', send( array( 'body' => array( 'action' => 'delete' ) ) ), 'bcm_hub_bad_request' );
is_code( 'extra top-level field', send( array( 'body' => array( 'action' => 'ping', 'as_user' => 1 ) ) ), 'bcm_hub_bad_request' );
is_code( 'run without ability', send( array( 'body' => array( 'action' => 'run' ) ) ), 'bcm_hub_bad_request' );
is_code( 'ability name with ..', send( array( 'body' => run_body( 'bcm/../x' ) ) ), 'bcm_hub_bad_request' );
is_code( 'input that is a string', send( array( 'body' => array( 'action' => 'run', 'ability' => 'bcm/get-post', 'input' => 'x' ) ) ), 'bcm_hub_bad_request' );
is_code( 'input on ping', send( array( 'body' => array( 'action' => 'ping', 'input' => array() ) ) ), 'bcm_hub_bad_request' );

// 7b. Families and lists.
is_code( 'core/ ability refused', send( array( 'body' => run_body( 'core/get-site-info' ) ) ), 'bcm_hub_not_allowed' );
is_code( 'unknown bcm ability', send( array( 'body' => run_body( 'bcm/delete-everything' ) ) ), 'bcm_hub_not_found' );
t( 'no "ability not found" notice for unknown names', empty( $GLOBALS['__doing_it_wrong'] ) );
is_code( 'developer switches excluded (run)', send( array( 'body' => run_body( 'elementor/manage-experiments', array( 'approved' => true ) ) ) ), 'bcm_hub_excluded' );
is_code( 'developer switches excluded (info)', send( array( 'body' => array( 'action' => 'info', 'ability' => 'elementor/manage-experiments' ) ) ), 'bcm_hub_excluded' );
// Tracking codes: adding needs Chris's confirmation; a pasted provider snippet isn't refused by the scan.
$snippet = array( 'service' => 'gsc', 'value' => '<meta name="google-site-verification" content="abc123" /><script async src="https://www.googletagmanager.com/gtag/js?id=G-XYZ"></script>', 'approved' => true );
$r = send( array( 'body' => run_body( 'bcm/add-tracking-code', $snippet ) ) );
is_code( 'add tracking code: needs confirmation (pasted snippet not refused)', $r, 'bcm_hub_needs_confirmation' );
t( 'add tracking code: reason says so', is_wp_error( $r ) && 'it adds tracking code to every page of the site' === $r->get_error_data()['reason'] );
is_code( 'add tracking code with confirmation runs', send( array( 'body' => run_body( 'bcm/add-tracking-code', $snippet ), 'approval' => array() ) ), 'ok' );
is_code( 'scan exemption is only for add-tracking-code', send( array( 'body' => run_body( 'bcm/update-post', array( 'post_id' => 1, 'value' => '<script>x</script>', 'approved' => true ) ) ) ), 'bcm_hub_excluded' );
is_code( 'remove tracking code runs without confirmation', send( array( 'body' => run_body( 'bcm/remove-tracking-code', array( 'service' => 'markerio', 'approved' => true ) ) ) ), 'ok' );
// Site parts: headers and footers allowed; Elementor Pro Custom Code snippets refused.
is_code( 'site parts: header allowed', send( array( 'body' => run_body( 'elementor/manage-site-parts', array( 'operations' => array( array( 'action' => 'create', 'type' => 'header', 'conditions' => array( 'include/general' ) ) ) ) ) ) ), 'ok' );
is_code( 'site parts: custom code snippet refused', send( array( 'body' => run_body( 'elementor/manage-site-parts', array( 'operations' => array( array( 'action' => 'create', 'type' => 'code_snippet' ) ) ) ) ) ), 'bcm_hub_excluded' );
// Reading clients' leads needs confirmation even though it only reads.
$r = send( array( 'body' => run_body( 'elementor/list-form-submissions', array( 'post_id' => 1 ) ) ) );
is_code( 'form submissions: needs confirmation', $r, 'bcm_hub_needs_confirmation' );
t( 'form submissions: reason says so', is_wp_error( $r ) && false !== strpos( $r->get_error_data()['reason'], 'personal data' ) );
$list  = data( send( array( 'body' => array( 'action' => 'list' ) ) ) );
$names = $list ? array_column( $list['abilities'], 'tier', 'name' ) : array();
t( 'list omits excluded abilities', ! isset( $names['elementor/manage-experiments'] ) );
t( 'list: add tracking code needs confirmation, remove is allowed', 'confirm' === $names['bcm/add-tracking-code'] && 'allowed' === $names['bcm/remove-tracking-code'] );
t( 'list: site parts allowed, form submissions need confirmation', 'allowed' === $names['elementor/manage-site-parts'] && 'confirm' === $names['elementor/list-form-submissions'] );
t( 'list omits other families', ! isset( $names['core/get-site-info'] ) );
t( 'list marks confirm tier', isset( $names['bcm/update-form-email'] ) && 'confirm' === $names['bcm/update-form-email'] && 'confirm' === $names['bcm/set-homepage'] );
t( 'list includes list-tracking-codes (read-only)', isset( $names['bcm/list-tracking-codes'] ) && 'allowed' === $names['bcm/list-tracking-codes'] );
t( 'list is sorted', $list && array_keys( $names ) === ( function ( $a ) { sort( $a ); return $a; } )( array_keys( $names ) ) );
$all_real = array( 'add-gallery-image','add-gallery-tab','add-list-item','add-menu-item','add-tracking-code','check-for-updates','create-post','create-term','find-images','find-text','get-activity-log','get-atomic-image-fit','get-post','get-site-identity','list-categories','list-forms','list-items','list-media','list-menus','list-posts','list-tracking-codes','publish-post','purge-page-cache','remove-gallery-image','remove-list-item','remove-menu-item','remove-tracking-code','replace-image','replace-text','schedule-post','set-atomic-image-fit','set-homepage','set-page-layout','unpublish-post','update-form-email','update-image-alt','update-menu-item','update-post','update-site-identity','upload-media' );
$unlisted = array();
foreach ( $all_real as $n ) {
	$tier = BCM_Hub_Policy::tier( 'bcm/' . $n, null );
	if ( ! in_array( 'bcm/' . $n, array_merge( BCM_Hub_Policy::ALLOWED_ABILITIES, BCM_Hub_Policy::CONFIRM_ABILITIES, BCM_Hub_Policy::EXCLUDED_ABILITIES ), true ) ) {
		$unlisted[] = $n;
	}
}
t( 'all 40 bcm abilities have a reviewed tier', array() === $unlisted, implode( ', ', $unlisted ) );
$registered = array_filter( array_keys( $GLOBALS['__abilities'] ), function ( $n ) { return 0 === strpos( $n, 'bcm/' ); } );
t( 'plugin registers exactly the 40 known bcm abilities', 40 === count( $registered ) && array() === array_diff( $registered, array_map( function ( $n ) { return 'bcm/' . $n; }, $all_real ) ) );
$info = data( send( array( 'body' => array( 'action' => 'info', 'ability' => 'bcm/update-post' ) ) ) );
t( 'info returns schema and tier', $info && isset( $info['ability']['input_schema']['properties'] ) && 'allowed' === $info['ability']['tier'] );

// Running.
$r = send( array( 'body' => run_body( 'bcm/get-post', array( 'post_id' => 5 ) ) ) );
is_code( 'read-only bcm ability runs', $r, 'ok' );
$p = data( $r )['result'];
t( 'runs as the oldest administrator', 1 === $p['user'] );
t( 'unfiltered_html removed, kses on', false === $p['unfiltered_html'] && true === $p['kses_on'] );
t( 'plugin/user management removed', false === $p['install_plugins'] && false === $p['create_users'] && false === $p['list_users'] );
t( 'manage_options and update_plugins kept', true === $p['manage_options'] && true === $p['update_plugins'] );
t( 'user signed out afterwards', 0 === get_current_user_id() );
wp_set_current_user( 1 );
t( 'admin keeps unfiltered_html outside hub requests', current_user_can( 'unfiltered_html' ) && current_user_can( 'install_plugins' ) && false === $GLOBALS['__kses_on'] );
wp_set_current_user( 0 );

$before = log_count();
$r      = send( array( 'body' => run_body( 'bcm/update-post', array( 'post_id' => 5, 'title' => 'New' ) ) ) );
is_code( 'approval rule unchanged: code passed through', $r, 'bcm_needs_approval' );
t( 'ability error returns status 400', is_wp_error( $r ) && 400 === $r->get_error_data()['status'] );
$row = last_log();
t( 'refused-by-ability write logged via hub with actor', log_count() === $before + 1 && 'hub' === $row['via'] && 'a7f3' === $row['actor'] && 'bcmadmin' === $row['user_login'] && 'error' === $row['result'] );
$r   = send( array( 'actor' => 'b8c9', 'body' => run_body( 'bcm/update-post', array( 'post_id' => 5, 'title' => 'New', 'approved' => true ) ) ) );
is_code( 'approved write runs', $r, 'ok' );
$row = last_log();
$sum = json_decode( $row['summary'], true );
t( 'bcm log row: via hub, actor, before/after, approved', 'hub' === $row['via'] && 'b8c9' === $row['actor'] && 'Old' === $sum['before'] && true === $sum['approved'] && false === $sum['second_confirmation'] && 5 === $row['object_id'] );
t( '_log stripped from result', ! isset( data( $r )['result']['_log'] ) );

// Second confirmation.
$body = run_body( 'bcm/update-form-email', array( 'post_id' => 7, 'to' => 'owner@kozyhomes.ca', 'approved' => true ) );
$r    = send( array( 'body' => $body ) );
is_code( 'form email without second confirmation', $r, 'bcm_hub_needs_confirmation' );
t( 'needs-confirmation says why', is_wp_error( $r ) && 'it changes where a form sends its emails' === $r->get_error_data()['reason'] );
is_code( 'form email with second confirmation', send( array( 'body' => $body, 'approval' => array() ) ), 'ok' );
t( 'logged as second-confirmed', true === json_decode( last_log()['summary'], true )['second_confirmation'] );
is_code( 'approval for a different body', send( array( 'body' => $body, 'approval' => array( 'body' => json_encode( run_body( 'bcm/update-form-email', array( 'post_id' => 7, 'to' => 'thief@evil.com', 'approved' => true ) ) ) ) ) ), 'bcm_hub_bad_approval' );
is_code( 'approval for a different nonce', send( array( 'body' => $body, 'approval' => array( 'nonce' => b64url( random_bytes( 24 ) ) ) ) ), 'bcm_hub_bad_approval' );
is_code( 'approval signed by the hub key', send( array( 'body' => $body, 'approval' => array( 'key_id' => 'approve-1', 'secret' => $kp['hub-1']['secret'] ) ) ), 'bcm_hub_bad_approval' );
is_code( 'approval under a signing key id', send( array( 'body' => $body, 'approval' => array( 'key_id' => 'hub-1', 'secret' => $kp['hub-1']['secret'] ) ) ), 'bcm_hub_bad_approval' );
is_code( 'approval 6 minutes old', send( array( 'body' => $body, 'approval' => array( 'time' => time() - 360 ) ) ), 'bcm_hub_bad_approval' );
is_code( 'approval with matching actor and fresh nonce runs', send( array( 'actor' => 'zz99', 'body' => $body, 'approval' => array( 'nonce' => null ) ) ), 'ok' ); // same actor signs both: sanity
$req = hub_request( array( 'body' => $body, 'approval' => array() ) );
$req->set_header( 'X-BCM-Hub-Actor', 'zz99' );
is_code( 'swapping actor after approval breaks the signature', bcm_test_dispatch( $req ), 'bcm_hub_bad_signature' );
is_code( 'site-wide setting needs confirmation', send( array( 'body' => run_body( 'bcm/update-form-email' ) ) ), 'bcm_hub_needs_confirmation' );

// Unreviewed abilities in allowed families.
is_code( 'unreviewed write needs confirmation', send( array( 'body' => run_body( 'elementor/new-thing', array( 'post_id' => 1 ) ) ) ), 'bcm_hub_needs_confirmation' );
is_code( 'unreviewed read-only runs', send( array( 'body' => run_body( 'elementor/new-read', array( 'post_id' => 1 ) ) ) ), 'ok' );
is_code( 'ability without input schema, input {} sent', send( array( 'raw_body' => '{"action":"run","ability":"elementor/get-page-structure","input":{}}' ) ), 'ok' );
is_code( 'ability without input schema, no input', send( array( 'body' => run_body( 'elementor/get-page-structure' ) ) ), 'ok' );

// Reference documents (MCP resources) count as read-only unless explicitly marked otherwise.
$names = array_column( data( send( array( 'body' => array( 'action' => 'list' ) ) ) )['abilities'], null, 'name' );
t( 'resource listed as allowed and read-only', 'allowed' === $names['elementor/style-best-practices']['tier'] && true === $names['elementor/style-best-practices']['readonly'] );
t( 'resource explicitly marked as writing still needs confirmation', 'confirm' === $names['elementor/resource-that-writes']['tier'] );
is_code( 'resource runs without a second confirmation', send( array( 'raw_body' => '{"action":"run","ability":"elementor/style-best-practices","input":{}}' ) ), 'ok' );
$r = send( array( 'body' => run_body( 'elementor/resource-that-writes', array( 'post_id' => 1 ) ) ) );
is_code( 'resource marked as writing: held', $r, 'bcm_hub_needs_confirmation' );
t( 'unreviewed: reason says so', is_wp_error( $r ) && false !== strpos( $r->get_error_data()['reason'], 'not reviewed' ) );
$r = send( array( 'body' => run_body( 'bcm/set-homepage', array( 'post_id' => 2 ) ) ) );
t( 'site-wide setting: reason says so', is_wp_error( $r ) && 'it changes a site-wide setting' === $r->get_error_data()['reason'] );

// Non-bcm write logging (keys only, never values).
$before = log_count();
is_code( 'yoast SEO update runs', send( array( 'body' => run_body( 'yoast-seo/update-post-seo-data', array( 'post_id' => 9, 'meta_description' => 'secret-value-xyz' ) ) ) ), 'ok' );
$row = last_log();
t( 'non-bcm write logged with input keys only', log_count() === $before + 1 && 'yoast-seo/update-post-seo-data' === $row['ability'] && false === strpos( $row['summary'], 'secret-value-xyz' ) && false !== strpos( $row['summary'], 'meta_description' ) && 9 === $row['object_id'] );
$before = log_count();
send( array( 'body' => run_body( 'elementor/new-read', array( 'post_id' => 1 ) ) ) );
t( 'read-only runs are not logged', log_count() === $before );

// Content scan on writes.
$scan_cases = array(
	// [label, input, expected code]
	array( 'plain text', array( 'settings' => array( 'title' => 'Saturday 9–2' ) ), 'ok' ),
	array( 'normal inline HTML', array( 'paragraph' => '<p>Call <strong>us</strong> <a href="https://kozyhomes.ca/contact" target="_blank">today</a>.</p>' ), 'ok' ),
	array( 'code tag for formatting', array( 'paragraph' => 'Run <code>npm i</code>' ), 'ok' ),
	array( 'JavaScript as a word', array( 'paragraph' => 'Learn JavaScript: the basics' ), 'ok' ),
	array( 'behavior/expression as words', array( 'paragraph' => 'Customer behavior: an expression (of thanks)' ), 'ok' ),
	array( 'on= in prose', array( 'paragraph' => 'Turn it on=off, one=two' ), 'ok' ),
	array( 'mailto and tel links', array( 'link' => array( 'destination' => 'mailto:info@kozyhomes.ca' ), 'tel' => 'tel:5551234' ), 'ok' ),
	array( 'image data URL', array( 'src' => 'data:image/png;base64,iVBORw0KGgo=' ), 'ok' ),
	array( 'inline style CSS', array( 'style' => 'color: red; background: url(https://kozyhomes.ca/a.jpg); &:hover { color: blue; }' ), 'ok' ),
	array( 'e-form widget tag', array( 'xml_structure' => '<e-form configuration-id="f"><e-form-input configuration-id="i"/></e-form>' ), 'ok' ),
	array( 'script tag', array( 'paragraph' => 'Hi <script>alert(1)</script>' ), 'bcm_hub_excluded' ),
	array( 'SCRIPT tag with spaces', array( 'paragraph' => '< SCRIPT src=//evil>' ), 'bcm_hub_excluded' ),
	array( 'entity-encoded script', array( 'paragraph' => '&lt;script&gt;alert(1)&lt;/script&gt;' ), 'bcm_hub_excluded' ),
	array( 'iframe', array( 'paragraph' => '<iframe src="https://evil"></iframe>' ), 'bcm_hub_excluded' ),
	array( 'style block', array( 'paragraph' => '<style>body{display:none}</style>' ), 'bcm_hub_excluded' ),
	array( 'onerror handler', array( 'paragraph' => '<img src=x onerror=alert(1)>' ), 'bcm_hub_excluded' ),
	array( 'handler after slash', array( 'paragraph' => '<img/onload=alert(1)>' ), 'bcm_hub_excluded' ),
	array( 'handler on new line', array( 'paragraph' => "<a href=\"#\"\nonclick=\"x()\">x</a>" ), 'bcm_hub_excluded' ),
	array( 'javascript href', array( 'paragraph' => '<a href="javascript:alert(1)">x</a>' ), 'bcm_hub_excluded' ),
	array( 'javascript href with tab entity', array( 'paragraph' => '<a href="java&#x09;script:alert(1)">x</a>' ), 'bcm_hub_excluded' ),
	array( 'javascript href with colon entity', array( 'paragraph' => '<a href="javascript&colon;alert(1)">x</a>' ), 'bcm_hub_excluded' ),
	array( 'javascript link value', array( 'link' => array( 'destination' => ' JavaScript:alert(1)' ) ), 'bcm_hub_excluded' ),
	array( 'html data URL link', array( 'link' => 'data:text/html;base64,PHNjcmlwdD4=' ), 'bcm_hub_excluded' ),
	array( 'CSS expression', array( 'style' => 'width: expression(alert(1))' ), 'bcm_hub_excluded' ),
	array( 'CSS @import', array( 'style' => '@import url(https://evil/x.css);' ), 'bcm_hub_excluded' ),
	array( 'CSS url(javascript:)', array( 'style' => 'background:url("javascript:alert(1)")' ), 'bcm_hub_excluded' ),
	array( 'PHP open tag', array( 'paragraph' => '<?php system("id"); ?>' ), 'bcm_hub_excluded' ),
	array( 'custom_css field', array( 'settings' => array( 'custom_css' => 'selector{}' ) ), 'bcm_hub_excluded' ),
	array( '_custom_css field', array( 'settings' => array( '_custom_css' => 'x' ) ), 'bcm_hub_excluded' ),
	array( 'custom attributes', array( 'settings' => array( '_attributes' => 'onclick|alert(1)' ) ), 'bcm_hub_excluded' ),
	array( 'key/value naming custom_css', array( 'key' => 'custom_css', 'value' => 'x' ), 'bcm_hub_excluded' ),
	array( 'HTML widget type', array( 'elements' => array( array( 'widgetType' => 'html', 'settings' => array( 'html' => 'x' ) ) ) ), 'bcm_hub_excluded' ),
	array( 'code-highlight widget', array( 'widgetType' => 'code-highlight' ), 'bcm_hub_excluded' ),
	array( 'shortcode widget', array( 'widget_type' => 'shortcode' ), 'bcm_hub_excluded' ),
	array( 'e-html in composition XML', array( 'xml_structure' => '<e-div-block configuration-id="a"><e-html configuration-id="h"/></e-div-block>' ), 'bcm_hub_excluded' ),
	array( 'shortcode dynamic tag', array( 'title' => array( 'name' => 'shortcode', 'settings' => array( 'shortcode' => '[x]' ) ) ), 'bcm_hub_excluded' ),
	array( 'V3 dynamic tag string', array( '__dynamic__' => array( 'title' => '[elementor-tag id="1" name="shortcode" settings="x"]' ) ), 'bcm_hub_excluded' ),
	array( 'svg tag', array( 'paragraph' => '<svg><a xlink:href="x">' ), 'bcm_hub_excluded' ),
	array( 'form tag', array( 'paragraph' => '<form action="https://evil"><input name=p></form>' ), 'bcm_hub_excluded' ),
	array( 'deep nesting', array_reduce( range( 1, 45 ), function ( $c ) { return array( 'a' => $c ); }, 'x' ), 'bcm_hub_excluded' ),
	array( 'V4 form email field', array( 'settings' => array( 'email' => array( 'to' => 'x@evil.com' ) ) ), 'bcm_hub_needs_confirmation' ),
	array( 'V4 webhook', array( 'settings' => array( 'actions-after-submit' => array( 'webhook' ), 'webhook_url' => 'https://evil' ) ), 'bcm_hub_needs_confirmation' ),
	array( 'V3 email_to', array( 'settings' => array( 'email_to' => 'x@evil.com' ) ), 'bcm_hub_needs_confirmation' ),
	array( 'redirect_url', array( 'settings' => array( 'redirect_url' => 'https://evil' ) ), 'bcm_hub_needs_confirmation' ),
	array( 'exclusion beats confirmation', array( 'settings' => array( 'email_to' => 'x@y.z', 'custom_css' => 'x' ) ), 'bcm_hub_excluded' ),
);
foreach ( $scan_cases as $c ) {
	is_code( 'scan: ' . $c[0], send( array( 'body' => run_body( 'elementor/manage-elements', $c[1] ) ) ), $c[2] );
}
is_code( 'scan: form email with confirmation runs', send( array( 'body' => run_body( 'elementor/manage-elements', array( 'settings' => array( 'email_to' => 'owner@kozyhomes.ca' ) ) ), 'approval' => array() ) ), 'ok' );
is_code( 'scan: confirmation never unlocks excluded content', send( array( 'body' => run_body( 'elementor/manage-elements', array( 'paragraph' => '<script>x</script>' ) ), 'approval' => array() ) ), 'bcm_hub_excluded' );
is_code( 'scan: read-only abilities are not scanned', send( array( 'body' => run_body( 'bcm/get-post', array( 'post_id' => 1, 'find' => '<script' ) ) ) ), 'ok' );
is_code( 'scan applies to bcm writes too', send( array( 'body' => run_body( 'bcm/update-post', array( 'post_id' => 1, 'content' => '<script>x</script>', 'approved' => true ) ) ) ), 'bcm_hub_excluded' );

// Hub user.
update_option( 'bcm_agency_hub_user_id', 3 );
t( 'chosen administrator is used', 3 === data( send( array( 'body' => run_body( 'bcm/get-post', array( 'post_id' => 1 ) ) ) ) )['result']['user'] );
update_option( 'bcm_agency_hub_user_id', 2 );
is_code( 'chosen user who is not an admin: refused (no fallback)', send( array( 'body' => run_body( 'bcm/get-post', array( 'post_id' => 1 ) ) ) ), 'bcm_hub_no_user' );
t( 'ping reports no usable user', false === data( send() )['hub_user_ready'] );
update_option( 'bcm_agency_hub_user_id', 0 );

// Refusal log cap.
delete_transient( 'bcm_agency_hub_refusals' );
$before = log_count();
for ( $i = 0; $i < 80; $i++ ) {
	send( array( 'key_id' => 'nope' ) );
}
t( 'refusal logging capped at 50 per 10 minutes', log_count() - $before === 50, 'logged ' . ( log_count() - $before ) );
delete_transient( 'bcm_agency_hub_refusals' );

// Cross-language: the JavaScript signer (same WebCrypto code the Worker uses).
if ( $node_keys ) {
	$sign_js = function ( array $in ) use ( $node ) {
		$p = proc_open( escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/sign.mjs' ) . ' sign', array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		fwrite( $pipes[0], json_encode( $in ) );
		fclose( $pipes[0] );
		$out = stream_get_contents( $pipes[1] );
		$err = stream_get_contents( $pipes[2] );
		proc_close( $p );
		$j = json_decode( $out, true );
		if ( ! $j ) {
			echo "node signer error: {$err}\n";
		}
		return $j ? $j['headers'] : array();
	};
	$js_req = function ( $headers, $body, $host = 'kozyhomes.ca' ) {
		$r = new WP_REST_Request( 'POST', '/bcm-agency/v1/hub' );
		$r->set_header( 'Host', $host );
		foreach ( $headers as $k => $v ) {
			$r->set_header( $k, $v );
		}
		$r->set_body( $body );
		return $r;
	};
	$body = json_encode( array( 'action' => 'run', 'ability' => 'bcm/get-post', 'input' => array( 'post_id' => 3, 'note' => 'Café – “quotes” ✓' ) ), JSON_UNESCAPED_UNICODE );
	$h    = $sign_js( array( 'private_pkcs8' => $node_keys['private_pkcs8'], 'key_id' => 'hub-js', 'host' => 'kozyhomes.ca', 'actor' => 'js01', 'body' => $body ) );
	is_code( 'JS-signed request (UTF-8 body) verifies in PHP', bcm_test_dispatch( $js_req( $h, $body ) ), 'ok' );
	is_code( 'JS-signed request replayed', bcm_test_dispatch( $js_req( $h, $body ) ), 'bcm_hub_replay' );
	$h2 = $sign_js( array( 'private_pkcs8' => $node_keys['private_pkcs8'] . "\n", 'key_id' => 'hub-js', 'host' => 'kozyhomes.ca', 'actor' => 'js01', 'body' => $body ) );
	is_code( 'secret with trailing newline still works', bcm_test_dispatch( $js_req( $h2, $body ) ), 'ok' );
	$h3 = $sign_js( array( 'private_pkcs8' => $node_keys['private_pkcs8'], 'key_id' => 'hub-js', 'host' => 'kozyhomes.ca', 'actor' => 'js01', 'body' => $body ) );
	is_code( 'JS-signed request with body changed', bcm_test_dispatch( $js_req( $h3, str_replace( 'Café', 'Cafe', $body ) ) ), 'bcm_hub_bad_signature' );

	// The exact one-liner Chris runs to make keys (private key to stdout for wrangler, public to the screen).
	$oneliner = "const k=require('crypto').generateKeyPairSync('ed25519');console.error('PUBLIC KEY: '+k.publicKey.export({format:'jwk'}).x);process.stdout.write(k.privateKey.export({format:'der',type:'pkcs8'}).toString('base64'))";
	$p        = proc_open( escapeshellarg( $node ) . ' -e ' . escapeshellarg( $oneliner ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
	fclose( $pipes[0] );
	$priv = stream_get_contents( $pipes[1] );
	$scr  = stream_get_contents( $pipes[2] );
	proc_close( $p );
	t( 'one-liner prints only the public key to the screen', (bool) preg_match( '/^PUBLIC KEY: ([A-Za-z0-9_-]{43})\s*$/', $scr, $pm ) && false === strpos( $scr, $priv ) );
	t( 'one-liner sends no newline after the private key', $priv === trim( $priv ) && '' !== $priv );
	t( 'one-liner public key decodes to 32 bytes', isset( $pm[1] ) && 32 === strlen( BCM_Hub::b64url_decode( $pm[1] ) ) );
	$h4 = $sign_js( array( 'private_pkcs8' => $priv, 'key_id' => 'k', 'host' => 'kozyhomes.ca', 'actor' => 'js01', 'body' => '{"action":"ping"}' ) );
	$m4 = BCM_Hub::message( 'POST', 'kozyhomes.ca', '/bcm-agency/v1/hub', 'k', $h4['X-BCM-Hub-Time'], $h4['X-BCM-Hub-Nonce'], 'js01', hash( 'sha256', '{"action":"ping"}' ) );
	t( 'one-liner private key signs in WebCrypto; PHP verifies with its public key', isset( $pm[1] ) && sodium_crypto_sign_verify_detached( BCM_Hub::b64url_decode( $h4['X-BCM-Hub-Signature'] ), $m4, BCM_Hub::b64url_decode( $pm[1] ) ) );
} else {
	echo "(node not found: cross-language checks skipped)\n";
}

// Published test vector (docs/HUB-PROTOCOL.md): the hub's implementation must reproduce it.
$vk  = sodium_crypto_sign_seed_keypair( str_repeat( chr( 7 ), 32 ) );
$vb  = '{"action":"run","ability":"bcm/replace-text","input":{"find":"Sat 9-1","replace":"Sat 9-2","post_ids":[12],"approved":true}}';
$vm  = BCM_Hub::message( 'POST', 'kozyhomes.ca', '/bcm-agency/v1/hub', 'hub-1', '1794500000', 'n0nce-Q2hyaXMtdGVzdC12ZWN0b3I', 'a7f3', hash( 'sha256', $vb ) );
t( 'test vector: public key', '6kpsY-KcUgq-9VB7Ey7F-ZVHdq6-vnuSQh7qaRRG0iw' === b64url( sodium_crypto_sign_publickey( $vk ) ) );
t( 'test vector: body hash', '73a4de660bb431ae4853bf0f07b7476b98b9ffd41dc2c198364ed046a6a54da0' === hash( 'sha256', $vb ) );
t( 'test vector: signature', 'fGyzWR7bwPkGnfMudMX-V9iyEYssA_D92MxfD11ksdzKWckNbaJ__vN1pEOUb76kUahNeCocUIHKYEP-f6QQAQ' === b64url( sodium_crypto_sign_detached( $vm, sodium_crypto_sign_secretkey( $vk ) ) ) );

// Admin screen renders with hub settings and the Via column.
wp_set_current_user( 1 );
ob_start();
bcm_agency_render_log_page();
$html = ob_get_clean();
wp_set_current_user( 0 );
t( 'admin screen: hub box', false !== strpos( $html, 'Hub access' ) && false !== strpos( $html, 'name="hub_enabled" value="1"  checked' ) || false !== strpos( $html, 'checked="checked"' ) );
t( 'admin screen: acting user shown', false !== strpos( $html, 'Automatic: oldest administrator (bcmadmin)' ) );
t( 'admin screen: trusted key ids listed', false !== strpos( $html, 'hub-1, hub-2' ) && false !== strpos( $html, 'approve-1' ) );
t( 'admin screen: via hub with actor in log', false !== strpos( $html, 'hub · b8c9' ) );

// wp-config kill switch (last: constants can't be undefined).
define( 'BCM_AGENCY_HUB_DISABLED', true );
update_option( 'bcm_agency_hub_enabled', '1' );
is_code( 'wp-config constant overrides the setting', send(), 'bcm_hub_disabled' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
