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
 * Is this settings node an image? Classic widgets and backgrounds store both "id"
 * and "url". Atomic (V4) images placed from the Media Library by id (as Elementor's
 * own build tools do) store only the id, typed "image-attachment-id", inside an
 * "src" slot, with no "url" key at all.
 */
function bcm_agency_is_image_node( array $node, $path ) {
	if ( ! array_key_exists( 'id', $node ) ) {
		return false;
	}
	if ( array_key_exists( 'url', $node ) ) {
		return true;
	}
	if ( is_array( $node['id'] ) && isset( $node['id']['$$type'] ) && 'image-attachment-id' === $node['id']['$$type'] ) {
		return true;
	}
	$segments = array_values( array_diff( explode( '.', (string) $path ), array( 'value', '' ) ) );
	return $segments && 'src' === end( $segments );
}

/**
 * Walks settings and collects image nodes (see bcm_agency_is_image_node) at any
 * depth. $path is the dotted key path to the node, used to target it later.
 */
function bcm_agency_collect_image_nodes( array $node, $path, array &$out ) {
	if ( bcm_agency_is_image_node( $node, $path ) ) {
		$id  = bcm_agency_typed_value( $node['id'] );
		$url = array_key_exists( 'url', $node ) ? bcm_agency_typed_value( $node['url'] ) : null;
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
	if ( ! is_array( $node ) || ! bcm_agency_is_image_node( $node, $field ) ) {
		return new WP_Error( 'bcm_not_found', 'That field is not an image.' );
	}

	$before_id = (int) bcm_agency_typed_value( $node['id'] );
	$before    = array(
		'id'  => $before_id,
		'url' => array_key_exists( 'url', $node ) && is_string( bcm_agency_typed_value( $node['url'] ) ) ? (string) bcm_agency_typed_value( $node['url'] ) : (string) wp_get_attachment_url( $before_id ),
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
	// Atomic library images store url as null, or no url at all; keep that shape.
	if ( array_key_exists( 'url', $node ) && null !== bcm_agency_typed_value( $node['url'] ) ) {
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

/**
 * Lists Media Library images, newest first. Reads the database directly (like
 * find-images) rather than through WP_Query, because media-folder, privacy and
 * custom snippets often reshape attachment queries and can hide every image.
 * search matches the title, file name or alt text (e.g. "greyscale").
 */
function bcm_agency_list_media( array $input ) {
	global $wpdb;
	$per_page = isset( $input['per_page'] ) ? max( 1, min( 50, (int) $input['per_page'] ) ) : 20;
	$search   = isset( $input['search'] ) ? trim( sanitize_text_field( $input['search'] ) ) : '';

	$sql  = "SELECT p.ID FROM {$wpdb->posts} p
	         WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%' AND p.post_status IN ('inherit', 'private', 'publish')";
	$args = array();
	if ( '' !== $search ) {
		$like  = '%' . $wpdb->esc_like( $search ) . '%';
		$sql  .= " AND ( p.post_title LIKE %s OR p.post_name LIKE %s
		           OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key IN ('_wp_attached_file', '_wp_attachment_image_alt') AND m.meta_value LIKE %s ) )";
		$args  = array( $like, $like, $like );
	}
	$sql   .= ' ORDER BY p.post_date DESC, p.ID DESC LIMIT %d';
	$args[] = $per_page;
	$ids    = $wpdb->get_col( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL

	$images = array();
	foreach ( (array) $ids as $id ) {
		$p = get_post( (int) $id );
		if ( ! $p ) {
			continue;
		}
		$meta     = wp_get_attachment_metadata( $p->ID );
		$images[] = array(
			'id'       => $p->ID,
			'title'    => get_the_title( $p ),
			'filename' => basename( (string) get_attached_file( $p->ID ) ),
			'url'      => wp_get_attachment_url( $p->ID ),
			'alt'      => (string) get_post_meta( $p->ID, '_wp_attachment_image_alt', true ),
			'width'    => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'   => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			'uploaded' => $p->post_date,
		);
	}
	return array(
		'images' => $images,
		'count'  => count( $images ),
		'note'   => $images ? '' : ( '' !== $search ? 'No images match "' . $search . '" in the title, file name or alt text. Try part of the file name.' : 'The Media Library has no images.' ),
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
			'description'         => 'Read-only. Lists Media Library images (newest first) with id, file name, url, alt text and dimensions. search matches the title, file name or alt text, e.g. "greyscale" or "spikeball", so use it to find an existing version of an image (a greyscale or older copy) or a new upload\'s attachment id.',
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

/*
 * Gallery images: add or remove pictures in Elementor galleries and carousels,
 * classic widgets included.
 *   - Simple galleries (Basic Gallery "wp_gallery", Pro Gallery "gallery",
 *     Image Carousel "carousel") store a plain list of { id, url }.
 *   - Slide-based carousels (Media Carousel "slides") store repeater items
 *     with an image inside; a new slide copies an existing one (same settings).
 */

/** True for a plain list of { id, url } image entries. */
function bcm_agency_is_image_list( $value ) {
	if ( ! is_array( $value ) || ! $value || array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
		return false;
	}
	foreach ( $value as $item ) {
		if ( ! is_array( $item ) || ! array_key_exists( 'id', $item ) || ! array_key_exists( 'url', $item ) || isset( $item['_id'] ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Describes the gallery fields on a widget's settings:
 *   simple  - a plain image list (Basic Gallery, Pro Gallery single, Image Carousel)
 *   slides  - repeater items each holding one image (Media Carousel)
 *   tabbed  - repeater items each holding a title and an image list (Pro Gallery "multiple galleries")
 */
function bcm_agency_gallery_fields( array $settings ) {
	$out = array();
	foreach ( $settings as $key => $value ) {
		if ( bcm_agency_is_image_list( $value ) ) {
			$out[ $key ] = array( 'kind' => 'simple', 'count' => count( $value ) );
			continue;
		}
		if ( ! is_array( $value ) || ! $value || array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			continue;
		}
		$list_key  = null;
		$image_key = null;
		$title_key = null;
		$valid     = true;
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['_id'] ) ) {
				$valid = false;
				break;
			}
			foreach ( $item as $k => $v ) {
				if ( null === $list_key && bcm_agency_is_image_list( $v ) ) {
					$list_key = $k;
				} elseif ( null === $image_key && is_array( $v ) && array_key_exists( 'id', $v ) && array_key_exists( 'url', $v ) ) {
					$image_key = $k;
				} elseif ( null === $title_key && is_string( $v ) && false !== strpos( $k, 'title' ) ) {
					$title_key = $k;
				}
			}
		}
		if ( ! $valid ) {
			continue;
		}
		if ( $list_key ) {
			$out[ $key ] = array( 'kind' => 'tabbed', 'list_key' => $list_key, 'title_key' => $title_key, 'count' => count( $value ) );
		} elseif ( $image_key ) {
			$out[ $key ] = array( 'kind' => 'slides', 'image_key' => $image_key, 'count' => count( $value ) );
		}
	}
	return $out;
}

function bcm_agency_image_rows( array $list ) {
	$out = array();
	foreach ( array_values( $list ) as $i => $node ) {
		$out[] = array(
			'index'         => $i,
			'attachment_id' => isset( $node['id'] ) ? (int) $node['id'] : 0,
			'file'          => isset( $node['url'] ) ? basename( (string) wp_parse_url( (string) $node['url'], PHP_URL_PATH ) ) : '',
		);
	}
	return $out;
}

function bcm_agency_gallery_preview( array $items, array $info ) {
	if ( 'simple' === $info['kind'] ) {
		return bcm_agency_image_rows( $items );
	}
	if ( 'slides' === $info['kind'] ) {
		$nodes = array();
		foreach ( $items as $item ) {
			$nodes[] = isset( $item[ $info['image_key'] ] ) && is_array( $item[ $info['image_key'] ] ) ? $item[ $info['image_key'] ] : array();
		}
		return bcm_agency_image_rows( $nodes );
	}
	$tabs = array();
	foreach ( array_values( $items ) as $t => $tab ) {
		$tabs[] = array(
			'tab_index' => $t,
			'tab_id'    => (string) $tab['_id'],
			'title'     => $info['title_key'] && isset( $tab[ $info['title_key'] ] ) ? (string) $tab[ $info['title_key'] ] : '',
			'images'    => isset( $tab[ $info['list_key'] ] ) && is_array( $tab[ $info['list_key'] ] ) ? bcm_agency_image_rows( $tab[ $info['list_key'] ] ) : array(),
		);
	}
	return $tabs;
}

/** Finds a tab by index or exact (case-insensitive) title. */
function bcm_agency_find_tab( array $tabs, array $info, $tab ) {
	if ( is_numeric( $tab ) && isset( $tabs[ (int) $tab ] ) ) {
		return (int) $tab;
	}
	if ( is_string( $tab ) && '' !== $tab && $info['title_key'] ) {
		foreach ( array_values( $tabs ) as $t => $row ) {
			if ( isset( $row[ $info['title_key'] ] ) && 0 === strcasecmp( trim( wp_strip_all_tags( (string) $row[ $info['title_key'] ] ) ), trim( $tab ) ) ) {
				return $t;
			}
		}
	}
	return new WP_Error( 'bcm_bad_tab', 'Pass tab as a tab index or its exact title. Tabs: ' . implode( ', ', wp_list_pluck( bcm_agency_gallery_preview( $tabs, $info ), 'title' ) ) . '.' );
}

/** Shared loader: returns [ &settings, data, field, info ] or WP_Error. */
function bcm_agency_gallery_target( array $input, array &$data ) {
	$post_id    = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$element_id = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
	if ( ! $post_id || '' === $element_id ) {
		return new WP_Error( 'bcm_bad_input', 'post_id and element_id are required (from bcm/find-images).' );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'bcm_forbidden', 'You cannot edit this page.' );
	}
	$data = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'bcm_not_found', 'No Elementor data on that page.' );
	}
	return true;
}

function bcm_agency_pick_gallery( array $settings, $field ) {
	$galleries = bcm_agency_gallery_fields( $settings );
	if ( ! $galleries ) {
		return new WP_Error( 'bcm_no_gallery', 'That element has no gallery or carousel images. (Pro Gallery in "multiple galleries" mode is edited in the Elementor editor.)' );
	}
	if ( '' !== $field ) {
		if ( ! isset( $galleries[ $field ] ) ) {
			return new WP_Error( 'bcm_bad_field', 'No gallery called "' . $field . '" here. Galleries: ' . implode( ', ', array_keys( $galleries ) ) . '.' );
		}
		return array( $field, $galleries[ $field ] );
	}
	if ( 1 !== count( $galleries ) ) {
		return new WP_Error( 'bcm_ambiguous', 'Several galleries on that element (' . implode( ', ', array_keys( $galleries ) ) . '). Pass field.' );
	}
	$key = (string) key( $galleries );
	return array( $key, $galleries[ $key ] );
}

function bcm_agency_add_gallery_image( array $input ) {
	if ( empty( $input['approved'] ) ) {
		return new WP_Error( 'bcm_needs_approval', 'Adding a gallery image changes a live page. Show the person the image and where it goes, then call again with approved: true.' );
	}
	$data = array();
	$ok   = bcm_agency_gallery_target( $input, $data );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$post_id = (int) $input['post_id'];
	$new_id  = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;
	$checked = bcm_agency_check_image( $new_id );
	if ( is_wp_error( $checked ) ) {
		return new WP_Error( 'bcm_bad_image', 'attachment_id must be an image in this site\'s Media Library (upload it first with bcm/upload-media).' );
	}
	$settings = &bcm_agency_find_element_settings( $data, (string) $input['element_id'] );
	if ( null === $settings ) {
		return new WP_Error( 'bcm_not_found', 'No element with that element_id on that page.' );
	}
	$picked = bcm_agency_pick_gallery( $settings, isset( $input['field'] ) ? (string) $input['field'] : '' );
	if ( is_wp_error( $picked ) ) {
		return $picked;
	}
	list( $field, $info ) = $picked;
	$items = array_values( $settings[ $field ] );
	$url   = (string) wp_get_attachment_url( $new_id );
	$tab_i = null;

	if ( 'tabbed' === $info['kind'] ) {
		$tab_i = bcm_agency_find_tab( $items, $info, isset( $input['tab'] ) ? $input['tab'] : null );
		if ( is_wp_error( $tab_i ) ) {
			return $tab_i;
		}
		$list = isset( $items[ $tab_i ][ $info['list_key'] ] ) ? array_values( $items[ $tab_i ][ $info['list_key'] ] ) : array();
	} else {
		$list = $items;
	}
	$rows = 'tabbed' === $info['kind'] ? bcm_agency_image_rows( $list ) : bcm_agency_gallery_preview( $list, $info );
	foreach ( $rows as $row ) {
		if ( $row['attachment_id'] === $new_id && empty( $input['allow_duplicate'] ) ) {
			return new WP_Error( 'bcm_duplicate', 'That image is already in this gallery' . ( null !== $tab_i ? ' tab' : '' ) . ' (position ' . $row['index'] . ').' );
		}
	}

	if ( 'slides' === $info['kind'] ) {
		$copy_from = isset( $input['copy_from'] ) ? (int) $input['copy_from'] : count( $list ) - 1;
		if ( ! isset( $list[ $copy_from ] ) ) {
			return new WP_Error( 'bcm_bad_input', 'copy_from must be an existing slide index.' );
		}
		$new        = $list[ $copy_from ];
		$new['_id'] = substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 7 );
		$img        = is_array( $new[ $info['image_key'] ] ) ? $new[ $info['image_key'] ] : array();
		$img['id']  = $new_id;
		$img['url'] = $url;
		$new[ $info['image_key'] ] = $img;
	} else {
		$new = array( 'id' => $new_id, 'url' => $url );
	}
	$position = isset( $input['position'] ) ? (int) $input['position'] : count( $list );
	$position = max( 0, min( count( $list ), $position ) );
	array_splice( $list, $position, 0, array( $new ) );

	if ( 'tabbed' === $info['kind'] ) {
		$items[ $tab_i ][ $info['list_key'] ] = $list;
	} else {
		$items = $list;
	}
	$settings[ $field ] = $items;
	$after              = $items;
	unset( $settings );

	$how    = bcm_agency_save_elementor_data( $post_id, $data );
	$purged = bcm_agency_purge_page_cache();
	$alt    = (string) get_post_meta( $new_id, '_wp_attachment_image_alt', true );
	return array(
		'id'             => $post_id,
		'element_id'     => (string) $input['element_id'],
		'field'          => $field,
		'tab'            => $tab_i,
		'added_at_index' => $position,
		'gallery'        => bcm_agency_gallery_preview( $after, $info ),
		'alt_text_note'  => '' === trim( $alt ) ? 'This image has no alt text in the Media Library. Ask the person for a short description and set it.' : '',
		'saved_by'       => $how,
		'caches_cleared' => $purged,
		'note'           => "Undo: the page's Revisions (Elementor: History → Revisions).",
		'_log'           => array(
			'element' => (string) $input['element_id'],
			'field'   => $field,
			'tab'     => $tab_i,
			'added'   => $new_id,
			'index'   => $position,
		),
	);
}

function bcm_agency_remove_gallery_image( array $input ) {
	if ( empty( $input['approved'] ) ) {
		return new WP_Error( 'bcm_needs_approval', 'Removing a gallery image changes a live page. Confirm which image with the person, then call again with approved: true.' );
	}
	$data = array();
	$ok   = bcm_agency_gallery_target( $input, $data );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$post_id  = (int) $input['post_id'];
	$settings = &bcm_agency_find_element_settings( $data, (string) $input['element_id'] );
	if ( null === $settings ) {
		return new WP_Error( 'bcm_not_found', 'No element with that element_id on that page.' );
	}
	$picked = bcm_agency_pick_gallery( $settings, isset( $input['field'] ) ? (string) $input['field'] : '' );
	if ( is_wp_error( $picked ) ) {
		return $picked;
	}
	list( $field, $info ) = $picked;
	$items = array_values( $settings[ $field ] );
	$tab_i = null;
	if ( 'tabbed' === $info['kind'] ) {
		$tab_i = bcm_agency_find_tab( $items, $info, isset( $input['tab'] ) ? $input['tab'] : null );
		if ( is_wp_error( $tab_i ) ) {
			return $tab_i;
		}
		$list = isset( $items[ $tab_i ][ $info['list_key'] ] ) ? array_values( $items[ $tab_i ][ $info['list_key'] ] ) : array();
		$rows = bcm_agency_image_rows( $list );
	} else {
		$list = $items;
		$rows = bcm_agency_gallery_preview( $list, $info );
	}
	$index = null;
	if ( isset( $input['index'] ) ) {
		$index = (int) $input['index'];
	} elseif ( ! empty( $input['attachment_id'] ) ) {
		$hits = array_values(
			array_filter(
				$rows,
				function ( $r ) use ( $input ) {
					return $r['attachment_id'] === (int) $input['attachment_id'];
				}
			)
		);
		if ( count( $hits ) > 1 ) {
			return new WP_Error( 'bcm_ambiguous', 'That image appears more than once here. Pass index instead.' );
		}
		$index = $hits ? $hits[0]['index'] : null;
	}
	if ( null === $index || ! isset( $list[ $index ] ) ) {
		return new WP_Error( 'bcm_not_found', 'Pass index or attachment_id of an image in this gallery' . ( null !== $tab_i ? ' tab' : '' ) . ' (see bcm/find-images).' );
	}
	if ( 1 === count( $list ) ) {
		return new WP_Error( 'bcm_last_item', 'That is the only image here. Remove the whole tab with bcm/remove-list-item, or the gallery in the Elementor editor.' );
	}
	$removed = $rows[ $index ];
	array_splice( $list, $index, 1 );
	if ( 'tabbed' === $info['kind'] ) {
		$items[ $tab_i ][ $info['list_key'] ] = $list;
	} else {
		$items = $list;
	}
	$settings[ $field ] = $items;
	$after              = $items;
	unset( $settings );

	$how    = bcm_agency_save_elementor_data( $post_id, $data );
	$purged = bcm_agency_purge_page_cache();
	return array(
		'id'             => $post_id,
		'element_id'     => (string) $input['element_id'],
		'field'          => $field,
		'tab'            => $tab_i,
		'removed'        => $removed,
		'gallery'        => bcm_agency_gallery_preview( $after, $info ),
		'saved_by'       => $how,
		'caches_cleared' => $purged,
		'note'           => "The image stays in the Media Library. Undo: the page's Revisions (Elementor: History → Revisions).",
		'_log'           => array(
			'element' => (string) $input['element_id'],
			'field'   => $field,
			'tab'     => $tab_i,
			'removed' => $removed,
		),
	);
}

/** Adds a new tab to a tabbed gallery, copying an existing tab's settings, with its own title and images. */
function bcm_agency_add_gallery_tab( array $input ) {
	if ( empty( $input['approved'] ) ) {
		return new WP_Error( 'bcm_needs_approval', 'Adding a gallery tab changes a live page. Confirm the tab title, images and position, then call again with approved: true.' );
	}
	$data = array();
	$ok   = bcm_agency_gallery_target( $input, $data );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$post_id = (int) $input['post_id'];
	$title   = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '';
	$ids     = isset( $input['attachment_ids'] ) ? array_values( array_unique( array_map( 'intval', (array) $input['attachment_ids'] ) ) ) : array();
	if ( '' === $title || ! $ids ) {
		return new WP_Error( 'bcm_bad_input', 'title and at least one attachment_id are required.' );
	}
	$list = array();
	foreach ( $ids as $id ) {
		if ( is_wp_error( bcm_agency_check_image( $id ) ) ) {
			return new WP_Error( 'bcm_bad_image', 'Attachment ' . $id . ' is not an image in this site\'s Media Library.' );
		}
		$list[] = array( 'id' => $id, 'url' => (string) wp_get_attachment_url( $id ) );
	}
	$settings = &bcm_agency_find_element_settings( $data, (string) $input['element_id'] );
	if ( null === $settings ) {
		return new WP_Error( 'bcm_not_found', 'No element with that element_id on that page.' );
	}
	$picked = bcm_agency_pick_gallery( $settings, isset( $input['field'] ) ? (string) $input['field'] : '' );
	if ( is_wp_error( $picked ) ) {
		return $picked;
	}
	list( $field, $info ) = $picked;
	if ( 'tabbed' !== $info['kind'] ) {
		return new WP_Error( 'bcm_not_tabbed', 'That gallery has no tabs. Use bcm/add-gallery-image instead.' );
	}
	$tabs = array_values( $settings[ $field ] );
	foreach ( bcm_agency_gallery_preview( $tabs, $info ) as $t ) {
		if ( 0 === strcasecmp( trim( wp_strip_all_tags( $t['title'] ) ), $title ) ) {
			return new WP_Error( 'bcm_duplicate', 'A tab called "' . $title . '" already exists (tab ' . $t['tab_index'] . ').' );
		}
	}
	$copy_from = isset( $input['copy_from'] ) ? (int) $input['copy_from'] : count( $tabs ) - 1;
	if ( ! isset( $tabs[ $copy_from ] ) ) {
		return new WP_Error( 'bcm_bad_input', 'copy_from must be an existing tab index.' );
	}
	$new        = $tabs[ $copy_from ];
	$new['_id'] = substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 7 );
	$new[ $info['list_key'] ] = $list;
	if ( $info['title_key'] ) {
		$new[ $info['title_key'] ] = $title;
	}
	$position = isset( $input['position'] ) ? (int) $input['position'] : count( $tabs );
	$position = max( 0, min( count( $tabs ), $position ) );
	array_splice( $tabs, $position, 0, array( $new ) );
	$settings[ $field ] = $tabs;
	$after              = $tabs;
	unset( $settings );

	$how    = bcm_agency_save_elementor_data( $post_id, $data );
	$purged = bcm_agency_purge_page_cache();
	return array(
		'id'             => $post_id,
		'element_id'     => (string) $input['element_id'],
		'field'          => $field,
		'added_tab'      => $position,
		'gallery'        => bcm_agency_gallery_preview( $after, $info ),
		'saved_by'       => $how,
		'caches_cleared' => $purged,
		'note'           => "Rename a tab with bcm/replace-text (element_ids), remove one with bcm/remove-list-item. Undo: the page's Revisions.",
		'_log'           => array(
			'element' => (string) $input['element_id'],
			'tab'     => $title,
			'images'  => $ids,
			'index'   => $position,
		),
	);
}

function bcm_agency_register_gallery_abilities() {
	$base = array(
		'post_id'    => array( 'type' => 'integer' ),
		'element_id' => array(
			'type'        => 'string',
			'description' => 'The gallery/carousel widget\'s element id (from bcm/find-images).',
		),
		'field'      => array(
			'type'        => 'string',
			'description' => 'Gallery key (wp_gallery, gallery, galleries, carousel, slides). Only needed if the widget has more than one.',
		),
		'tab'        => array(
			'type'        => array( 'integer', 'string' ),
			'description' => 'Tabbed galleries only: the tab index or its exact title.',
		),
	);

	bcm_agency_register(
		'bcm/add-gallery-image',
		array(
			'label'               => 'Add an image to a gallery or carousel',
			'description'         => 'Adds a Media Library image to an Elementor gallery or carousel (Basic Gallery, Pro Gallery incl. tabbed "multiple galleries" via tab, Image Carousel, Media Carousel), classic widgets included, at any position (default: end). Media Carousel slides copy an existing slide\'s settings. Refuses duplicates. Requires approved: true; revision for undo; caches cleared.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array_merge(
					$base,
					array(
						'attachment_id'   => array( 'type' => 'integer' ),
						'position'        => array(
							'type'        => 'integer',
							'description' => 'Index to insert at (0 = first). Default: end.',
						),
						'copy_from'       => array(
							'type'        => 'integer',
							'description' => 'Slide carousels only: slide index to copy settings from. Default: last.',
						),
						'allow_duplicate' => array( 'type' => 'boolean' ),
						'approved'        => array( 'type' => 'boolean' ),
					)
				),
				'required'             => array( 'post_id', 'element_id', 'attachment_id' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_add_gallery_image',
			'permission_callback' => function () {
				return current_user_can( 'edit_pages' ) && current_user_can( 'edit_others_posts' );
			},
		)
	);

	bcm_agency_register(
		'bcm/remove-gallery-image',
		array(
			'label'               => 'Remove an image from a gallery or carousel',
			'description'         => 'Removes one image from an Elementor gallery or carousel (for tabbed galleries, within a tab) by index or attachment_id (see bcm/find-images). Never removes the last image, and never deletes it from the Media Library. Requires approved: true; revision for undo; caches cleared.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array_merge(
					$base,
					array(
						'index'         => array( 'type' => 'integer' ),
						'attachment_id' => array( 'type' => 'integer' ),
						'approved'      => array( 'type' => 'boolean' ),
					)
				),
				'required'             => array( 'post_id', 'element_id' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_remove_gallery_image',
			'permission_callback' => function () {
				return current_user_can( 'edit_pages' ) && current_user_can( 'edit_others_posts' );
			},
		)
	);

	bcm_agency_register(
		'bcm/add-gallery-tab',
		array(
			'label'               => 'Add a tab to a tabbed gallery',
			'description'         => 'Adds a new tab to an Elementor Pro Gallery in "multiple galleries" (tabbed) mode, with its title and images, copying an existing tab\'s settings. Refuses a duplicate title. Requires approved: true; revision for undo; caches cleared.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array_merge(
					$base,
					array(
						'title'          => array( 'type' => 'string' ),
						'attachment_ids' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'position'       => array( 'type' => 'integer' ),
						'copy_from'      => array( 'type' => 'integer' ),
						'approved'       => array( 'type' => 'boolean' ),
					)
				),
				'required'             => array( 'post_id', 'element_id', 'title', 'attachment_ids' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_add_gallery_tab',
			'permission_callback' => function () {
				return current_user_can( 'edit_pages' ) && current_user_can( 'edit_others_posts' );
			},
		)
	);
}
