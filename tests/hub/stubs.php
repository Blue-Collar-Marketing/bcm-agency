<?php
/**
 * Just enough of WordPress to load BCM Agency Tools and drive the hub endpoint.
 * Hooks, options, users and capabilities behave like WordPress's; the database
 * is in memory but enforces the nonce table's primary key like MySQL.
 * WP_Ability is WordPress 6.9's real class (tests/hub/wp/).
 */

error_reporting( E_ALL );

define( 'MINUTE_IN_SECONDS', 60 );
define( 'ARRAY_A', 'ARRAY_A' );

/* Hooks ------------------------------------------------------------------ */
$GLOBALS['__filters'] = array();
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['__filters'][ $tag ][ $prio ][] = array( $cb, $args );
	return true;
}
function add_action( $tag, $cb, $prio = 10, $args = 1 ) {
	return add_filter( $tag, $cb, $prio, $args );
}
function remove_filter( $tag, $cb, $prio = 10 ) {
	if ( empty( $GLOBALS['__filters'][ $tag ][ $prio ] ) ) {
		return false;
	}
	foreach ( $GLOBALS['__filters'][ $tag ][ $prio ] as $i => $entry ) {
		if ( $entry[0] == $cb ) { // phpcs:ignore
			unset( $GLOBALS['__filters'][ $tag ][ $prio ][ $i ] );
			return true;
		}
	}
	return false;
}
function has_filter( $tag, $cb ) {
	foreach ( isset( $GLOBALS['__filters'][ $tag ] ) ? $GLOBALS['__filters'][ $tag ] : array() as $list ) {
		foreach ( $list as $entry ) {
			if ( $entry[0] == $cb ) { // phpcs:ignore
				return true;
			}
		}
	}
	return false;
}
function apply_filters( $tag, $value, ...$rest ) {
	if ( empty( $GLOBALS['__filters'][ $tag ] ) ) {
		return $value;
	}
	$hooks = $GLOBALS['__filters'][ $tag ];
	ksort( $hooks );
	foreach ( $hooks as $list ) {
		foreach ( $list as $entry ) {
			$value = call_user_func_array( $entry[0], array_slice( array_merge( array( $value ), $rest ), 0, max( 1, $entry[1] ) ) );
		}
	}
	return $value;
}
function do_action( $tag, ...$args ) {
	if ( empty( $GLOBALS['__filters'][ $tag ] ) ) {
		return;
	}
	$hooks = $GLOBALS['__filters'][ $tag ];
	ksort( $hooks );
	foreach ( $hooks as $list ) {
		foreach ( $list as $entry ) {
			call_user_func_array( $entry[0], array_slice( $args, 0, $entry[1] ) );
		}
	}
}

/* Basics ----------------------------------------------------------------- */
function __( $s ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return (string) $s; }
function wp_kses_post( $s ) { return (string) $s; }
function _doing_it_wrong( $f, $m, $v ) { $GLOBALS['__doing_it_wrong'][] = $f . ': ' . $m; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function current_time( $type, $gmt = false ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_rand( $min = 0, $max = 0 ) { return mt_rand( $min, $max ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function get_bloginfo( $k ) { return 'version' === $k ? '6.9' : ''; }
function home_url( $path = '' ) { return get_option( 'home' ) . $path; }
function site_url( $path = '' ) { return get_option( 'siteurl' ) . $path; }
function admin_url( $p = '' ) { return get_option( 'siteurl' ) . '/wp-admin/' . $p; }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function register_activation_hook( $f, $cb ) {}
function absint( $v ) { return abs( (int) $v ); }
function rest_validate_value_from_schema( $value, $schema, $param = '' ) {
	if ( isset( $schema['type'] ) && 'object' === $schema['type'] && ! is_array( $value ) ) {
		return new WP_Error( 'rest_invalid_type', $param . ' is not of type object.' );
	}
	return true;
}

class WP_Error {
	public $code, $message, $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }

/* Options and transients ------------------------------------------------- */
$GLOBALS['__options'] = array(
	'home'    => 'https://kozyhomes.ca',
	'siteurl' => 'https://kozyhomes.ca',
);
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__options'][ $k ] ); return true; }
function get_transient( $k ) { return get_option( '_transient_' . $k ); }
function set_transient( $k, $v, $ttl = 0 ) { return update_option( '_transient_' . $k, $v ); }
function delete_transient( $k ) { return delete_option( '_transient_' . $k ); }

/* Users and capabilities ------------------------------------------------- */
class WP_User {
	public $ID, $user_login, $roles, $allcaps;
	public function __construct( $id, $login, $role, array $caps ) {
		$this->ID         = $id;
		$this->user_login = $login;
		$this->roles      = array( $role );
		$this->allcaps    = array_fill_keys( $caps, true );
	}
	public function has_cap( $cap ) {
		$all = apply_filters( 'user_has_cap', $this->allcaps, array( $cap ), array( $cap, $this->ID ), $this );
		return ! empty( $all[ $cap ] );
	}
}
$admin_caps = array(
	'manage_options', 'edit_posts', 'edit_pages', 'edit_others_posts', 'publish_posts', 'upload_files',
	'unfiltered_html', 'unfiltered_upload', 'install_plugins', 'activate_plugins', 'edit_plugins',
	'create_users', 'promote_users', 'update_plugins', 'edit_theme_options', 'manage_categories', 'list_users',
);
$GLOBALS['__users'] = array(
	1 => new WP_User( 1, 'bcmadmin', 'administrator', $admin_caps ),
	2 => new WP_User( 2, 'editor', 'editor', array( 'edit_posts', 'edit_pages', 'unfiltered_html' ) ),
	3 => new WP_User( 3, 'second-admin', 'administrator', $admin_caps ),
);
$GLOBALS['__current_user'] = 0;
function get_userdata( $id ) { return isset( $GLOBALS['__users'][ (int) $id ] ) ? $GLOBALS['__users'][ (int) $id ] : false; }
function get_users( $args ) {
	$out = array();
	foreach ( $GLOBALS['__users'] as $u ) {
		if ( empty( $args['role'] ) || in_array( $args['role'], $u->roles, true ) ) {
			$out[] = ( isset( $args['fields'] ) && 'ID' === $args['fields'] ) ? $u->ID : $u;
		}
	}
	return $out;
}
function user_can( $user, $cap ) {
	$u = is_object( $user ) ? $user : get_userdata( $user );
	return $u ? $u->has_cap( $cap ) : false;
}
function wp_set_current_user( $id ) {
	$GLOBALS['__current_user'] = (int) $id;
	do_action( 'set_current_user' );
	return wp_get_current_user();
}
function wp_get_current_user() {
	$u = get_userdata( $GLOBALS['__current_user'] );
	return $u ? $u : new WP_User( 0, '', 'none', array() );
}
function get_current_user_id() { return (int) $GLOBALS['__current_user']; }
function current_user_can( $cap ) { $u = get_userdata( $GLOBALS['__current_user'] ); return $u ? $u->has_cap( $cap ) : false; }

// WordPress switches its content filters (kses) on whenever the user changes.
$GLOBALS['__kses_on'] = true;
function kses_init() { $GLOBALS['__kses_on'] = ! current_user_can( 'unfiltered_html' ); }
add_action( 'set_current_user', 'kses_init' );

/* Abilities API ---------------------------------------------------------- */
require __DIR__ . '/wp/class-wp-ability.php';
$GLOBALS['__abilities'] = array();
function wp_register_ability( $name, $args ) {
	$GLOBALS['__abilities'][ $name ] = new WP_Ability( $name, $args );
	return $GLOBALS['__abilities'][ $name ];
}
function wp_register_ability_category( $slug, $args ) { return null; }
function wp_has_ability( $name ) { return isset( $GLOBALS['__abilities'][ $name ] ); }
function wp_get_ability( $name ) {
	if ( ! isset( $GLOBALS['__abilities'][ $name ] ) ) {
		_doing_it_wrong( __FUNCTION__, 'Ability not found: ' . $name, '6.9.0' );
		return null;
	}
	return $GLOBALS['__abilities'][ $name ];
}
function wp_get_abilities() { return array_values( $GLOBALS['__abilities'] ); }

/* REST ------------------------------------------------------------------- */
$GLOBALS['__routes'] = array();
function register_rest_route( $ns, $route, $args ) { $GLOBALS['__routes'][ '/' . $ns . $route ] = $args; }
class WP_REST_Request {
	private $method, $route, $headers = array(), $body = '';
	public function __construct( $method, $route ) { $this->method = $method; $this->route = $route; }
	private static function canon( $k ) { return str_replace( '-', '_', strtolower( $k ) ); }
	public function set_header( $k, $v ) { $this->headers[ self::canon( $k ) ] = array( $v ); }
	public function get_header( $k ) { $k = self::canon( $k ); return isset( $this->headers[ $k ] ) ? implode( ',', $this->headers[ $k ] ) : null; }
	public function set_body( $b ) { $this->body = $b; }
	public function get_body() { return $this->body; }
	public function get_route() { return $this->route; }
	public function get_method() { return $this->method; }
}
class WP_REST_Response {
	public $data;
	public function __construct( $data ) { $this->data = $data; }
	public function get_data() { return $this->data; }
}
function rest_ensure_response( $r ) { return $r instanceof WP_REST_Response ? $r : new WP_REST_Response( $r ); }

/** Mirrors WP_REST_Server: permission callback first, then the callback. */
function bcm_test_dispatch( WP_REST_Request $req ) {
	$route = $GLOBALS['__routes'][ $req->get_route() ];
	if ( strtoupper( $req->get_method() ) !== $route['methods'] ) {
		return new WP_Error( 'rest_no_route', 'No route', array( 'status' => 404 ) );
	}
	$ok = call_user_func( $route['permission_callback'], $req );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	if ( true !== $ok ) {
		return new WP_Error( 'rest_forbidden', 'Forbidden', array( 'status' => 401 ) );
	}
	return call_user_func( $route['callback'], $req );
}

/* Admin screen ---------------------------------------------------------- */
function wp_nonce_field( $a ) { echo '<input type="hidden" name="_wpnonce" value="x">'; }
function checked( $a, $b = true, $echo = true ) { return $a == $b ? ' checked="checked"' : ''; } // phpcs:ignore
function selected( $a, $b = true, $echo = true ) { return $a == $b ? ' selected="selected"' : ''; } // phpcs:ignore
function submit_button( $t ) { echo '<button>' . esc_html( $t ) . '</button>'; }
function get_post( $id ) { return null; }
function get_edit_post_link( $id ) { return ''; }

/* Database --------------------------------------------------------------- */
class Fake_WPDB {
	public $prefix = 'wp_';
	public $last_error = '';
	public $log = array();
	public $nonces = array();
	public $nonce_table_exists = false;
	public $ddl = array();
	private $suppress = false;
	public function get_charset_collate() { return ''; }
	public function suppress_errors( $s = true ) { $o = $this->suppress; $this->suppress = $s; return $o; }
	public function prepare( $q, ...$args ) {
		$i = 0;
		return preg_replace_callback(
			'/%[sd]/',
			function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
			},
			$q
		);
	}
	public function query( $sql ) {
		$this->last_error = '';
		if ( preg_match( "/^INSERT INTO wp_bcm_agency_hub_nonces \(nonce, seen\) VALUES \('([^']*)', (\d+)\)$/", $sql, $m ) ) {
			if ( ! $this->nonce_table_exists ) {
				$this->last_error = "Table 'wp_bcm_agency_hub_nonces' doesn't exist";
				return false;
			}
			if ( isset( $this->nonces[ $m[1] ] ) ) {
				$this->last_error = "Duplicate entry '{$m[1]}' for key 'PRIMARY'";
				return false;
			}
			$this->nonces[ $m[1] ] = (int) $m[2];
			return 1;
		}
		if ( preg_match( '/^DELETE FROM wp_bcm_agency_hub_nonces WHERE seen < (\d+)$/', $sql, $m ) ) {
			$n = 0;
			foreach ( $this->nonces as $k => $seen ) {
				if ( $seen < (int) $m[1] ) {
					unset( $this->nonces[ $k ] );
					$n++;
				}
			}
			return $n;
		}
		$this->last_error = 'Unexpected query: ' . $sql;
		return false;
	}
	public function insert( $table, $data, $format ) {
		if ( 'wp_bcm_agency_log' !== $table ) {
			throw new Exception( 'insert into unexpected table ' . $table );
		}
		if ( count( $data ) !== count( $format ) ) {
			throw new Exception( 'format count mismatch' );
		}
		$this->log[] = $data;
		return 1;
	}
	public function get_results( $q, $o ) { return array_reverse( $this->log ); }
}
$GLOBALS['wpdb'] = new Fake_WPDB();
