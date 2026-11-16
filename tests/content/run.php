<?php
/**
 * BCM Agency Tools 1.4.3: redirects (Redirection plugin), make private, trash
 * with redirect, page publish/unpublish, protected pages and HTML embeds.
 *
 *     php tests/content/run.php
 *
 * Redirection is faked with its real table layout on SQLite; WordPress posts,
 * options and capabilities are small in-memory stubs.
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	public $code, $message, $data;
	public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
class WP_Post {
	public $ID, $post_type, $post_status, $post_title, $post_name, $post_date = '2026-01-01 10:00:00', $post_date_gmt = '2026-01-01 10:00:00', $post_modified = '', $post_excerpt = '', $post_content = '', $post_author = 1;
	public function __construct( array $a ) { foreach ( $a as $k => $v ) { $this->$k = $v; } }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }

$GLOBALS['posts']   = array();
$GLOBALS['meta']    = array();
$GLOBALS['options'] = array( 'page_on_front' => 10, 'page_for_posts' => 11, 'wp_page_for_privacy_policy' => 12 );
$GLOBALS['caps']    = array( 'edit_post' => true, 'delete_post' => true, 'publish_posts' => true, 'publish_pages' => true, 'unfiltered_html' => true, 'edit_pages' => true, 'edit_others_posts' => true );
$GLOBALS['hub_confirmed'] = false;
$GLOBALS['hub_running']   = false;
function add_post( $id, $type, $status, $title, $slug ) { $GLOBALS['posts'][ $id ] = new WP_Post( array( 'ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_title' => $title, 'post_name' => $slug ) ); }
function get_post( $id ) { return isset( $GLOBALS['posts'][ (int) $id ] ) ? $GLOBALS['posts'][ (int) $id ] : null; }
function get_post_status( $id ) { $p = get_post( $id ); return $p ? $p->post_status : false; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function current_user_can( $cap, $id = 0 ) { return ! empty( $GLOBALS['caps'][ $cap ] ); }
function home_url( $path = '' ) { return 'https://www.kozyhomes.ca' . $path; }
function get_permalink( $p ) { $p = is_object( $p ) ? $p : get_post( $p ); return home_url( '/' . $p->post_name . '/' ); }
function get_preview_post_link( $p ) { return get_permalink( $p ) . '?preview=true'; }
function admin_url( $p = '' ) { return 'https://www.kozyhomes.ca/wp-admin/' . $p; }
function get_the_title( $p ) { $p = is_object( $p ) ? $p : get_post( $p ); return $p ? $p->post_title : ''; }
function get_the_category( $id ) { return array(); }
function wp_get_post_tags( $id ) { return array(); }
function get_post_thumbnail_id( $p ) { return 0; }
function wp_get_post_categories( $id ) { return array(); }
function has_post_thumbnail( $p = null ) { return false; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function get_post_field( $f, $p ) { $p = is_object( $p ) ? $p : get_post( $p ); return isset( $p->$f ) ? $p->$f : ''; }
function wp_get_attachment_image_url( $id ) { return ''; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function maybe_unserialize( $v ) { $u = @unserialize( $v ); return false === $u && 'b:0;' !== $v ? $v : $u; }
function wp_slash( $v ) { return $v; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_rand() { return mt_rand(); }
function get_post_meta( $id, $k, $single = false ) { return isset( $GLOBALS['meta'][ $id ][ $k ] ) ? $GLOBALS['meta'][ $id ][ $k ] : ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; return true; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['meta'][ $id ][ $k ] ); return true; }
function url_to_postid( $url ) { foreach ( $GLOBALS['posts'] as $p ) { if ( rtrim( get_permalink( $p ), '/' ) === rtrim( $url, '/' ) ) { return $p->ID; } } return 0; }
function wp_update_post( $a, $err = false ) { $p = get_post( $a['ID'] ); foreach ( $a as $k => $v ) { if ( 'ID' !== $k && property_exists( $p, $k ) ) { $p->$k = $v; } } return $p->ID; }
function wp_trash_post( $id ) { $p = get_post( $id ); $p->post_status = 'trash'; $p->post_name .= '__trashed'; return $p; }
function current_time( $t, $gmt = false ) { return '2026-11-15 10:00:00'; }
function bcm_agency_purge_page_cache() { return array( 'test' ); }
function add_action() {}
class BCM_Hub { public static function running() { return $GLOBALS['hub_running']; } public static function running_confirmed() { return $GLOBALS['hub_running'] && $GLOBALS['hub_confirmed']; } }

// Redirection's tables, on SQLite.
class Fake_WPDB {
	public $prefix = 'wp_';
	public $db;
	public function __construct() {
		$this->db = new PDO( 'sqlite::memory:' );
		$this->db->exec( 'CREATE TABLE wp_redirection_groups (id INTEGER PRIMARY KEY, name TEXT, module_id INTEGER, status TEXT, position INTEGER)' );
		$this->db->exec( 'CREATE TABLE wp_redirection_items (id INTEGER PRIMARY KEY AUTOINCREMENT, url TEXT, regex INTEGER, group_id INTEGER, status TEXT, action_type TEXT, action_code INTEGER, action_data TEXT, match_type TEXT, title TEXT, last_count INTEGER DEFAULT 0, last_access TEXT)' );
		$this->db->exec( "INSERT INTO wp_redirection_groups VALUES (1,'Redirections',1,'enabled',0),(2,'Modified posts',1,'enabled',1),(3,'Apache',2,'enabled',0)" );
	}
	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	public function prepare( $q, ...$args ) {
		$args = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
		$i    = 0;
		return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) { $v = $args[ $i++ ]; return '%d' === $m[0] ? (string) (int) $v : $this->db->quote( (string) $v ); }, $q );
	}
	public function get_var( $sql ) {
		if ( preg_match( "/^SHOW TABLES LIKE '([^']+)'$/", trim( $sql ), $m ) ) {
			$r = $this->db->query( "SELECT name FROM sqlite_master WHERE type = 'table' AND name = " . $this->db->quote( $m[1] ) )->fetch( PDO::FETCH_NUM );
			return $r ? $r[0] : null;
		}
		try { $r = $this->db->query( $sql )->fetch( PDO::FETCH_NUM ); } catch ( PDOException $e ) { return null; } // Like wpdb: a missing table returns nothing.
		return $r ? $r[0] : null;
	}
	public function get_results( $sql, $o = null ) { try { return $this->db->query( $sql )->fetchAll( PDO::FETCH_ASSOC ); } catch ( PDOException $e ) { return array(); } }
}
$GLOBALS['wpdb'] = new Fake_WPDB();

$plugin = dirname( __DIR__, 2 ) . '/bcm-agency/includes';
require $plugin . '/helpers.php';
require $plugin . '/abilities-posts.php';
require $plugin . '/abilities-redirects.php';
require $plugin . '/abilities-content.php';
require $plugin . '/abilities-embed.php';

$pass = 0; $fail = 0;
function t( $name, $cond, $detail = '' ) { global $pass, $fail; if ( $cond ) { $pass++; } else { $fail++; echo "FAIL: $name" . ( $detail ? "\n      $detail" : '' ) . "\n"; } }
function code( $r ) { return is_wp_error( $r ) ? $r->code : 'ok'; }
function redirects() { return $GLOBALS['wpdb']->db->query( 'SELECT * FROM wp_redirection_items ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC ); }

add_post( 10, 'page', 'publish', 'Home', 'home' );
add_post( 11, 'page', 'publish', 'Blog', 'blog' );
add_post( 12, 'page', 'publish', 'Privacy', 'privacy-policy' );
add_post( 20, 'page', 'publish', 'Concrete Kingston', 'concrete-kingston' );
add_post( 21, 'page', 'publish', 'Concrete Services', 'concrete-services' );
add_post( 22, 'page', 'draft', 'New Service', 'new-service' );
add_post( 30, 'post', 'publish', 'Spring Tips', 'spring-tips' );
add_post( 40, 'attachment', 'inherit', 'Logo', 'logo' );

// --- Redirection not active ---------------------------------------------------
t( 'no Redirection: clear error', 'bcm_no_redirection' === code( bcm_agency_add_redirect( array( 'source' => '/a/', 'target' => '/b/', 'approved' => true ) ) ) );
t( 'no Redirection: trash with redirect stops before trashing', 'bcm_no_redirection' === code( bcm_agency_trash_post( array( 'post_id' => 20, 'redirect_to' => '/concrete-services/', 'approved' => true ) ) ) && 'publish' === get_post( 20 )->post_status );

// Redirection's API, as the plugin defines it (create runs its sanitizer and inserts a row).
eval( '
class Red_Group {
	private $id;
	public function __construct( $id ) { $this->id = $id; }
	public static function create( $name, $module_id, $enabled = true ) {
		$w = $GLOBALS["wpdb"]->db;
		$w->exec( "INSERT INTO wp_redirection_groups (name, module_id, status, position) VALUES (" . $w->quote( $name ) . ", " . (int) $module_id . ", \'enabled\', 0)" );
		return new Red_Group( (int) $w->lastInsertId() );
	}
	public function get_id() { return $this->id; }
}
class Red_Item {
	private $row;
	public function __construct( $row ) { $this->row = $row; }
	public static function create( array $d ) {
		$GLOBALS["red_creates"][] = $d;
		$w = $GLOBALS["wpdb"]->db;
		$st = $w->prepare( "INSERT INTO wp_redirection_items (url, regex, group_id, status, action_type, action_code, action_data, match_type, title) VALUES (?, 0, ?, \'enabled\', ?, ?, ?, ?, ?)" );
		$st->execute( array( $d["url"], $d["group_id"], $d["action_type"], $d["action_code"], $d["action_data"]["url"], $d["match_type"], $d["title"] ) );
		return self::get_by_id( (int) $w->lastInsertId() );
	}
	public static function get_by_id( $id ) { $r = $GLOBALS["wpdb"]->db->query( "SELECT * FROM wp_redirection_items WHERE id = " . (int) $id )->fetch( PDO::FETCH_ASSOC ); return $r ? new Red_Item( $r ) : false; }
	public function get_id() { return (int) $this->row["id"]; }
	public function get_url() { return $this->row["url"]; }
	public function delete() { $GLOBALS["wpdb"]->db->exec( "DELETE FROM wp_redirection_items WHERE id = " . (int) $this->row["id"] ); }
}' );

// --- add-redirect ---------------------------------------------------------------
$r = bcm_agency_add_redirect( array( 'source' => '/old-kingston/', 'target' => '/concrete-kingston/' ) );
t( 'add-redirect needs approval, says what it does', 'bcm_needs_approval' === code( $r ) && false !== strpos( $r->message, '/old-kingston/ to /concrete-kingston/ (301)' ) && ! redirects() );
$r = bcm_agency_add_redirect( array( 'source' => '/old-kingston/', 'target' => 'https://kozyhomes.ca/concrete-kingston/', 'approved' => true ) );
$rows = redirects();
t( 'add-redirect: same-site URL becomes a path; 301 in the WordPress group', 'ok' === code( $r ) && 1 === count( $rows ) && '/old-kingston/' === $rows[0]['url'] && '/concrete-kingston/' === $rows[0]['action_data'] && 301 === (int) $rows[0]['action_code'] && 1 === (int) $rows[0]['group_id'] && 'url' === $rows[0]['match_type'], json_encode( $rows ) );
t( 'add-redirect: no regex, labelled', array( 'url' ) === array_keys( $GLOBALS['red_creates'][0]['action_data'] ) && false === $GLOBALS['red_creates'][0]['regex'] && 'Added by BCM Agency Tools' === $GLOBALS['red_creates'][0]['title'] );
t( 'add-redirect: log has no paths', array( 'action', 'id', 'code' ) === array_keys( $r['_log'] ) );
t( 'duplicate source refused (trailing slash ignored)', 'bcm_redirect_exists' === code( bcm_agency_add_redirect( array( 'source' => '/old-kingston', 'target' => '/concrete-services/', 'approved' => true ) ) ) );
foreach ( array(
	array( 'https://evil.example/x', '/concrete-kingston/', 'bcm_external_redirect', 'source on another domain' ),
	array( '/somewhere/', 'https://evil.example/', 'bcm_external_redirect', 'target on another domain' ),
	array( '/somewhere/', '//evil.example/', 'bcm_bad_input', 'protocol-relative target' ),
	array( '/somewhere/', 'javascript:alert(1)', 'bcm_bad_input', 'script target' ),
	array( '/', '/concrete-kingston/', 'bcm_bad_input', 'homepage source' ),
	array( '/?p=1', '/concrete-kingston/', 'bcm_bad_input', 'homepage with a query' ),
	array( '/wp-admin/', '/concrete-kingston/', 'bcm_bad_input', 'wp-admin source' ),
	array( '/wp-login.php', '/concrete-kingston/', 'bcm_bad_input', 'login source' ),
	array( '/same/', '/same', 'bcm_bad_input', 'source equals target' ),
	array( '/a b/', '/c/', 'bcm_bad_input', 'space in path' ),
) as $c ) {
	t( 'refused: ' . $c[3], $c[2] === code( bcm_agency_add_redirect( array( 'source' => $c[0], 'target' => $c[1], 'approved' => true ) ) ), code( bcm_agency_add_redirect( array( 'source' => $c[0], 'target' => $c[1], 'approved' => true ) ) ) );
}
t( 'bad code refused', 'bcm_bad_input' === code( bcm_agency_add_redirect( array( 'source' => '/x/', 'target' => '/y/', 'code' => 404, 'approved' => true ) ) ) );
$r = bcm_agency_add_redirect( array( 'source' => '/concrete-services/', 'target' => '/old-kingston/' ) );
t( 'warns about a live page and a chain before approval', 'bcm_needs_approval' === code( $r ) && false !== strpos( $r->message, 'live page (id 21)' ) && false !== strpos( $r->message, 'chain' ) );
t( 'nothing created by refusals', 1 === count( redirects() ) );

// --- list / remove ----------------------------------------------------------------
$GLOBALS['wpdb']->db->exec( "INSERT INTO wp_redirection_items (url, regex, group_id, status, action_type, action_code, action_data, match_type, title) VALUES ('/apache-only/', 0, 3, 'enabled', 'url', 301, '/x/', 'url', '')" );
$l = bcm_agency_list_redirects( array() );
t( 'list shows WordPress-group redirects only', 1 === $l['count'] && '/old-kingston/' === $l['redirects'][0]['source'] && '/concrete-kingston/' === $l['redirects'][0]['target'] && true === $l['redirects'][0]['enabled'] );
t( 'list search', 1 === bcm_agency_list_redirects( array( 'search' => 'kingston' ) )['count'] && 0 === bcm_agency_list_redirects( array( 'search' => 'nothing' ) )['count'] );
$id = $l['redirects'][0]['id'];
t( 'remove needs approval', 'bcm_needs_approval' === code( bcm_agency_remove_redirect( array( 'id' => $id ) ) ) && 2 === count( redirects() ) );
$apache = (int) $GLOBALS['wpdb']->get_var( "SELECT id FROM wp_redirection_items WHERE url = '/apache-only/'" );
t( 'remove refuses other modules', 'bcm_not_found' === code( bcm_agency_remove_redirect( array( 'id' => $apache, 'approved' => true ) ) ) );
t( 'remove works', 'ok' === code( bcm_agency_remove_redirect( array( 'id' => $id, 'approved' => true ) ) ) && 1 === count( redirects() ) );
t( 'remove unknown id', 'bcm_not_found' === code( bcm_agency_remove_redirect( array( 'id' => 999, 'approved' => true ) ) ) );

// --- Redirection installed but its setup screen never opened (1.4.5) ---------------------
$GLOBALS['wpdb']->db->exec( "UPDATE wp_redirection_groups SET status = 'disabled' WHERE module_id = 1" );
$r = bcm_agency_add_redirect( array( 'source' => '/never-set-up/', 'target' => '/concrete-services/', 'approved' => true ) );
$grp = $GLOBALS['wpdb']->db->query( "SELECT id, name, module_id, status FROM wp_redirection_groups WHERE status = 'enabled' AND module_id = 1" )->fetchAll( PDO::FETCH_ASSOC );
t( 'missing group: created like Redirection\'s own setup, redirect added to it', 'ok' === code( $r ) && 1 === count( $grp ) && 'Redirections' === $grp[0]['name'] && (int) $grp[0]['id'] === (int) $GLOBALS['wpdb']->get_var( "SELECT group_id FROM wp_redirection_items WHERE url = '/never-set-up/'" ), json_encode( array( $r, $grp ) ) );
$GLOBALS['wpdb']->db->exec( 'ALTER TABLE wp_redirection_groups RENAME TO wp_redirection_groups_off' );
$GLOBALS['wpdb']->db->exec( 'CREATE TABLE wp_redirection_groups (id INTEGER PRIMARY KEY, name TEXT, module_id INTEGER, status TEXT, position INTEGER)' );
$GLOBALS['wpdb']->db->exec( 'DROP TABLE wp_redirection_groups' );
$r = bcm_agency_add_redirect( array( 'source' => '/no-tables/', 'target' => '/concrete-services/', 'approved' => true ) );
t( 'no Redirection tables at all: clear setup message, nothing created', is_wp_error( $r ) && false !== strpos( $r->message, 'Tools → Redirection once' ), is_wp_error( $r ) ? $r->message : 'ok' );
$GLOBALS['wpdb']->db->exec( 'ALTER TABLE wp_redirection_groups_off RENAME TO wp_redirection_groups' );

// --- make private -------------------------------------------------------------------
t( 'private: homepage refused', 'bcm_protected_page' === code( bcm_agency_make_private( array( 'post_id' => 10, 'approved' => true ) ) ) );
t( 'private: blog page refused', 'bcm_protected_page' === code( bcm_agency_make_private( array( 'post_id' => 11, 'approved' => true ) ) ) );
t( 'private: privacy page refused', 'bcm_protected_page' === code( bcm_agency_make_private( array( 'post_id' => 12, 'approved' => true ) ) ) );
t( 'private: media refused', 'bcm_not_found' === code( bcm_agency_make_private( array( 'post_id' => 40, 'approved' => true ) ) ) );
t( 'private: needs approval', 'bcm_needs_approval' === code( bcm_agency_make_private( array( 'post_id' => 30 ) ) ) && 'publish' === get_post( 30 )->post_status );
$r = bcm_agency_make_private( array( 'post_id' => 30, 'approved' => true ) );
t( 'private: blog post made private', 'ok' === code( $r ) && 'private' === get_post( 30 )->post_status && 'publish' === $r['was'] );
t( 'private: already private', 'bcm_no_change' === code( bcm_agency_make_private( array( 'post_id' => 30, 'approved' => true ) ) ) );

// --- publish / unpublish pages ---------------------------------------------------------
t( 'publish: needs approval', 'bcm_needs_approval' === code( bcm_agency_publish_post( array( 'post_id' => 22 ) ) ) );
$GLOBALS['caps']['publish_pages'] = false;
t( 'publish page: needs publish_pages', 'bcm_forbidden' === code( bcm_agency_publish_post( array( 'post_id' => 22, 'approved' => true ) ) ) && 'draft' === get_post( 22 )->post_status );
$GLOBALS['caps']['publish_pages'] = true;
t( 'publish: works for a page', 'ok' === code( bcm_agency_publish_post( array( 'post_id' => 22, 'approved' => true ) ) ) && 'publish' === get_post( 22 )->post_status );
add_post( 23, 'page', 'draft', 'Elementor Page', 'elementor-page' );
$GLOBALS['meta'][23]['_elementor_edit_mode'] = 'builder';
$r = bcm_agency_publish_post( array( 'post_id' => 23, 'approved' => true ) );
t( 'publish: no false "Content is empty" for Elementor pages', 'ok' === code( $r ) && ! in_array( 'Content is empty.', $r['warnings'], true ), json_encode( is_array( $r ) ? $r['warnings'] : $r ) );
$r = bcm_agency_unpublish_post( array( 'post_id' => 23, 'approved' => true ) );
t( 'unpublish: clear wording', 'ok' === code( $r ) && 0 === strpos( $r['note'], 'It is a draft again' ) );
t( 'unpublish: homepage refused', 'bcm_protected_page' === code( bcm_agency_unpublish_post( array( 'post_id' => 10, 'approved' => true ) ) ) && 'publish' === get_post( 10 )->post_status );
t( 'unpublish: works for a page', 'ok' === code( bcm_agency_unpublish_post( array( 'post_id' => 22, 'approved' => true ) ) ) && 'draft' === get_post( 22 )->post_status );

// --- trash ---------------------------------------------------------------------------------
t( 'trash: homepage refused', 'bcm_protected_page' === code( bcm_agency_trash_post( array( 'post_id' => 10, 'approved' => true ) ) ) );
$GLOBALS['caps']['delete_post'] = false;
t( 'trash: needs delete permission', 'bcm_forbidden' === code( bcm_agency_trash_post( array( 'post_id' => 20, 'approved' => true ) ) ) );
$GLOBALS['caps']['delete_post'] = true;
$r = bcm_agency_trash_post( array( 'post_id' => 20, 'redirect_to' => '/concrete-services/' ) );
t( 'trash: approval message names the redirect', 'bcm_needs_approval' === code( $r ) && false !== strpos( $r->message, '/concrete-kingston/ will redirect (301) to /concrete-services/' ) && 'publish' === get_post( 20 )->post_status );
t( 'trash: external redirect target stops everything', 'bcm_external_redirect' === code( bcm_agency_trash_post( array( 'post_id' => 20, 'redirect_to' => 'https://other.example/', 'approved' => true ) ) ) && 'publish' === get_post( 20 )->post_status );
t( 'trash: draft with redirect refused', 'bcm_bad_input' === code( bcm_agency_trash_post( array( 'post_id' => 22, 'redirect_to' => '/concrete-services/', 'approved' => true ) ) ) );
$before = count( redirects() );
$r = bcm_agency_trash_post( array( 'post_id' => 20, 'redirect_to' => '/concrete-services/', 'approved' => true ) );
$last = redirects()[ count( redirects() ) - 1 ];
t( 'trash: page trashed, old address redirects', 'ok' === code( $r ) && 'trash' === get_post( 20 )->post_status && $before + 1 === count( redirects() ) && '/concrete-kingston/' === $last['url'] && '/concrete-services/' === $last['action_data'] && $r['redirect']['id'] === (int) $last['id'], json_encode( $r ) );
t( 'trash: result says how to restore', false !== strpos( $r['note'], 'Pages → Trash' ) && $r['_log']['redirect_id'] === (int) $last['id'] );
t( 'trash: already trashed', 'bcm_trashed' === code( bcm_agency_trash_post( array( 'post_id' => 20, 'approved' => true ) ) ) );
$r = bcm_agency_trash_post( array( 'post_id' => 22 ) );
t( 'trash: draft needs approval, no redirect suggestion', 'bcm_needs_approval' === code( $r ) && false === strpos( $r->message, 'redirect' ) );

// --- HTML embeds --------------------------------------------------------------------------
$tree = array(
	array( 'id' => 'hero01', 'elType' => 'container', 'settings' => array(), 'elements' => array(
		array( 'id' => 'head01', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Hi' ), 'elements' => array() ),
		array( 'id' => 'html01', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => array( 'html' => '<p>old</p>' ), 'elements' => array() ),
	) ),
	array( 'id' => 'v4box1', 'elType' => 'e-div-block', 'settings' => array(), 'elements' => array() ),
	array( 'id' => 'foot01', 'elType' => 'container', 'settings' => array(), 'elements' => array() ),
);
add_post( 50, 'page', 'publish', 'Calculator', 'calculator' );
$GLOBALS['meta'][50]['_elementor_edit_mode'] = 'builder';
$reset = function () use ( $tree ) { $GLOBALS['meta'][50]['_elementor_data'] = json_encode( $tree ); };
$data  = function () { return json_decode( $GLOBALS['meta'][50]['_elementor_data'], true ); };
$code  = '<div id="yardage"><input id="l"><input id="w"><output id="o"></output></div><script>(function(){var l=document.getElementById("l");})();</script>';

$reset();
$GLOBALS['caps']['unfiltered_html'] = false;
t( 'embed: refused without unfiltered_html outside the hub', 'bcm_forbidden' === code( bcm_agency_add_html_embed( array( 'post_id' => 50, 'html' => $code, 'approved' => true ) ) ) );
$GLOBALS['hub_running'] = true;
$GLOBALS['hub_confirmed'] = true;
t( 'embed: allowed through the hub with Chris\'s confirmation', 'ok' === code( bcm_agency_add_html_embed( array( 'post_id' => 50, 'html' => $code, 'approved' => true ) ) ) );
$GLOBALS['caps']['unfiltered_html'] = true; // The hub's user keeps unfiltered_html since 1.4.4...
$GLOBALS['hub_confirmed'] = false;
t( 'embed: through the hub WITHOUT Chris\'s confirmation refused, even with unfiltered_html', 'bcm_forbidden' === code( bcm_agency_add_html_embed( array( 'post_id' => 50, 'html' => $code, 'approved' => true ) ) ) );
$GLOBALS['hub_running'] = false;

$reset();
t( 'embed: needs approval, nothing saved', 'bcm_needs_approval' === code( bcm_agency_add_html_embed( array( 'post_id' => 50, 'html' => $code, 'after_id' => 'hero01' ) ) ) && $data() === $tree );
$r = bcm_agency_add_html_embed( array( 'post_id' => 50, 'html' => $code, 'after_id' => 'hero01', 'label' => 'Yardage calculator', 'approved' => true ) );
$d = $data();
t( 'embed: own section after hero01, exact code stored', 'ok' === code( $r ) && 4 === count( $d ) && 'container' === $d[1]['elType'] && 'html' === $d[1]['elements'][0]['widgetType'] && $code === $d[1]['elements'][0]['settings']['html'] && 'Yardage calculator' === $d[1]['elements'][0]['settings']['_title'] && $r['element_id'] === $d[1]['elements'][0]['id'], json_encode( $d ) );
t( 'embed: everything else untouched', $d[0] === $tree[0] && $d[2] === $tree[1] && $d[3] === $tree[2] );
t( 'embed: new ids unique, 7 hex', preg_match( '/^[0-9a-f]{7}$/', $d[1]['id'] ) && $d[1]['id'] !== $d[1]['elements'][0]['id'] );
t( 'embed: log has size and hash, never the code', $r['_log']['bytes'] === strlen( $code ) && hash( 'sha256', $code ) === $r['_log']['sha256'] && false === strpos( json_encode( $r['_log'] ), 'script' ) );

$reset();
$r = bcm_agency_add_html_embed( array( 'post_id' => 50, 'html' => $code, 'parent_id' => 'hero01', 'after_id' => 'head01', 'approved' => true ) );
$d = $data();
t( 'embed: inside a container, after a given child', 'ok' === code( $r ) && 3 === count( $d[0]['elements'] ) && 'head01' === $d[0]['elements'][0]['id'] && $code === $d[0]['elements'][1]['settings']['html'] && 'html01' === $d[0]['elements'][2]['id'] );
$reset();
t( 'embed: Atomic parent refused', 'bcm_bad_input' === code( bcm_agency_add_html_embed( array( 'post_id' => 50, 'html' => $code, 'parent_id' => 'v4box1', 'approved' => true ) ) ) && $data() === $tree );
t( 'embed: after_id must be a child', 'bcm_bad_input' === code( bcm_agency_add_html_embed( array( 'post_id' => 50, 'html' => $code, 'parent_id' => 'hero01', 'after_id' => 'foot01', 'approved' => true ) ) ) );
t( 'embed: unknown parent', 'bcm_not_found' === code( bcm_agency_add_html_embed( array( 'post_id' => 50, 'html' => $code, 'parent_id' => 'nope', 'approved' => true ) ) ) );
t( 'embed: appended at the end by default', 'ok' === code( bcm_agency_add_html_embed( array( 'post_id' => 50, 'html' => $code, 'approved' => true ) ) ) && 4 === count( $data() ) && 'container' === $data()[3]['elType'] );

$reset();
$r = bcm_agency_add_html_embed( array( 'post_id' => 50, 'html' => $code, 'mode' => 'update', 'element_id' => 'html01', 'approved' => true ) );
$d = $data();
t( 'embed update: code replaced in place', 'ok' === code( $r ) && $code === $d[0]['elements'][1]['settings']['html'] && $d[0]['elements'][0] === $tree[0]['elements'][0] && 3 === count( $d ) );
t( 'embed update: same code is no change', 'bcm_no_change' === code( bcm_agency_add_html_embed( array( 'post_id' => 50, 'html' => $code, 'mode' => 'update', 'element_id' => 'html01', 'approved' => true ) ) ) );
t( 'embed update: only HTML widgets', 'bcm_bad_input' === code( bcm_agency_add_html_embed( array( 'post_id' => 50, 'html' => $code, 'mode' => 'update', 'element_id' => 'head01', 'approved' => true ) ) ) );

// Section options for the embed's own new section (1.4.5).
$reset();
$r = bcm_agency_add_html_embed( array( 'post_id' => 50, 'html' => $code, 'after_id' => 'hero01', 'section_background' => '#f5f3ef', 'section_padding' => 96, 'approved' => true ) );
$sec = $data()[1]['settings'];
t( 'section: background, full width (default with a background) and padding as Elementor settings', 'ok' === code( $r ) && 'classic' === $sec['background_background'] && '#F5F3EF' === $sec['background_color'] && 'selector{box-shadow:0 0 0 100vmax #F5F3EF;clip-path:inset(0 -100vmax)}' === $sec['custom_css'] && '96' === $sec['padding']['top'] && '96' === $sec['padding']['bottom'] && '0' === $sec['padding']['left'], json_encode( $sec ) );
$reset();
bcm_agency_add_html_embed( array( 'post_id' => 50, 'html' => $code, 'section_background' => '#FFFFFF', 'section_full_width' => false, 'approved' => true ) );
$sec = $data()[3]['settings'];
t( 'section: full width can be turned off', '#FFFFFF' === $sec['background_color'] && ! isset( $sec['custom_css'] ) );
$reset();
bcm_agency_add_html_embed( array( 'post_id' => 50, 'html' => $code, 'approved' => true ) );
t( 'section: no options, plain section as before', array( 'content_width' => 'boxed' ) === $data()[3]['settings'] );
$reset();
foreach ( array(
	array( array( 'section_background' => 'red' ), 'bad colour' ),
	array( array( 'section_background' => '#fff;}body{display:none' ), 'CSS injection in the colour' ),
	array( array( 'section_full_width' => true ), 'full width without a background' ),
	array( array( 'section_padding' => 999 ), 'padding out of range' ),
	array( array( 'section_background' => '#FFFFFF', 'parent_id' => 'hero01' ), 'options inside an existing section' ),
	array( array( 'section_background' => '#FFFFFF', 'mode' => 'update', 'element_id' => 'html01' ), 'options on update' ),
) as $c ) {
	t( 'section refused: ' . $c[1], 'bcm_bad_input' === code( bcm_agency_add_html_embed( array_merge( array( 'post_id' => 50, 'html' => $code, 'approved' => true ), $c[0] ) ) ) && $data() === $tree );
}

$reset();
t( 'embed: PHP refused', 'bcm_bad_input' === code( bcm_agency_add_html_embed( array( 'post_id' => 50, 'html' => '<?php echo 1; ?>', 'approved' => true ) ) ) );
t( 'embed: over 100 KB refused', 'bcm_bad_input' === code( bcm_agency_add_html_embed( array( 'post_id' => 50, 'html' => str_repeat( 'a', 102401 ), 'approved' => true ) ) ) );
t( 'embed: non-Elementor page refused', 'bcm_not_elementor' === code( bcm_agency_add_html_embed( array( 'post_id' => 21, 'html' => $code, 'approved' => true ) ) ) );
t( 'embed: nothing changed by refusals', $data() === $tree );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
