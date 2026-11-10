<?php
defined( 'ABSPATH' ) || exit;

/*
 * Find and replace images in Elementor content.
 *
 * Elementor renders images from their Media Library ID, so swapping only the
 * URL text is not enough. These abilities treat an image as a unit: any setting
 * node that holds both an "id" and a "url" (Image widget, Image Box, section /
 * column / container backgrounds, galleries, and Atomic images whose values are
 * wrapped in {"$$type": ..., "value": ...}) and update id, url and alt together.
 */

/** Plain value of a possibly typed Atomic value. */
function bcm_agency_typed_value( $v ) {
	if ( is_array( $v ) && array_key_exists( 'value', $v ) && isset( $v['$$type'] ) ) {
		return $v['value'];
	}
	return $v;
}

/** Sets a value, keeping the Atomic typed wrapper if there is one. */
function bcm_agency_set_typed( &$slot, $value ) {
	if ( is_array( $slot ) && isset( $slot['$$type'] ) && array_key_exists( 'value', $slot ) ) {
		$slot['value'] = $value;
	} else {
		$slot = $value;
	}
}

/**
 * Walks settings and collects image nodes: arrays with both "id" and "url" keys
 * (at any depth). $path is the dotted key path to the node, used to target it later.
 */
function bcm_agency_collect_image_nodes( array $node, $path, array &$out ) {
	if ( array_key_exists( 'id', $node ) && array_key_exists( 'url', $node ) ) {
		$id  = bcm_agency_typed_value( $node['id'] );
		$url = bcm_agency_typed_value( $node['url'] );
		if ( ( is_numeric( $id ) && (int) $id > 0 ) || ( is_string( $url ) && '' !== $url ) ) {
			$out[] = array(
				'field' => $path,
				'id'    => is_numeric( $id ) ? (int) $id : 0,
				'url'   => is_string( $url ) ? $url : '',
				'alt'   => isset( $node['alt'] ) && is_string( $node['alt'] ) ? $node['alt'] : '',
			);
		}
	}
	foreach ( $node as $k => $v ) {
		if ( is_array( $v ) && '$$type' !== $k ) {
			bcm_agency_collect_image_nodes( $v, '' === $path ? (string) $k : $path . '.' . $k, $out );
		}
	}
}

/** Collects image placements across an element tree. */
function bcm_agency_collect_images( array $elements, array &$found ) {
	foreach ( $elements as $el ) {
		if ( ! is_array( $el ) ) {
			continue;
		}
		$label = isset( $el['widgetType'] ) ? $el['widgetType'] : ( isset( $el['elType'] ) ? $el['elType'] : 'element' );
		if ( isset( $el['settings'] ) && is_array( $el['settings'] ) ) {
			$nodes = array();
			bcm_agency_collect_image_nodes( $el['settings'], '', $nodes );
			foreach ( $nodes as $n ) {
				$found[] = array_merge(
					array(
						'element_id' => isset( $el['id'] ) ? (string) $el['id'] : '',
						'widget'     => $label,
						'kind'       => false !== strpos( $n['field'], 'background' ) ? 'background' : 'image',
					),
					$n
				);
			}
		}
		if ( isset( $el['elements'] ) && is_array( $el['elements'] ) ) {
			bcm_agency_collect_images( $el['elements'], $found );
		}
	}
}

/** Returns a reference to an element's settings array by element id. */
function &bcm_agency_find_element_settings( array &$elements, $element_id ) {
	$null = null;
	foreach ( $elements as &$el ) {
		if ( ! is_array( $el ) ) {
			continue;
		}
		if ( isset( $el['id'] ) && (string) $el['id'] === (string) $element_id ) {
			if ( ! isset( $el['settings'] ) || ! is_array( $el['settings'] ) ) {
				$el['settings'] = array();
			}
			return $el['settings'];
		}
		if ( isset( $el['elements'] ) && is_array( $el['elements'] ) ) {
			$found = &bcm_agency_find_element_settings( $el['elements'], $element_id );
			if ( null !== $found ) {
				return $found;
			}
		}
	}
	unset( $el );
	return $null;
}

function bcm_agency_find_images( array $input ) {
	global $wpdb;
	$attachment_id = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;
	$contains      = isset( $input['url_contains'] ) ? trim( (string) $input['url_contains'] ) : '';
	$post_id       = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	if ( ! $attachment_id && '' === $contains && ! $post_id ) {
		return new WP_Error( 'bcm_bad_input', 'Pass url_contains (part of the file name), attachment_id, or post_id to list every image on one page.' );
	}

	$statuses = "'" . implode( "','", array_map( 'esc_sql', bcm_agency_replace_statuses() ) ) . "'";
	$where    = array();
	$args     = array();
	if ( $post_id ) {
		$where[] = 'p.ID = %d';
		$args[]  = $post_id;
	}
	$likes = array();
	if ( '' !== $contains ) {
		$likes[] = 'm.meta_value LIKE %s';
		$args[]  = '%' . $wpdb->esc_like( $contains ) . '%';
	}
	if ( $attachment_id ) {
		$file = (string) get_attached_file( $attachment_id );
		if ( $file ) {
			$likes[] = 'm.meta_value LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( pathinfo( $file, PATHINFO_FILENAME ) ) . '%';
		}
		$likes[] = 'm.meta_value LIKE %s';
		$args[]  = '%' . $wpdb->esc_like( '"id":' . $attachment_id ) . '%';
		$likes[] = 'm.meta_value LIKE %s';
		$args[]  = '%' . $wpdb->esc_like( '"value":' . $attachment_id ) . '%';
	}
	if ( $likes ) {
		$where[] = '(' . implode( ' OR ', $likes ) . ')';
	}
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$sql = "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
	        WHERE m.meta_key = '_elementor_data' AND p.post_type <> 'revision' AND p.post_status IN ($statuses)
	        AND " . implode( ' AND ', $where );
	$ids = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
	// phpcs:enable

	$results = array();
	foreach ( $ids as $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			continue;
		}
		$data = json_decode( (string) get_post_meta( $post->ID, '_elementor_data', true ), true );
		if ( ! is_array( $data ) ) {
			continue;
		}
		$found = array();
		bcm_agency_collect_images( $data, $found );
		foreach ( $found as $f ) {
			$match = $post_id && ! $attachment_id && '' === $contains;
			if ( $attachment_id && $f['id'] === $attachment_id ) {
				$match = true;
			}
			if ( '' !== $contains && false !== stripos( $f['url'], $contains ) ) {
				$match = true;
			}
			if ( ! $match ) {
				continue;
			}
			if ( ! $f['url'] && $f['id'] ) {
				$f['url'] = (string) wp_get_attachment_url( $f['id'] );
			}
			$results[] = array_merge(
				array(
					'post_id'   => $post->ID,
					'page'      => get_the_title( $post ),
					'type'      => bcm_agency_post_label( $post ),
					'status'    => $post->post_status,
					'view_url'  => 'publish' === $post->post_status && 'elementor_library' !== $post->post_type ? get_permalink( $post ) : '',
					'site_wide' => 'elementor_library' === $post->post_type && in_array( get_post_meta( $post->ID, '_elementor_template_type', true ), array( 'header', 'footer', 'popup', 'widget', 'kit' ), true ),
				),
				$f
			);
		}
	}

	return array(
		'images'    => $results,
		'count'     => count( $results ),
		'next_step' => $results ? 'Show the person where each image is used. To swap one, upload or pick the new image in the Media Library, then call bcm/replace-image with post_id, element_id, field and new_attachment_id, plus approved: true.' : 'No Elementor images matched. Featured images, logos set in theme settings and images inside text blocks are not covered here.',
	);
}

function bcm_agency_replace_image( array $input ) {
	if ( empty( $input['approved'] ) ) {
		return new WP_Error( 'bcm_needs_approval', 'Swapping an image changes a live page. Show the person the placement (bcm/find-images) and the new image, then call again with approved: true.' );
	}
	$post_id    = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$element_id = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
	$field      = isset( $input['field'] ) ? trim( (string) $input['field'] ) : '';
	$new_id     = isset( $input['new_attachment_id'] ) ? (int) $input['new_attachment_id'] : 0;
	if ( ! $post_id || '' === $element_id || '' === $field || ! $new_id ) {
		return new WP_Error( 'bcm_bad_input', 'post_id, element_id, field (all from bcm/find-images) and new_attachment_id are required.' );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'bcm_forbidden', 'You cannot edit this page.' );
	}
	$checked = bcm_agency_check_image( $new_id );
	if ( is_wp_error( $checked ) ) {
		return new WP_Error( 'bcm_bad_image', 'new_attachment_id must be an image in this site\'s Media Library (upload it first, or use bcm/upload-media).' );
	}

	$data = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'bcm_not_found', 'No Elementor data on that page.' );
	}
	$settings = &bcm_agency_find_element_settings( $data, $element_id );
	if ( null === $settings ) {
		return new WP_Error( 'bcm_not_found', 'No element with that element_id on that page. Run bcm/find-images again.' );
	}

	// Walk to the image node by its dotted path.
	$node = &$settings;
	foreach ( explode( '.', $field ) as $seg ) {
		if ( ! is_array( $node ) || ! array_key_exists( $seg, $node ) ) {
			return new WP_Error( 'bcm_not_found', 'That field is not on the element any more. Run bcm/find-images again.' );
		}
		$node = &$node[ $seg ];
	}
	if ( ! is_array( $node ) || ! array_key_exists( 'id', $node ) || ! array_key_exists( 'url', $node ) ) {
		return new WP_Error( 'bcm_not_found', 'That field is not an image.' );
	}

	$before = array(
		'id'  => (int) bcm_agency_typed_value( $node['id'] ),
		'url' => (string) bcm_agency_typed_value( $node['url'] ),
	);
	if ( $before['id'] === $new_id ) {
		return new WP_Error( 'bcm_no_change', 'That spot already uses this image.' );
	}

	$new_url = (string) wp_get_attachment_url( $new_id );
	$new_alt = (string) get_post_meta( $new_id, '_wp_attachment_image_alt', true );

	// Alt text: an explicit alt_text wins; otherwise, if the new image has none in the
	// Media Library, carry over the old image's alt so the page doesn't lose it.
	$alt_note = '';
	if ( ! isset( $input['alt_text'] ) && '' === trim( $new_alt ) ) {
		$old_alt = '';
		if ( $before['id'] ) {
			$old_alt = (string) get_post_meta( $before['id'], '_wp_attachment_image_alt', true );
		}
		if ( '' === trim( $old_alt ) && isset( $node['alt'] ) && is_string( $node['alt'] ) ) {
			$old_alt = $node['alt'];
		}
		if ( '' !== trim( $old_alt ) ) {
			update_post_meta( $new_id, '_wp_attachment_image_alt', sanitize_text_field( $old_alt ) );
			$new_alt  = sanitize_text_field( $old_alt );
			$alt_note = 'The new image had no alt text, so the previous image\'s alt text was copied to it.';
		} else {
			$alt_note = 'Neither image has alt text. Ask the person for a short description and set it with alt_text (good for SEO and accessibility).';
		}
	}

	bcm_agency_set_typed( $node['id'], $new_id );
	// Atomic library images often store url as null; keep that shape.
	if ( null !== bcm_agency_typed_value( $node['url'] ) ) {
		bcm_agency_set_typed( $node['url'], $new_url );
	}
	if ( array_key_exists( 'alt', $node ) ) {
		$node['alt'] = $new_alt;
	}
	if ( isset( $input['alt_text'] ) ) {
		// Alt text belongs to the image in the Media Library, so it follows the image everywhere.
		update_post_meta( $new_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );
		if ( array_key_exists( 'alt', $node ) ) {
			$node['alt'] = sanitize_text_field( $input['alt_text'] );
		}
	}
	unset( $node, $settings );

	$how    = bcm_agency_save_elementor_data( $post_id, $data );
	$purged = bcm_agency_purge_page_cache();

	return array(
		'id'             => $post_id,
		'page'           => get_the_title( $post_id ),
		'element_id'     => $element_id,
		'field'          => $field,
		'before'         => $before,
		'after'          => array(
			'id'  => $new_id,
			'url' => $new_url,
			'alt' => (string) get_post_meta( $new_id, '_wp_attachment_image_alt', true ),
		),
		'alt_text_note'  => $alt_note,
		'saved_by'       => $how,
		'caches_cleared' => $purged,
		'note'           => 'The old image stays in the Media Library (nothing is deleted). Undo: the page\'s Revisions (Elementor: History → Revisions).',
		'_log'           => array(
			'element' => $element_id,
			'field'   => $field,
			'from'    => $before['id'],
			'to'      => $new_id,
		),
	);
}

function bcm_agency_list_media( array $input ) {
	$q = new WP_Query(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			's'              => isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '',
			'posts_per_page' => isset( $input['per_page'] ) ? max( 1, min( 50, (int) $input['per_page'] ) ) : 20,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);
	return array(
		'images' => array_map(
			function ( $p ) {
				$meta = wp_get_attachment_metadata( $p->ID );
				return array(
					'id'       => $p->ID,
					'title'    => get_the_title( $p ),
					'filename' => basename( (string) get_attached_file( $p->ID ) ),
					'url'      => wp_get_attachment_url( $p->ID ),
					'alt'      => (string) get_post_meta( $p->ID, '_wp_attachment_image_alt', true ),
					'width'    => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
					'height'   => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
					'uploaded' => $p->post_date,
				);
			},
			$q->posts
		),
	);
}

function bcm_agency_register_image_abilities() {
	bcm_agency_register(
		'bcm/find-images',
		array(
			'label'               => 'Find where images are used',
			'description'         => 'Read-only. Finds Elementor image placements (Image and Image Box widgets, section/column/container backgrounds, galleries, Atomic images) by part of the file name (url_contains), by Media Library attachment_id, or lists every image on one page (post_id). Returns page, element_id, widget, field, current image id/url/alt. Covers every Elementor version and theme-builder templates.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'url_contains'  => array(
						'type'        => 'string',
						'description' => 'Part of the image file name, e.g. "Spikeball".',
					),
					'attachment_id' => array( 'type' => 'integer' ),
					'post_id'       => array(
						'type'        => 'integer',
						'description' => 'Limit to one page; alone, lists every image on it.',
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_find_images',
			'permission_callback' => function () {
				return current_user_can( 'edit_pages' );
			},
		),
		true
	);

	bcm_agency_register(
		'bcm/list-media',
		array(
			'label'               => 'List Media Library images',
			'description'         => 'Read-only. Lists recent Media Library images (newest first), optionally filtered by search text, with id, file name, url, alt text and dimensions. Use to find the new image\'s attachment id after it is uploaded.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'   => array( 'type' => 'string' ),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 50,
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_list_media',
			'permission_callback' => function () {
				return current_user_can( 'upload_files' );
			},
		),
		true
	);

	bcm_agency_register(
		'bcm/replace-image',
		array(
			'label'               => 'Swap an image on a page',
			'description'         => 'Replaces one image placement found by bcm/find-images with another Media Library image, updating id, url and alt together (so Elementor actually shows the new image). Works on classic and Atomic Elementor, including backgrounds. Optional alt_text updates the new image\'s alt in the Media Library. Requires approved: true; saves a revision for undo; clears page caches; never deletes the old image.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'           => array( 'type' => 'integer' ),
					'element_id'        => array( 'type' => 'string' ),
					'field'             => array(
						'type'        => 'string',
						'description' => 'The field from bcm/find-images, e.g. "image" or "background_image".',
					),
					'new_attachment_id' => array( 'type' => 'integer' ),
					'alt_text'          => array( 'type' => 'string' ),
					'approved'          => array(
						'type'        => 'boolean',
						'description' => 'Set true only after the person approved this swap.',
					),
				),
				'required'             => array( 'post_id', 'element_id', 'field', 'new_attachment_id' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_replace_image',
			'permission_callback' => function () {
				return current_user_can( 'edit_pages' ) && current_user_can( 'edit_others_posts' );
			},
		)
	);
}
