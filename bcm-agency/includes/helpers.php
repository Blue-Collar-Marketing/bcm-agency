<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers an ability in the bcm-agency category, exposed to MCP, with
 * consistent logging. Write abilities are logged on success and on failure.
 *
 * @param string $name     e.g. 'bcm/create-post'.
 * @param array  $args     Standard wp_register_ability() args.
 * @param bool   $readonly Read-only abilities are not logged.
 */
function bcm_agency_register( $name, array $args, $readonly = false ) {
	$execute = $args['execute_callback'];

	$args['category'] = 'bcm-agency';
	$args['meta']     = array_merge(
		array(
			'public'       => true,                                   // WordPress 7.1+.
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true, 'type' => 'tool' ), // MCP Adapter.
			'annotations'  => array(
				'readonly'    => $readonly,
				'destructive' => false,
			),
		),
		isset( $args['meta'] ) ? $args['meta'] : array()
	);

	$args['execute_callback'] = function ( $input = array() ) use ( $name, $execute, $readonly ) {
		$input = is_array( $input ) ? $input : array();
		try {
			$result = call_user_func( $execute, $input );
		} catch ( Throwable $e ) {
			$result = new WP_Error( 'bcm_exception', 'Unexpected error: ' . $e->getMessage() );
		}
		if ( ! $readonly ) {
			if ( is_wp_error( $result ) ) {
				BCM_Log::add( $name, bcm_agency_guess_object_id( $input ), 'error', array( 'error' => $result->get_error_message() ) );
			} else {
				$log = isset( $result['_log'] ) ? $result['_log'] : array();
				BCM_Log::add( $name, isset( $result['id'] ) ? (int) $result['id'] : bcm_agency_guess_object_id( $input ), 'ok', $log );
			}
		}
		if ( is_array( $result ) ) {
			unset( $result['_log'] );
		}
		return $result;
	};

	wp_register_ability( $name, $args );
}

function bcm_agency_guess_object_id( array $input ) {
	foreach ( array( 'post_id', 'attachment_id', 'id' ) as $key ) {
		if ( ! empty( $input[ $key ] ) ) {
			return (int) $input[ $key ];
		}
	}
	return 0;
}

/**
 * Loads a blog post the current user may edit. Only the 'post' type is in scope:
 * pages and Elementor templates stay with Elementor's own tools.
 */
function bcm_agency_get_editable_post( $post_id ) {
	$post = get_post( (int) $post_id );
	if ( ! $post || 'post' !== $post->post_type ) {
		return new WP_Error( 'bcm_not_found', 'No blog post with that ID. Only standard blog posts are handled here.' );
	}
	if ( 'trash' === $post->post_status ) {
		return new WP_Error( 'bcm_trashed', 'That post is in the trash.' );
	}
	if ( ! current_user_can( 'edit_post', $post->ID ) ) {
		return new WP_Error( 'bcm_forbidden', 'You are not allowed to edit this post.' );
	}
	return $post;
}

function bcm_agency_is_elementor_post( $post_id ) {
	return 'builder' === get_post_meta( (int) $post_id, '_elementor_edit_mode', true );
}

/**
 * Compact, consistent description of a post for ability results.
 */
function bcm_agency_format_post( WP_Post $post, $with_content = false ) {
	$thumb_id = (int) get_post_thumbnail_id( $post );
	$out      = array(
		'id'                 => $post->ID,
		'title'              => get_the_title( $post ),
		'status'             => $post->post_status,
		'slug'               => $post->post_name,
		'date'               => $post->post_date,
		'date_gmt'           => $post->post_date_gmt,
		'modified'           => $post->post_modified,
		'excerpt'            => $post->post_excerpt,
		'categories'         => array_map(
			function ( $t ) {
				return array( 'id' => $t->term_id, 'name' => $t->name );
			},
			get_the_category( $post->ID )
		),
		'tags'               => wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ),
		'featured_image_id'  => $thumb_id,
		'featured_image_url' => $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'full' ) : '',
		'focus_keyphrase'    => (string) get_post_meta( $post->ID, '_yoast_wpseo_focuskw', true ),
		'built_with_elementor' => bcm_agency_is_elementor_post( $post->ID ),
		'edit_url'           => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
		'preview_url'        => get_preview_post_link( $post ),
		'url'                => 'publish' === $post->post_status ? get_permalink( $post ) : '',
	);
	if ( $with_content ) {
		$out['content'] = $post->post_content;
	}
	return $out;
}

/**
 * Parses a publish time. Accepts ISO 8601 with an offset (2026-12-01T09:00:00-05:00)
 * or a site-local time without one (2026-12-01 09:00). Returns a DateTimeImmutable in UTC.
 */
function bcm_agency_parse_time( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return new WP_Error( 'bcm_bad_date', 'publish_at is required.' );
	}
	try {
		$has_offset = (bool) preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', $value );
		$dt         = $has_offset
			? new DateTimeImmutable( $value )
			: new DateTimeImmutable( $value, wp_timezone() );
	} catch ( Exception $e ) {
		return new WP_Error( 'bcm_bad_date', 'Could not read publish_at. Use e.g. 2026-12-01T09:00:00-05:00 or 2026-12-01 09:00 (site time).' );
	}
	return $dt->setTimezone( new DateTimeZone( 'UTC' ) );
}

/**
 * Resolves category input (IDs or exact names) to existing category IDs.
 * Never creates categories, so the site's structure stays under human control.
 */
function bcm_agency_resolve_categories( array $cats ) {
	$ids     = array();
	$missing = array();
	foreach ( $cats as $c ) {
		$term = is_numeric( $c ) ? get_term( (int) $c, 'category' ) : get_term_by( 'name', (string) $c, 'category' );
		if ( $term && ! is_wp_error( $term ) ) {
			$ids[] = (int) $term->term_id;
		} else {
			$missing[] = (string) $c;
		}
	}
	if ( $missing ) {
		return new WP_Error( 'bcm_unknown_category', 'These categories do not exist. Check bcm/list-categories for a close match, or create them first with bcm/create-term: ' . implode( ', ', $missing ) );
	}
	return array_values( array_unique( $ids ) );
}

/**
 * Confirms an attachment exists and is an image, for use as a featured image.
 */
function bcm_agency_check_image( $attachment_id ) {
	$attachment_id = (int) $attachment_id;
	if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) ) {
		return new WP_Error( 'bcm_bad_image', 'featured_image_id must be an image in the Media Library (use bcm/upload-media first).' );
	}
	return $attachment_id;
}
