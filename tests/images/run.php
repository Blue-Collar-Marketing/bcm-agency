<?php
/**
 * BCM Agency Tools: finding and swapping images in Elementor data.
 *
 *     php tests/images/run.php
 *
 * Uses real stored shapes: classic widgets, backgrounds, and Atomic (V4) images
 * stored with and without a url key. Atomic images placed by id (as Elementor's
 * build tools do) have no url key; 1.4.0 missed those.
 */
define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public $code, $message;
	public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
$GLOBALS['meta'] = array();
function get_post_meta( $id, $k, $single = false ) { return isset( $GLOBALS['meta'][ $id ][ $k ] ) ? $GLOBALS['meta'][ $id ][ $k ] : ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; return true; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['meta'][ $id ][ $k ] ); return true; }
function wp_slash( $v ) { return $v; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function current_user_can( $c, $id = 0 ) { return true; }
function get_post_type( $id ) { return 'attachment'; }
function wp_attachment_is_image( $id ) { return in_array( (int) $id, array( 2071, 3000, 5, 9 ), true ); }
function wp_get_attachment_url( $id ) { return 'https://site.test/wp-content/uploads/img-' . (int) $id . '.jpg'; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function get_the_title( $id ) { return 'About'; }
function bcm_agency_purge_page_cache() { return array( 'test' ); }
function bcm_agency_save_elementor_data( $post_id, array $data ) { update_post_meta( $post_id, '_elementor_data', json_encode( $data ) ); return 'meta'; }
function bcm_agency_check_image( $id ) { return wp_attachment_is_image( $id ) ? (int) $id : new WP_Error( 'bcm_bad_image', 'x' ); }
function add_action() {}
function bcm_agency_register() {}
require dirname( __DIR__, 2 ) . '/bcm-agency/includes/abilities-images.php';

$pass = 0; $fail = 0;
function t( $name, $cond, $detail = '' ) { global $pass, $fail; if ( $cond ) { $pass++; } else { $fail++; echo "FAIL: $name" . ( $detail ? "\n      $detail" : '' ) . "\n"; } }

$t = function ( $type, $value ) { return array( '$$type' => $type, 'value' => $value ); };
$page = array(
	array( 'id' => 'a1', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => array( 'image' => array( 'id' => 5, 'url' => 'https://site.test/old.jpg', 'alt' => 'Old' ) ) ),
	array( 'id' => 'a2', 'elType' => 'container', 'settings' => array( 'background_image' => array( 'id' => 9, 'url' => 'https://site.test/bg.jpg' ) ) ),
	// Atomic, placed by id from the Media Library: no url key (the 1.4.0 gap).
	array( 'id' => 'v4a', 'elType' => 'widget', 'widgetType' => 'e-image', 'settings' => array( 'image' => $t( 'image', array( 'src' => $t( 'image-src', array( 'id' => $t( 'image-attachment-id', 2071 ) ) ), 'size' => $t( 'string', 'large' ) ) ) ) ),
	// Atomic with url: null.
	array( 'id' => 'v4b', 'elType' => 'widget', 'widgetType' => 'e-image', 'settings' => array( 'image' => $t( 'image', array( 'src' => $t( 'image-src', array( 'id' => $t( 'image-attachment-id', 3000 ), 'url' => null ) ) ) ) ) ),
	// Atomic background overlay image, by id only.
	array( 'id' => 'v4c', 'elType' => 'e-div-block', 'settings' => array( 'background' => $t( 'background', array( 'background-overlay' => $t( 'background-overlay', array( $t( 'background-image-overlay', array( 'image' => $t( 'image', array( 'src' => $t( 'image-src', array( 'id' => $t( 'image-attachment-id', 9 ) ) ) ) ) ) ) ) ) ) ) ) ),
	// Look-alikes that are NOT images: a link to a post id, a repeater item id.
	array( 'id' => 'x1', 'elType' => 'widget', 'widgetType' => 'e-button', 'settings' => array( 'link' => $t( 'link', array( 'destination' => $t( 'query', array( 'id' => 12, 'label' => 'About' ) ) ) ), 'items' => array( array( '_id' => 'r1', 'id' => 'abc' ) ) ) ),
);

$found = array();
bcm_agency_collect_images( $page, $found );
$by = array();
foreach ( $found as $f ) { $by[ $f['element_id'] ][] = $f; }
t( 'classic image widget found', isset( $by['a1'] ) && 5 === $by['a1'][0]['id'] && 'image' === $by['a1'][0]['field'] );
t( 'classic background found', isset( $by['a2'] ) && 'background' === $by['a2'][0]['kind'] );
t( 'Atomic image stored by id only is found (the 1.4.0 gap)', isset( $by['v4a'] ) && 2071 === $by['v4a'][0]['id'] && 'image.value.src.value' === $by['v4a'][0]['field'], json_encode( $found ) );
t( 'Atomic image with url: null is found', isset( $by['v4b'] ) && 3000 === $by['v4b'][0]['id'] );
t( 'Atomic background overlay by id is found', isset( $by['v4c'] ) && 9 === $by['v4c'][0]['id'] );
t( 'links and repeater ids are not images', ! isset( $by['x1'] ), json_encode( isset( $by['x1'] ) ? $by['x1'] : null ) );
t( 'exactly five images', 5 === count( $found ), (string) count( $found ) );

// Swap the Atomic id-only image; the shape must stay id-only (no url key invented).
update_post_meta( 1948, '_elementor_data', json_encode( $page ) );
$r = bcm_agency_replace_image( array( 'post_id' => 1948, 'element_id' => 'v4a', 'field' => 'image.value.src.value', 'new_attachment_id' => 3000, 'approved' => true ) );
t( 'swap on an id-only Atomic image works', is_array( $r ) && 2071 === $r['before']['id'] && 3000 === $r['after']['id'], is_wp_error( $r ) ? $r->code : json_encode( $r ) );
t( 'before url filled from the Media Library', is_array( $r ) && false !== strpos( $r['before']['url'], 'img-2071' ) );
$saved = json_decode( get_post_meta( 1948, '_elementor_data' ), true );
$src   = $saved[2]['settings']['image']['value']['src']['value'];
t( 'saved: typed id updated', array( '$$type' => 'image-attachment-id', 'value' => 3000 ) === $src['id'], json_encode( $src ) );
t( 'saved: no url key added', ! array_key_exists( 'url', $src ) );
t( 'saved: everything else untouched', $saved[0] === $page[0] && $saved[5] === $page[5] && 'large' === $saved[2]['settings']['image']['value']['size']['value'] );

// Swap the classic one: id, url and alt update together.
update_post_meta( 1948, '_elementor_data', json_encode( $page ) );
$r = bcm_agency_replace_image( array( 'post_id' => 1948, 'element_id' => 'a1', 'field' => 'image', 'new_attachment_id' => 2071, 'approved' => true, 'alt_text' => 'New' ) );
$saved = json_decode( get_post_meta( 1948, '_elementor_data' ), true );
t( 'classic swap: id, url and alt together', 2071 === $saved[0]['settings']['image']['id'] && false !== strpos( $saved[0]['settings']['image']['url'], 'img-2071' ) && 'New' === $saved[0]['settings']['image']['alt'] );

// Refusals.
update_post_meta( 1948, '_elementor_data', json_encode( $page ) );
$r = bcm_agency_replace_image( array( 'post_id' => 1948, 'element_id' => 'x1', 'field' => 'link.value.destination.value', 'new_attachment_id' => 3000, 'approved' => true ) );
t( 'a link is refused as "not an image"', is_wp_error( $r ) && 'bcm_not_found' === $r->code );
$r = bcm_agency_replace_image( array( 'post_id' => 1948, 'element_id' => 'v4b', 'field' => 'image.value.src.value', 'new_attachment_id' => 3000, 'approved' => true ) );
t( 'same image refused as no change', is_wp_error( $r ) && 'bcm_no_change' === $r->code );
$r = bcm_agency_replace_image( array( 'post_id' => 1948, 'element_id' => 'v4a', 'field' => 'image.value.src.value', 'new_attachment_id' => 3000 ) );
t( 'still needs approved: true', is_wp_error( $r ) && 'bcm_needs_approval' === $r->code );

// ---------------------------------------------------------------------------
// list-media: reads the database directly (real SQL on SQLite), so plugins or
// snippets that reshape WP_Query can't hide the Media Library (imperium.social).
class WP_Query {
	public function __construct( $args ) { throw new Exception( 'list-media must not use WP_Query' ); }
}
class Fake_WPDB {
	public $posts = 'wp_posts', $postmeta = 'wp_postmeta';
	public $db;
	public function __construct() {
		$this->db = new PDO( 'sqlite::memory:' );
		$this->db->exec( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_type TEXT, post_mime_type TEXT, post_status TEXT, post_title TEXT, post_name TEXT, post_date TEXT)' );
		$this->db->exec( 'CREATE TABLE wp_postmeta (post_id INTEGER, meta_key TEXT, meta_value TEXT)' );
	}
	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	public function prepare( $q, $args ) {
		$i = 0;
		return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) { $v = $args[ $i++ ]; return '%d' === $m[0] ? (string) (int) $v : $this->db->quote( (string) $v ); }, $q );
	}
	public function get_col( $sql ) { return $this->db->query( $sql )->fetchAll( PDO::FETCH_COLUMN ); }
}
$GLOBALS['wpdb'] = new Fake_WPDB();
$GLOBALS['posts'] = array();
$add = function ( $id, $title, $file, $alt, $date, $mime = 'image/jpeg', $status = 'inherit', $type = 'attachment' ) {
	$GLOBALS['wpdb']->db->exec( sprintf( "INSERT INTO wp_posts VALUES (%d, '%s', '%s', '%s', '%s', '%s', '%s')", $id, $type, $mime, $status, $title, strtolower( str_replace( ' ', '-', $title ) ), $date ) );
	$GLOBALS['wpdb']->db->exec( sprintf( "INSERT INTO wp_postmeta VALUES (%d, '_wp_attached_file', '%s')", $id, $file ) );
	if ( $alt ) {
		$GLOBALS['wpdb']->db->exec( sprintf( "INSERT INTO wp_postmeta VALUES (%d, '_wp_attachment_image_alt', '%s')", $id, $alt ) );
		$GLOBALS['meta'][ $id ]['_wp_attachment_image_alt'] = $alt;
	}
	$GLOBALS['posts'][ $id ] = (object) array( 'ID' => $id, 'post_title' => $title, 'post_date' => $date, 'file' => $file );
};
function get_post( $id ) { return isset( $GLOBALS['posts'][ (int) $id ] ) ? $GLOBALS['posts'][ (int) $id ] : null; }
function wp_get_attachment_metadata( $id ) { return array( 'width' => 1200, 'height' => 800 ); }
function get_attached_file( $id ) { return '/uploads/' . $GLOBALS['posts'][ (int) $id ]->file; }
$add( 13585, 'Gage Foster Memorial Spikeball Tournament', '2025/06/Gage_Foster_Memorial_Spikeball_Tournament.jpg', 'Spikeball tournament', '2025-06-01 10:00:00' );
$add( 13590, 'IMG 4471', '2025/06/spikeball-greyscale.jpg', '', '2025-06-02 10:00:00' );
$add( 17223, 'Gage Foster Memorial Spikeball Tournament', '2026/11/Gage_Foster_Memorial_Spikeball_Tournament-1.jpg', 'Gage Foster Memorial Spikeball Tournament', '2026-11-13 09:00:00' );
$add( 15000, 'Logo', '2025/01/logo.png', 'Imperium logo', '2025-01-01 10:00:00', 'image/png' );
$add( 15001, 'Brochure', '2025/01/brochure.pdf', '', '2025-01-02 10:00:00', 'application/pdf' );
$add( 15002, 'Trashed', '2025/01/old.jpg', '', '2026-12-01 10:00:00', 'image/jpeg', 'trash' );
$add( 15003, 'A blog post', 'x', '', '2026-12-02 10:00:00', '', 'publish', 'post' );

$r   = bcm_agency_list_media( array() );
$ids = array_column( $r['images'], 'id' );
t( 'lists images newest first, no WP_Query', array( 17223, 13590, 13585, 15000 ) === $ids, json_encode( $ids ) );
t( 'skips PDFs, trashed images and posts', ! array_intersect( $ids, array( 15001, 15002, 15003 ) ) );
t( 'returns file name, url, alt, size', 'Gage_Foster_Memorial_Spikeball_Tournament-1.jpg' === $r['images'][0]['filename'] && 'Gage Foster Memorial Spikeball Tournament' === $r['images'][0]['alt'] && 1200 === $r['images'][0]['width'] && 4 === $r['count'] );
$r = bcm_agency_list_media( array( 'search' => 'greyscale' ) );
t( 'search finds the greyscale version by file name (title is just "IMG 4471")', array( 13590 ) === array_column( $r['images'], 'id' ), json_encode( $r ) );
$r = bcm_agency_list_media( array( 'search' => 'Imperium logo' ) );
t( 'search matches alt text', array( 15000 ) === array_column( $r['images'], 'id' ) );
$r = bcm_agency_list_media( array( 'search' => 'spikeball' ) );
t( 'search matches title and file name, newest first', array( 17223, 13590, 13585 ) === array_column( $r['images'], 'id' ) );
$r = bcm_agency_list_media( array( 'search' => '%' ) );
t( 'search wildcards are literal', 0 === $r['count'] && false !== strpos( $r['note'], 'No images match' ) );
$r = bcm_agency_list_media( array( 'search' => "x' OR '1'='1" ) );
t( 'search is safely quoted', 0 === $r['count'] );
t( 'per_page respected', 2 === bcm_agency_list_media( array( 'per_page' => 2 ) )['count'] );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
