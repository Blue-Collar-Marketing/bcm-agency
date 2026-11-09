<?php
defined( 'ABSPATH' ) || exit;

function bcm_agency_get_site_identity() {
	return array(
		'site_title' => get_option( 'blogname' ),
		'tagline'    => get_option( 'blogdescription' ),
		'site_icon'  => (int) get_option( 'site_icon' ),
		'timezone'   => wp_timezone_string(),
		'home_url'   => home_url(),
		'bcm_agency_version' => BCM_AGENCY_VERSION,
	);
}

function bcm_agency_update_site_identity( array $input ) {
	if ( ! isset( $input['site_title'] ) && ! isset( $input['tagline'] ) ) {
		return new WP_Error( 'bcm_bad_input', 'Pass site_title, tagline, or both.' );
	}
	$before = bcm_agency_get_site_identity();
	if ( isset( $input['site_title'] ) ) {
		$title = sanitize_text_field( $input['site_title'] );
		if ( '' === $title ) {
			return new WP_Error( 'bcm_bad_input', 'site_title cannot be empty.' );
		}
		update_option( 'blogname', $title );
	}
	if ( isset( $input['tagline'] ) ) {
		update_option( 'blogdescription', sanitize_text_field( $input['tagline'] ) );
	}
	$after = bcm_agency_get_site_identity();
	return array(
		'before' => array(
			'site_title' => $before['site_title'],
			'tagline'    => $before['tagline'],
		),
		'after'  => array(
			'site_title' => $after['site_title'],
			'tagline'    => $after['tagline'],
		),
		'note'   => 'This is the WordPress site title and tagline (browser tabs, search results, Yoast). Elementor’s kit "site name" is separate.',
		'_log'   => array(
			'before' => array( $before['site_title'], $before['tagline'] ),
			'after'  => array( $after['site_title'], $after['tagline'] ),
		),
	);
}

function bcm_agency_get_log( array $input ) {
	$rows = BCM_Log::recent( isset( $input['limit'] ) ? (int) $input['limit'] : 25 );
	return array(
		'entries' => array_map(
			function ( $r ) {
				$r['summary'] = json_decode( $r['summary'], true );
				return $r;
			},
			$rows
		),
	);
}

/**
 * Clears page caches from WordPress, the same way WPCode does after its own
 * Header & Footer save, plus Breeze (Cloudways) which WPCode does not cover.
 * Returns the list of caches that were asked to clear.
 */
function bcm_agency_purge_page_cache() {
	$done = array();
	if ( function_exists( 'wpcode_clear_all_plugins_page_cache' ) ) {
		wpcode_clear_all_plugins_page_cache( 'global' );
		$done[] = 'wpcode (LiteSpeed, WP Super Cache, W3TC, WP Rocket, etc. when present)';
	}
	if ( defined( 'BREEZE_VERSION' ) || class_exists( 'Breeze_PurgeCache' ) || has_action( 'breeze_clear_all_cache' ) ) {
		do_action( 'breeze_clear_all_cache' );
		do_action( 'breeze_clear_varnish' );
		$done[] = 'breeze (+ Varnish)';
	}
	return $done;
}

function bcm_agency_purge_cache_ability() {
	$done = bcm_agency_purge_page_cache();
	return array(
		'cleared' => $done,
		'note'    => $done ? 'Page caches cleared. A CDN (e.g. Cloudflare) is separate.' : 'No known page cache plugin was found to clear.',
		'_log'    => array( 'cleared' => $done ),
	);
}

function bcm_agency_register_site_abilities() {
	bcm_agency_register(
		'bcm/get-site-identity',
		array(
			'label'               => 'Get site title and tagline',
			'description'         => 'Reads the WordPress site title, tagline, timezone and home URL.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => new stdClass(),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_get_site_identity',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		),
		true
	);

	bcm_agency_register(
		'bcm/update-site-identity',
		array(
			'label'               => 'Update site title and tagline',
			'description'         => 'Sets the WordPress site title and/or tagline (Settings → General). Returns before and after values.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'site_title' => array( 'type' => 'string' ),
					'tagline'    => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_update_site_identity',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);

	bcm_agency_register(
		'bcm/purge-page-cache',
		array(
			'label'               => 'Purge page cache',
			'description'         => 'Clears this site\'s page caches (Breeze and Varnish on Cloudways, plus LiteSpeed, WP Rocket, W3TC and others when present) so recent changes show to visitors. Safe to run; pages rebuild on the next visit.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => new stdClass(),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_purge_cache_ability',
			'permission_callback' => function () {
				return current_user_can( 'edit_others_posts' ); // Editors and admins only.
			},
		)
	);

	bcm_agency_register(
		'bcm/get-activity-log',
		array(
			'label'               => 'Get BCM activity log',
			'description'         => 'Lists the most recent BCM Agency actions on this site: who, what, which post or image, when, and whether it worked.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 200,
						'default' => 25,
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_get_log',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		),
		true
	);
}
