<?php
/**
 * Redirects, through the Redirection plugin (wordpress.org/plugins/redirection).
 *
 * Same-site only: a redirect always goes from a path on this site to another
 * page on this site. Redirects to other domains, regex rules and redirecting
 * the homepage or WordPress's own addresses are refused; those stay with a
 * developer in Tools → Redirection.
 *
 * @package BCM_Agency
 */

defined( 'ABSPATH' ) || exit;

/** Is the Redirection plugin active and usable? */
function bcm_agency_redirection_ready() {
	return class_exists( 'Red_Item' ) && class_exists( 'Red_Group' );
}

function bcm_agency_redirection_missing() {
	return new WP_Error( 'bcm_no_redirection', 'The Redirection plugin is not active on this site. Install and activate it (wordpress.org/plugins/redirection), open Tools → Redirection once to finish its setup, then try again.' );
}

/** The first enabled group for ordinary WordPress redirects (Redirection's "Redirections" group). */
function bcm_agency_redirect_group() {
	global $wpdb;
	$id = $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}redirection_groups WHERE module_id = 1 AND status = 'enabled' ORDER BY position ASC, id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return $id ? (int) $id : 0;
}

/** This site's host, without www. */
function bcm_agency_site_host() {
	$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	return preg_replace( '/^www\./', '', $host );
}

/**
 * A path on this site from a path or a same-site URL; WP_Error otherwise.
 * Keeps the query string; drops any #fragment.
 */
function bcm_agency_redirect_path( $value, $what ) {
	$value = trim( (string) $value );
	if ( '' === $value || strlen( $value ) > 500 ) {
		return new WP_Error( 'bcm_bad_input', $what . ' is required (a path on this site such as /old-page/, up to 500 characters).' );
	}
	if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $value ) ) {
		$parts = wp_parse_url( $value );
		$host  = isset( $parts['host'] ) ? preg_replace( '/^www\./', '', strtolower( $parts['host'] ) ) : '';
		if ( ! isset( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) || $host !== bcm_agency_site_host() ) {
			return new WP_Error( 'bcm_external_redirect', $what . ' must be on this site (' . bcm_agency_site_host() . '). Redirects to or from other domains are set up by a developer.' );
		}
		$value = ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}
	$value = preg_replace( '/#.*$/', '', $value );
	if ( '/' !== substr( $value, 0, 1 ) || '//' === substr( $value, 0, 2 ) ) {
		return new WP_Error( 'bcm_bad_input', $what . ' must be a path on this site that starts with /, such as /old-page/.' );
	}
	if ( preg_match( '/[\s<>"\'\\\\]/', $value ) ) {
		return new WP_Error( 'bcm_bad_input', $what . ' contains characters that are not allowed in a path.' );
	}
	return $value;
}

/** Comparable form of a path: lower case, no trailing slash. */
function bcm_agency_path_key( $path ) {
	$base = strtolower( (string) preg_replace( '/\?.*$/', '', $path ) );
	$base = '/' === $base ? '/' : rtrim( $base, '/' );
	return $base . ( false !== strpos( $path, '?' ) ? strtolower( substr( $path, strpos( $path, '?' ) ) ) : '' );
}

/** An enabled redirect that already uses this source, or null. */
function bcm_agency_redirect_existing( $source ) {
	global $wpdb;
	$key  = bcm_agency_path_key( $source );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT i.id, i.url, i.action_data FROM {$wpdb->prefix}redirection_items i INNER JOIN {$wpdb->prefix}redirection_groups g ON g.id = i.group_id WHERE g.module_id = 1 AND i.status = 'enabled' AND i.regex = 0 AND ( i.url = %s OR i.url = %s OR i.url = %s )", $source, rtrim( $source, '/' ), rtrim( $source, '/' ) . '/' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( (array) $rows as $row ) {
		if ( bcm_agency_path_key( $row['url'] ) === $key ) {
			return $row;
		}
	}
	return null;
}

/** The target URL of a redirect row (Redirection stores it plain or serialized). */
function bcm_agency_redirect_target( $action_data ) {
	$data = maybe_unserialize( $action_data );
	if ( is_array( $data ) ) {
		return isset( $data['url'] ) ? (string) $data['url'] : '';
	}
	return (string) $data;
}

/**
 * Checks a same-site redirect before anything changes.
 *
 * @return array|WP_Error [ 'source' => path, 'target' => path, 'code' => int, 'group' => int ]
 */
function bcm_agency_redirect_prepare( $source, $target, $code = 301 ) {
	if ( ! bcm_agency_redirection_ready() ) {
		return bcm_agency_redirection_missing();
	}
	$source = bcm_agency_redirect_path( $source, 'source' );
	if ( is_wp_error( $source ) ) {
		return $source;
	}
	$target = bcm_agency_redirect_path( $target, 'target' );
	if ( is_wp_error( $target ) ) {
		return $target;
	}
	if ( '/' === bcm_agency_path_key( $source ) || 0 === strpos( bcm_agency_path_key( $source ), '/?' ) ) {
		return new WP_Error( 'bcm_bad_input', 'The homepage cannot be redirected from here.' );
	}
	if ( preg_match( '#^/(wp-admin|wp-login\.php|wp-json|xmlrpc\.php|wp-content|wp-includes|wp-cron\.php)(/|\?|$)#i', $source ) ) {
		return new WP_Error( 'bcm_bad_input', 'WordPress\'s own addresses cannot be redirected.' );
	}
	if ( bcm_agency_path_key( $source ) === bcm_agency_path_key( $target ) ) {
		return new WP_Error( 'bcm_bad_input', 'The source and target are the same page.' );
	}
	$code = (int) $code;
	if ( ! in_array( $code, array( 301, 302, 307, 308 ), true ) ) {
		return new WP_Error( 'bcm_bad_input', 'code must be 301 (permanent, the usual choice), 302, 307 or 308.' );
	}
	$existing = bcm_agency_redirect_existing( $source );
	if ( $existing ) {
		return new WP_Error( 'bcm_redirect_exists', 'There is already a redirect from ' . $existing['url'] . ' (id ' . (int) $existing['id'] . ') to ' . bcm_agency_redirect_target( $existing['action_data'] ) . '. Remove it with bcm/remove-redirect first if it should change.' );
	}
	$group = bcm_agency_redirect_group();
	if ( ! $group ) {
		return new WP_Error( 'bcm_no_redirection', 'Redirection has no enabled group for WordPress redirects. Open Tools → Redirection once to finish its setup.' );
	}
	return array(
		'source' => $source,
		'target' => $target,
		'code'   => $code,
		'group'  => $group,
	);
}

/**
 * Creates a prepared redirect.
 *
 * @return array|WP_Error
 */
function bcm_agency_redirect_create( array $r ) {
	$item = Red_Item::create(
		array(
			'url'         => $r['source'],
			'match_type'  => 'url',
			'action_type' => 'url',
			'action_code' => $r['code'],
			'action_data' => array( 'url' => $r['target'] ),
			'group_id'    => $r['group'],
			'regex'       => false,
			'title'       => 'Added by BCM Agency Tools',
		)
	);
	if ( is_wp_error( $item ) ) {
		return new WP_Error( 'bcm_redirect_failed', 'Redirection refused the redirect: ' . $item->get_error_message() );
	}
	return array(
		'id'     => (int) $item->get_id(),
		'source' => $r['source'],
		'target' => $r['target'],
		'code'   => $r['code'],
	);
}

function bcm_agency_add_redirect( array $input ) {
	$r = bcm_agency_redirect_prepare(
		isset( $input['source'] ) ? $input['source'] : '',
		isset( $input['target'] ) ? $input['target'] : '',
		isset( $input['code'] ) ? $input['code'] : 301
	);
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	$warnings = array();
	$live     = url_to_postid( home_url( $r['source'] ) );
	if ( $live && 'publish' === get_post_status( $live ) ) {
		$warnings[] = 'The source is a live page (id ' . $live . '). Visitors will be redirected away from it; unpublish or trash it if it is no longer needed.';
	}
	$target_redirect = bcm_agency_redirect_existing( $r['target'] );
	if ( $target_redirect ) {
		$warnings[] = 'The target itself redirects to ' . bcm_agency_redirect_target( $target_redirect['action_data'] ) . ', which makes a chain. Point this redirect straight at the final page.';
	}
	if ( empty( $input['approved'] ) ) {
		return new WP_Error( 'bcm_needs_approval', 'A redirect sends every visitor (and Google) from ' . $r['source'] . ' to ' . $r['target'] . ' (' . $r['code'] . ').' . ( $warnings ? ' ' . implode( ' ', $warnings ) : '' ) . ' Confirm with the person, then call again with approved: true.' );
	}
	$made = bcm_agency_redirect_create( $r );
	if ( is_wp_error( $made ) ) {
		return $made;
	}
	$made['warnings'] = $warnings;
	$made['note']     = 'Live now. Manage it in Tools → Redirection.';
	$made['_log']     = array(
		'action' => 'redirect added',
		'id'     => $made['id'],
		'code'   => $made['code'],
	);
	return $made;
}

function bcm_agency_list_redirects( array $input ) {
	if ( ! bcm_agency_redirection_ready() ) {
		return bcm_agency_redirection_missing();
	}
	global $wpdb;
	$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 50;
	$search   = isset( $input['search'] ) ? trim( sanitize_text_field( $input['search'] ) ) : '';
	$sql      = "SELECT i.id, i.url, i.action_data, i.action_code, i.action_type, i.status, i.regex, i.last_count, i.last_access, i.title FROM {$wpdb->prefix}redirection_items i INNER JOIN {$wpdb->prefix}redirection_groups g ON g.id = i.group_id WHERE g.module_id = 1";
	$args     = array();
	if ( '' !== $search ) {
		$like  = '%' . $wpdb->esc_like( $search ) . '%';
		$sql  .= ' AND ( i.url LIKE %s OR i.action_data LIKE %s )';
		$args  = array( $like, $like );
	}
	$sql   .= ' ORDER BY i.id DESC LIMIT %d';
	$args[] = $per_page;
	$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery

	$out = array();
	foreach ( (array) $rows as $row ) {
		$out[] = array(
			'id'        => (int) $row['id'],
			'source'    => $row['url'],
			'target'    => 'url' === $row['action_type'] ? bcm_agency_redirect_target( $row['action_data'] ) : '(' . $row['action_type'] . ')',
			'code'      => (int) $row['action_code'],
			'enabled'   => 'enabled' === $row['status'],
			'regex'     => (bool) (int) $row['regex'],
			'hits'      => (int) $row['last_count'],
			'last_used' => $row['last_access'],
			'title'     => (string) $row['title'],
		);
	}
	return array(
		'redirects' => $out,
		'count'     => count( $out ),
	);
}

function bcm_agency_remove_redirect( array $input ) {
	if ( ! bcm_agency_redirection_ready() ) {
		return bcm_agency_redirection_missing();
	}
	$id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
	$item = $id ? Red_Item::get_by_id( $id ) : false;
	if ( ! $item ) {
		return new WP_Error( 'bcm_not_found', 'No redirect with that id. Find it with bcm/list-redirects.' );
	}
	global $wpdb;
	$module = (int) $wpdb->get_var( $wpdb->prepare( "SELECT g.module_id FROM {$wpdb->prefix}redirection_items i INNER JOIN {$wpdb->prefix}redirection_groups g ON g.id = i.group_id WHERE i.id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( 1 !== $module ) {
		return new WP_Error( 'bcm_not_found', 'That redirect belongs to Redirection\'s Apache or Nginx module and is managed in Tools → Redirection.' );
	}
	$source = $item->get_url();
	if ( empty( $input['approved'] ) ) {
		return new WP_Error( 'bcm_needs_approval', 'Removing redirect ' . $id . ' (' . $source . ') means that address shows its old page again, or "not found". Confirm with the person, then call again with approved: true.' );
	}
	$item->delete();
	return array(
		'id'      => $id,
		'removed' => $source,
		'_log'    => array(
			'action' => 'redirect removed',
			'id'     => $id,
		),
	);
}

function bcm_agency_register_redirect_abilities() {
	$approved = array(
		'type'        => 'boolean',
		'description' => 'Set true only after the person approved this exact change.',
	);

	bcm_agency_register(
		'bcm/list-redirects',
		array(
			'label'               => 'List redirects',
			'description'         => 'Read-only. Lists the site\'s redirects from the Redirection plugin (newest first): id, source path, target, code, enabled, hit count. search matches the source or target.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'   => array( 'type' => 'string' ),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_list_redirects',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		),
		true
	);

	bcm_agency_register(
		'bcm/add-redirect',
		array(
			'label'               => 'Add a redirect',
			'description'         => 'Adds a same-site redirect in the Redirection plugin, e.g. from a removed page to its replacement: source and target are paths on this site (/old-page/ → /new-page/) or full URLs on this site. code 301 (permanent, default), 302, 307 or 308. Refuses the homepage, WordPress\'s own addresses, other domains, a source that already redirects, and regex. Requires approved: true after the person confirms.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'source'   => array( 'type' => 'string' ),
					'target'   => array( 'type' => 'string' ),
					'code'     => array(
						'type' => 'integer',
						'enum' => array( 301, 302, 307, 308 ),
					),
					'approved' => $approved,
				),
				'required'             => array( 'source', 'target' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_add_redirect',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);

	bcm_agency_register(
		'bcm/remove-redirect',
		array(
			'label'               => 'Remove a redirect',
			'description'         => 'Deletes one redirect by id (from bcm/list-redirects). Requires approved: true after the person confirms.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'       => array( 'type' => 'integer' ),
					'approved' => $approved,
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_remove_redirect',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);
}
