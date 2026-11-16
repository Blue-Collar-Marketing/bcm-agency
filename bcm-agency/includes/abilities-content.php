<?php
/**
 * Taking posts and pages off the site: make private, or move to the trash
 * (optionally redirecting the old address to another page). Nothing here
 * deletes permanently; the trash keeps items for 30 days.
 *
 * @package BCM_Agency
 */

defined( 'ABSPATH' ) || exit;

function bcm_agency_make_private( array $input ) {
	$post = bcm_agency_get_editable_content( isset( $input['post_id'] ) ? $input['post_id'] : 0 );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$protected = bcm_agency_protected_page_reason( $post );
	if ( '' !== $protected ) {
		return new WP_Error( 'bcm_protected_page', 'This page cannot be made private from here because ' . $protected . '.' );
	}
	if ( 'private' === $post->post_status ) {
		return new WP_Error( 'bcm_no_change', 'This is already private.' );
	}
	$label = 'page' === $post->post_type ? 'page' : 'post';
	if ( empty( $input['approved'] ) ) {
		return new WP_Error( 'bcm_needs_approval', 'Making the ' . $label . ' "' . get_the_title( $post ) . '" private hides it from visitors and Google; logged-in editors still see it. Confirm with the person, then call again with approved: true.' );
	}
	$was = $post->post_status;
	$url = get_permalink( $post );
	$r   = wp_update_post(
		array(
			'ID'          => $post->ID,
			'post_status' => 'private',
		),
		true
	);
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	$out            = bcm_agency_format_post( get_post( $post->ID ) );
	$out['was']     = $was;
	$out['cleared'] = bcm_agency_purge_page_cache();
	$out['note']    = 'Hidden from visitors; nothing was deleted. If the old address had search traffic, add a redirect with bcm/add-redirect.';
	$out['_log']    = array(
		'action' => 'made private',
		'was'    => $was,
		'type'   => $post->post_type,
		'url'    => $url,
	);
	return $out;
}

function bcm_agency_trash_post( array $input ) {
	$post = bcm_agency_get_editable_content( isset( $input['post_id'] ) ? $input['post_id'] : 0 );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$protected = bcm_agency_protected_page_reason( $post );
	if ( '' !== $protected ) {
		return new WP_Error( 'bcm_protected_page', 'This page cannot be trashed from here because ' . $protected . '.' );
	}
	if ( ! current_user_can( 'delete_post', $post->ID ) ) {
		return new WP_Error( 'bcm_forbidden', 'You are not allowed to trash this.' );
	}

	// Work out the redirect first, so a bad target stops everything before anything changes.
	$old_url  = get_permalink( $post );
	$redirect = null;
	if ( isset( $input['redirect_to'] ) && '' !== trim( (string) $input['redirect_to'] ) ) {
		if ( 'publish' !== $post->post_status ) {
			return new WP_Error( 'bcm_bad_input', 'This is not published, so its address has nothing to redirect. Leave out redirect_to.' );
		}
		$redirect = bcm_agency_redirect_prepare( $old_url, $input['redirect_to'], 301 );
		if ( is_wp_error( $redirect ) ) {
			return $redirect;
		}
	}

	$label = 'page' === $post->post_type ? 'page' : 'post';
	if ( empty( $input['approved'] ) ) {
		$what = 'Moving the ' . $label . ' "' . get_the_title( $post ) . '" to the trash takes it off the site (WordPress keeps it in the trash for 30 days).';
		$what .= $redirect ? ' Its address ' . $redirect['source'] . ' will redirect (301) to ' . $redirect['target'] . '.' : ( 'publish' === $post->post_status ? ' Its address will show "not found"; pass redirect_to to send visitors to another page instead.' : '' );
		return new WP_Error( 'bcm_needs_approval', $what . ' Confirm with the person, then call again with approved: true.' );
	}

	$was     = $post->post_status;
	$trashed = wp_trash_post( $post->ID );
	if ( ! $trashed ) {
		return new WP_Error( 'bcm_trash_failed', 'WordPress did not move it to the trash.' );
	}
	$out = array(
		'id'         => $post->ID,
		'title'      => get_the_title( $post ),
		'type'       => $post->post_type,
		'was'        => $was,
		'former_url' => $old_url,
		'note'       => 'In the trash for 30 days: restore it from ' . ( 'page' === $post->post_type ? 'Pages' : 'Posts' ) . ' → Trash in wp-admin.',
	);
	if ( $redirect ) {
		$made = bcm_agency_redirect_create( $redirect );
		$out['redirect'] = is_wp_error( $made ) ? array( 'error' => $made->get_error_message() ) : $made;
	}
	$out['cleared'] = bcm_agency_purge_page_cache();
	$out['_log']    = array(
		'action'      => 'trashed',
		'was'         => $was,
		'type'        => $post->post_type,
		'url'         => $old_url,
		'redirect_id' => ( $redirect && isset( $out['redirect']['id'] ) ) ? $out['redirect']['id'] : 0,
	);
	return $out;
}

function bcm_agency_register_content_abilities() {
	$approved = array(
		'type'        => 'boolean',
		'description' => 'Set true only after the person approved this exact change.',
	);

	bcm_agency_register(
		'bcm/make-private',
		array(
			'label'               => 'Make a post or page private',
			'description'         => 'Hides a blog post or page from visitors and Google (status private); logged-in editors still see it and nothing is deleted. The homepage, blog page and privacy policy page are refused. Requires approved: true after the person confirms. Clears page caches.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'  => array( 'type' => 'integer' ),
					'approved' => $approved,
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_make_private',
			'permission_callback' => function () {
				return current_user_can( 'edit_others_posts' ) && current_user_can( 'edit_pages' );
			},
		)
	);

	bcm_agency_register(
		'bcm/trash-post',
		array(
			'label'               => 'Move a post or page to the trash',
			'description'         => 'Moves one blog post or page to the trash (restorable for 30 days; nothing is deleted permanently). Optional redirect_to: a page on this site its old address should redirect to (301, through the Redirection plugin), e.g. when a location page is removed. The homepage, blog page and privacy policy page are refused. Requires approved: true after the person confirms; through the BCM hub, on a launched site (not one still on its Cloudways temporary address) it also needs Chris\'s second confirmation.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'     => array( 'type' => 'integer' ),
					'redirect_to' => array( 'type' => 'string' ),
					'approved'    => $approved,
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_trash_post',
			'permission_callback' => function () {
				return current_user_can( 'delete_others_posts' ) && current_user_can( 'delete_pages' );
			},
		)
	);
}
