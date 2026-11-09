<?php
defined( 'ABSPATH' ) || exit;

/**
 * Append-only activity log. Every write ability records who did what, to which
 * object, and whether it worked. There is deliberately no ability to clear it.
 */
class BCM_Log {

	const DB_VERSION = '1';
	const OPTION     = 'bcm_agency_log_db_version';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bcm_agency_log';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_gmt datetime NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_login varchar(60) NOT NULL DEFAULT '',
				ability varchar(100) NOT NULL,
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				result varchar(20) NOT NULL,
				summary text NOT NULL,
				PRIMARY KEY  (id),
				KEY created_gmt (created_gmt),
				KEY object_id (object_id)
			) {$charset};"
		);
		update_option( self::OPTION, self::DB_VERSION, false );
	}

	public static function maybe_upgrade() {
		if ( get_option( self::OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * @param string $ability   Ability name, e.g. bcm/create-post.
	 * @param int    $object_id Post or attachment ID (0 if none).
	 * @param string $result    'ok' or 'error'.
	 * @param array  $summary   Short, non-sensitive details. Never full post content.
	 */
	public static function add( $ability, $object_id, $result, array $summary = array() ) {
		global $wpdb;
		$user = wp_get_current_user();
		$wpdb->insert(
			self::table(),
			array(
				'created_gmt' => current_time( 'mysql', true ),
				'user_id'     => (int) $user->ID,
				'user_login'  => (string) $user->user_login,
				'ability'     => substr( (string) $ability, 0, 100 ),
				'object_id'   => (int) $object_id,
				'result'      => 'ok' === $result ? 'ok' : 'error',
				'summary'     => wp_json_encode( $summary ),
			),
			array( '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	public static function recent( $limit = 50 ) {
		global $wpdb;
		$limit = max( 1, min( 200, (int) $limit ) );
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}
}
