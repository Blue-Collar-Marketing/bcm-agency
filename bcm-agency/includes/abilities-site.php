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
	if ( ! isset( $input['site_title'] ) && ! isset( $input['tagline'] ) && ! isset( $input['site_icon_id'] ) ) {
		return new WP_Error( 'bcm_bad_input', 'Pass site_title, tagline, site_icon_id, or any combination.' );
	}
	$warnings = array();
	// Validate everything before changing anything.
	if ( isset( $input['site_title'] ) && '' === sanitize_text_field( $input['site_title'] ) ) {
		return new WP_Error( 'bcm_bad_input', 'site_title cannot be empty.' );
	}
	$icon_id = null;
	if ( isset( $input['site_icon_id'] ) ) {
		$icon_id = (int) $input['site_icon_id'];
		if ( $icon_id > 0 ) {
			if ( ! wp_attachment_is_image( $icon_id ) ) {
				return new WP_Error( 'bcm_bad_input', 'site_icon_id must be a Media Library image (see bcm/list-media).' );
			}
			$meta = wp_get_attachment_metadata( $icon_id );
			$w    = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
			$h    = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
			if ( $w && $h && $w !== $h ) {
				$warnings[] = "The icon is {$w}x{$h}, not square, so browsers will squash or crop it. A square image works best.";
			}
			if ( $w && $w < 512 ) {
				$warnings[] = "The icon is {$w}px wide; WordPress recommends at least 512x512 so it stays sharp on phones.";
			}
		}
	}

	$before = bcm_agency_get_site_identity();
	if ( isset( $input['site_title'] ) ) {
		update_option( 'blogname', sanitize_text_field( $input['site_title'] ) );
	}
	if ( isset( $input['tagline'] ) ) {
		update_option( 'blogdescription', sanitize_text_field( $input['tagline'] ) );
	}
	if ( null !== $icon_id ) {
		if ( $icon_id > 0 ) {
			update_option( 'site_icon', $icon_id );
		} else {
			delete_option( 'site_icon' );
		}
	}
	$after = bcm_agency_get_site_identity();
	if ( null !== $icon_id ) {
		bcm_agency_purge_page_cache();
	}
	return array(
		'before'   => array(
			'site_title' => $before['site_title'],
			'tagline'    => $before['tagline'],
			'site_icon'  => $before['site_icon'],
		),
		'after'    => array(
			'site_title'    => $after['site_title'],
			'tagline'       => $after['tagline'],
			'site_icon'     => $after['site_icon'],
			'site_icon_url' => $after['site_icon'] ? get_site_icon_url( 512 ) : '',
		),
		'warnings' => $warnings,
		'note'     => 'This is the WordPress site title, tagline and site icon (favicon) from Settings → General / Customize → Site Identity. Elementor’s kit "site name" is separate. Browsers cache favicons, so a new icon can take a hard refresh to appear.',
		'_log'     => array(
			'before' => array( $before['site_title'], $before['tagline'], $before['site_icon'] ),
			'after'  => array( $after['site_title'], $after['tagline'], $after['site_icon'] ),
		),
	);
}

/**
 * Sets the static homepage (Settings → Reading). Optionally the posts page too.
 */
function bcm_agency_set_homepage( array $input ) {
	if ( empty( $input['approved'] ) ) {
		return new WP_Error( 'bcm_needs_approval', 'Changing the homepage changes what every visitor sees at the site root. Confirm with the person, then call again with approved: true.' );
	}
	$page_id = isset( $input['page_id'] ) ? (int) $input['page_id'] : 0;
	$page    = get_post( $page_id );
	if ( ! $page || 'page' !== $page->post_type ) {
		return new WP_Error( 'bcm_not_found', 'page_id must be a page (not a post or template).' );
	}
	if ( 'publish' !== $page->post_status ) {
		return new WP_Error( 'bcm_not_published', 'Publish the page first; a draft homepage would show visitors an error.' );
	}
	$posts_page = null;
	if ( isset( $input['posts_page_id'] ) ) {
		$posts_page = (int) $input['posts_page_id'];
		if ( $posts_page === $page_id ) {
			return new WP_Error( 'bcm_bad_input', 'The homepage and the posts page must be different pages.' );
		}
		if ( $posts_page > 0 ) {
			$pp = get_post( $posts_page );
			if ( ! $pp || 'page' !== $pp->post_type || 'publish' !== $pp->post_status ) {
				return new WP_Error( 'bcm_bad_input', 'posts_page_id must be a published page, or 0 for none.' );
			}
		}
	}
	$before = array(
		'show_on_front'  => get_option( 'show_on_front' ),
		'page_on_front'  => (int) get_option( 'page_on_front' ),
		'page_for_posts' => (int) get_option( 'page_for_posts' ),
	);
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $page_id );
	if ( null !== $posts_page ) {
		update_option( 'page_for_posts', $posts_page );
	}
	$after = array(
		'show_on_front'  => get_option( 'show_on_front' ),
		'page_on_front'  => (int) get_option( 'page_on_front' ),
		'page_for_posts' => (int) get_option( 'page_for_posts' ),
	);
	$cleared = bcm_agency_purge_page_cache();
	return array(
		'id'        => $page_id,
		'homepage'  => array( 'id' => $page_id, 'title' => get_the_title( $page ), 'url' => home_url( '/' ) ),
		'before'    => $before,
		'after'     => $after,
		'cleared'   => $cleared,
		'note'      => 'Undo by calling again with the previous page_on_front (see before). The page’s own URL now redirects to the site root.',
		'_log'      => array( 'before' => $before, 'after' => $after ),
	);
}

/**
 * Astra per-page layout: full width / unboxed, no sidebar, theme title off.
 * Works on pages and blog posts. Only Astra's own per-post settings are touched.
 */
function bcm_agency_astra_layout_meta( $post_id ) {
	$keys = array( 'ast-site-content-layout', 'site-content-style', 'site-sidebar-layout', 'site-post-title', 'site-content-layout', 'ast-featured-img' );
	$out  = array();
	foreach ( $keys as $k ) {
		$out[ $k ] = (string) get_post_meta( $post_id, $k, true );
	}
	$out['page_template'] = (string) get_post_meta( $post_id, '_wp_page_template', true );
	return $out;
}

function bcm_agency_set_page_layout( array $input ) {
	if ( 'astra' !== get_template() ) {
		return new WP_Error( 'bcm_not_astra', 'This site’s theme is not Astra (it is "' . get_template() . '"), so there is no Astra layout to set. Set the layout in Elementor instead.' );
	}
	$post = get_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
	if ( ! $post || ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
		return new WP_Error( 'bcm_not_found', 'post_id must be a page or a blog post.' );
	}
	if ( ! current_user_can( 'edit_post', $post->ID ) ) {
		return new WP_Error( 'bcm_forbidden', 'You are not allowed to edit this page.' );
	}
	if ( in_array( $post->post_status, array( 'publish', 'future' ), true ) && empty( $input['approved'] ) ) {
		return new WP_Error( 'bcm_needs_approval', 'This page is live (or scheduled), so its layout changes immediately. Confirm with the person, then call again with approved: true.' );
	}
	$layout     = isset( $input['layout'] ) ? $input['layout'] : 'full-width';
	$hide_title = ! isset( $input['hide_title'] ) || ! empty( $input['hide_title'] );

	$before = bcm_agency_astra_layout_meta( $post->ID );
	if ( 'theme-default' === $layout ) {
		foreach ( array( 'ast-site-content-layout', 'site-content-style', 'site-sidebar-layout', 'site-content-layout' ) as $k ) {
			delete_post_meta( $post->ID, $k );
		}
	} else {
		$map = array(
			'full-width' => array( 'full-width-container', 'unboxed', 'page-builder' ),
			'boxed'      => array( 'normal-width-container', 'boxed', 'boxed-container' ),
		);
		update_post_meta( $post->ID, 'ast-site-content-layout', $map[ $layout ][0] );
		update_post_meta( $post->ID, 'site-content-style', $map[ $layout ][1] );
		update_post_meta( $post->ID, 'site-content-layout', $map[ $layout ][2] ); // Older Astra versions.
		update_post_meta( $post->ID, 'site-sidebar-layout', 'no-sidebar' );
	}
	if ( $hide_title ) {
		update_post_meta( $post->ID, 'site-post-title', 'disabled' );
		if ( 'post' === $post->post_type ) {
			update_post_meta( $post->ID, 'ast-featured-img', 'disabled' );
		}
	} else {
		delete_post_meta( $post->ID, 'site-post-title' );
		delete_post_meta( $post->ID, 'ast-featured-img' );
	}
	// Elementor-built pages also get Elementor's full-width page template, so Elementor controls the content area.
	$template_note = '';
	if ( 'page' === $post->post_type && 'full-width' === $layout && bcm_agency_is_elementor_post( $post->ID ) ) {
		update_post_meta( $post->ID, '_wp_page_template', 'elementor_header_footer' );
		$template_note = ' Elementor page template set to Elementor Full Width.';
	}
	clean_post_cache( $post->ID );
	$after   = bcm_agency_astra_layout_meta( $post->ID );
	$cleared = bcm_agency_purge_page_cache();
	return array(
		'id'      => $post->ID,
		'title'   => get_the_title( $post ),
		'layout'  => $layout,
		'before'  => $before,
		'after'   => $after,
		'cleared' => $cleared,
		'note'    => 'Astra layout for this ' . $post->post_type . ' only.' . $template_note . ' Undo: call again with layout "theme-default" (and hide_title false), or restore the before values. For every blog post at once, set Customize → Blog → Single Post instead.',
		'_log'    => array( 'before' => $before, 'after' => $after ),
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
			'label'               => 'Update site title, tagline and site icon',
			'description'         => 'Sets the WordPress site title, tagline and/or site icon (favicon) (Settings → General, Customize → Site Identity). site_icon_id is a Media Library image (square, 512x512 or larger is best); 0 removes the icon. Returns before and after values and warns about non-square or small icons.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'site_title'   => array( 'type' => 'string' ),
					'tagline'      => array( 'type' => 'string' ),
					'site_icon_id' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Media Library image ID to use as the favicon / site icon. 0 removes it.',
					),
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
		'bcm/set-homepage',
		array(
			'label'               => 'Set the homepage',
			'description'         => 'Makes a published page the site’s static homepage (Settings → Reading → "A static page"), and optionally sets the posts page (posts_page_id, 0 = none). Requires approved: true after the person confirms. Returns before and after values for undo; clears page caches.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'page_id'       => array( 'type' => 'integer' ),
					'posts_page_id' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Optional. Page that lists blog posts. Leave out to keep the current setting; 0 for none. Not needed when the blog page is an Elementor page with its own post loop.',
					),
					'approved'      => array(
						'type'        => 'boolean',
						'description' => 'Set true only after the person has approved changing the homepage.',
					),
				),
				'required'             => array( 'page_id' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_set_homepage',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);

	bcm_agency_register(
		'bcm/set-page-layout',
		array(
			'label'               => 'Set a page’s Astra layout (full width)',
			'description'         => 'Astra theme only. Sets one page’s or blog post’s layout: "full-width" (default: full width, unboxed, no sidebar), "boxed", or "theme-default" (removes the override). hide_title (default true) turns off Astra’s own title (and, on posts, its featured image), for pages whose design has its own heading. Elementor-built pages set to full width also get the Elementor Full Width page template. Live or scheduled pages need approved: true. Returns before and after values; clears page caches.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'    => array( 'type' => 'integer' ),
					'layout'     => array(
						'type'    => 'string',
						'enum'    => array( 'full-width', 'boxed', 'theme-default' ),
						'default' => 'full-width',
					),
					'hide_title' => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'approved'   => array(
						'type'        => 'boolean',
						'description' => 'Required when the page is published or scheduled. Set true only after the person has approved.',
					),
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_set_page_layout',
			'permission_callback' => function () {
				return current_user_can( 'edit_pages' );
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
