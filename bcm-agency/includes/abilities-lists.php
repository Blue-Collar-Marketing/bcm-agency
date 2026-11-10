<?php
defined( 'ABSPATH' ) || exit;

/*
 * Add or remove items in repeating lists inside Elementor widgets: Icon List
 * bullets, Toggle / Accordion / FAQ items, Price List rows, testimonial slides,
 * tabs and so on. In Elementor's data these are "repeater" fields: an array of
 * items that each carry a unique "_id".
 *
 * New items are copies of an existing item, so they keep exactly the same icon,
 * styling and structure; only the text fields (and optionally a link URL) are
 * set. Works on classic widgets, which Elementor's own tools treat as read-only.
 */

/** Repeater fields on a settings array: key => items. */
function bcm_agency_repeaters( array $settings ) {
	$out = array();
	foreach ( $settings as $key => $value ) {
		if ( ! is_array( $value ) || ! $value || array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			continue;
		}
		$all_items = true;
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['_id'] ) ) {
				$all_items = false;
				break;
			}
		}
		if ( $all_items ) {
			$out[ $key ] = $value;
		}
	}
	return $out;
}

/** Text fields an item can have set: string values, plus "link" when it's a {url} object. */
function bcm_agency_repeater_text_fields( array $items ) {
	$fields = array();
	foreach ( $items as $item ) {
		foreach ( $item as $k => $v ) {
			if ( '_id' === $k || '' === $k || '_' === $k[0] ) {
				continue;
			}
			if ( is_string( $v ) ) {
				$fields[ $k ] = 'text';
			} elseif ( is_array( $v ) && array_key_exists( 'url', $v ) ) {
				$fields[ $k ] = 'link';
			}
		}
	}
	return $fields;
}

function bcm_agency_repeater_preview( array $items, array $fields ) {
	$out = array();
	foreach ( array_values( $items ) as $i => $item ) {
		$row = array(
			'index' => $i,
			'_id'   => (string) $item['_id'],
		);
		foreach ( $fields as $k => $kind ) {
			if ( ! isset( $item[ $k ] ) ) {
				continue;
			}
			if ( 'link' === $kind ) {
				$row[ $k ] = is_array( $item[ $k ] ) && isset( $item[ $k ]['url'] ) ? (string) $item[ $k ]['url'] : '';
			} else {
				$text      = trim( wp_strip_all_tags( (string) $item[ $k ] ) );
				$row[ $k ] = strlen( $text ) > 160 ? substr( $text, 0, 157 ) . '…' : $text;
			}
		}
		$out[] = $row;
	}
	return $out;
}

/** Loads the page data and a reference to the widget's settings. Returns [ data, error ]. */
function bcm_agency_load_widget( $post_id, $element_id ) {
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return array( null, new WP_Error( 'bcm_forbidden', 'You cannot edit this page.' ) );
	}
	$data = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
	if ( ! is_array( $data ) ) {
		return array( null, new WP_Error( 'bcm_not_found', 'No Elementor data on that page.' ) );
	}
	return array( $data, null );
}

function bcm_agency_pick_repeater( array $settings, $field ) {
	$reps = bcm_agency_repeaters( $settings );
	if ( ! $reps ) {
		return new WP_Error( 'bcm_no_list', 'That element has no list items to manage.' );
	}
	if ( '' !== $field ) {
		if ( ! isset( $reps[ $field ] ) ) {
			return new WP_Error( 'bcm_bad_field', 'No list called "' . $field . '" on that element. Lists here: ' . implode( ', ', array_keys( $reps ) ) . '.' );
		}
		return $field;
	}
	if ( 1 !== count( $reps ) ) {
		return new WP_Error( 'bcm_ambiguous', 'That element has several lists (' . implode( ', ', array_keys( $reps ) ) . '). Pass field.' );
	}
	return (string) key( $reps );
}

function bcm_agency_list_items( array $input ) {
	$post_id    = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$element_id = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
	list( $data, $err ) = bcm_agency_load_widget( $post_id, $element_id );
	if ( $err ) {
		return $err;
	}
	$settings = &bcm_agency_find_element_settings( $data, $element_id );
	if ( null === $settings ) {
		return new WP_Error( 'bcm_not_found', 'No element with that element_id on that page.' );
	}
	$lists = array();
	foreach ( bcm_agency_repeaters( $settings ) as $key => $items ) {
		$fields        = bcm_agency_repeater_text_fields( $items );
		$lists[ $key ] = array(
			'count'           => count( $items ),
			'editable_fields' => $fields,
			'items'           => bcm_agency_repeater_preview( $items, $fields ),
		);
	}
	return array(
		'post_id'    => $post_id,
		'element_id' => $element_id,
		'lists'      => $lists,
		'note'       => $lists ? 'Use bcm/add-list-item / bcm/remove-list-item with the list key as field.' : 'No list items on this element.',
	);
}

function bcm_agency_add_list_item( array $input ) {
	if ( empty( $input['approved'] ) ) {
		return new WP_Error( 'bcm_needs_approval', 'Adding a list item changes a live page. Show the person the new item and where it goes, then call again with approved: true.' );
	}
	$post_id    = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$element_id = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
	$values     = isset( $input['values'] ) && is_array( $input['values'] ) ? $input['values'] : array();
	if ( ! $values ) {
		return new WP_Error( 'bcm_bad_input', 'values is required, e.g. {"text": "Houston, USA"}. Check bcm/list-items for the editable fields.' );
	}
	list( $data, $err ) = bcm_agency_load_widget( $post_id, $element_id );
	if ( $err ) {
		return $err;
	}
	$settings = &bcm_agency_find_element_settings( $data, $element_id );
	if ( null === $settings ) {
		return new WP_Error( 'bcm_not_found', 'No element with that element_id on that page.' );
	}
	$field = bcm_agency_pick_repeater( $settings, isset( $input['field'] ) ? (string) $input['field'] : '' );
	if ( is_wp_error( $field ) ) {
		return $field;
	}
	$items  = array_values( $settings[ $field ] );
	$fields = bcm_agency_repeater_text_fields( $items );

	$copy_from = isset( $input['copy_from'] ) ? (int) $input['copy_from'] : count( $items ) - 1;
	if ( ! isset( $items[ $copy_from ] ) ) {
		return new WP_Error( 'bcm_bad_input', 'copy_from must be an existing item index (0 to ' . ( count( $items ) - 1 ) . ').' );
	}
	$new        = $items[ $copy_from ];
	$new['_id'] = substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 7 );

	foreach ( $values as $k => $v ) {
		if ( ! isset( $fields[ $k ] ) ) {
			return new WP_Error( 'bcm_bad_field', 'Cannot set "' . $k . '". Editable fields for this list: ' . implode( ', ', array_keys( $fields ) ) . '.' );
		}
		if ( 'link' === $fields[ $k ] ) {
			$url = trim( (string) $v );
			if ( '' !== $url && ! preg_match( '#^(https?://|/|\#|tel:|mailto:)#i', $url ) ) {
				return new WP_Error( 'bcm_bad_link', 'Links must start with https://, /, #, tel: or mailto:.' );
			}
			$new[ $k ]        = isset( $new[ $k ] ) && is_array( $new[ $k ] ) ? $new[ $k ] : array();
			$new[ $k ]['url'] = '' === $url ? '' : esc_url_raw( $url, array( 'http', 'https', 'tel', 'mailto' ) );
			if ( '' === $new[ $k ]['url'] && '' !== $url && ( '/' === $url[0] || '#' === $url[0] ) ) {
				$new[ $k ]['url'] = $url; // Relative and anchor links.
			}
		} else {
			$new[ $k ] = wp_kses_post( (string) $v );
		}
	}
	// A copied item keeps the source's link unless one was given: clear it so the new item doesn't point at the old page.
	foreach ( $fields as $k => $kind ) {
		if ( 'link' === $kind && ! array_key_exists( $k, $values ) && isset( $new[ $k ]['url'] ) && '' !== $new[ $k ]['url'] ) {
			$new[ $k ]['url'] = '';
		}
	}

	$position = isset( $input['position'] ) ? (int) $input['position'] : count( $items );
	$position = max( 0, min( count( $items ), $position ) );
	array_splice( $items, $position, 0, array( $new ) );
	$settings[ $field ] = $items;
	$after_items        = $items;
	unset( $settings );

	$how    = bcm_agency_save_elementor_data( $post_id, $data );
	$purged = bcm_agency_purge_page_cache();

	return array(
		'id'             => $post_id,
		'element_id'     => $element_id,
		'field'          => $field,
		'added_at_index' => $position,
		'new_item_id'    => $new['_id'],
		'items'          => bcm_agency_repeater_preview( $after_items, $fields ),
		'saved_by'       => $how,
		'caches_cleared' => $purged,
		'note'           => 'The new item copies item ' . $copy_from . "'s icon and styling. Undo: the page's Revisions (Elementor: History → Revisions).",
		'_log'           => array(
			'element' => $element_id,
			'field'   => $field,
			'added'   => $values,
			'index'   => $position,
		),
	);
}

function bcm_agency_remove_list_item( array $input ) {
	if ( empty( $input['approved'] ) ) {
		return new WP_Error( 'bcm_needs_approval', 'Removing a list item changes a live page. Show the person which item goes, then call again with approved: true.' );
	}
	$post_id    = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$element_id = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
	$item_id    = isset( $input['item_id'] ) ? (string) $input['item_id'] : '';
	if ( '' === $item_id ) {
		return new WP_Error( 'bcm_bad_input', 'item_id (the item\'s _id from bcm/list-items) is required.' );
	}
	list( $data, $err ) = bcm_agency_load_widget( $post_id, $element_id );
	if ( $err ) {
		return $err;
	}
	$settings = &bcm_agency_find_element_settings( $data, $element_id );
	if ( null === $settings ) {
		return new WP_Error( 'bcm_not_found', 'No element with that element_id on that page.' );
	}
	$field = bcm_agency_pick_repeater( $settings, isset( $input['field'] ) ? (string) $input['field'] : '' );
	if ( is_wp_error( $field ) ) {
		return $field;
	}
	$items  = array_values( $settings[ $field ] );
	$fields = bcm_agency_repeater_text_fields( $items );
	$found  = null;
	foreach ( $items as $i => $item ) {
		if ( (string) $item['_id'] === $item_id ) {
			$found = $i;
			break;
		}
	}
	if ( null === $found ) {
		return new WP_Error( 'bcm_not_found', 'No item with that item_id in this list. Run bcm/list-items again.' );
	}
	if ( 1 === count( $items ) ) {
		return new WP_Error( 'bcm_last_item', 'That is the only item in the list. Removing the whole widget is done in the Elementor editor.' );
	}
	$removed = bcm_agency_repeater_preview( array( $items[ $found ] ), $fields )[0];
	array_splice( $items, $found, 1 );
	$settings[ $field ] = $items;
	unset( $settings );

	$how    = bcm_agency_save_elementor_data( $post_id, $data );
	$purged = bcm_agency_purge_page_cache();

	return array(
		'id'             => $post_id,
		'element_id'     => $element_id,
		'field'          => $field,
		'removed'        => $removed,
		'items'          => bcm_agency_repeater_preview( $items, $fields ),
		'saved_by'       => $how,
		'caches_cleared' => $purged,
		'note'           => "Undo: the page's Revisions (Elementor: History → Revisions).",
		'_log'           => array(
			'element' => $element_id,
			'field'   => $field,
			'removed' => $removed,
		),
	);
}

function bcm_agency_register_list_abilities() {
	$target = array(
		'post_id'    => array( 'type' => 'integer' ),
		'element_id' => array(
			'type'        => 'string',
			'description' => 'The widget\'s Elementor element id (from bcm/find-text, bcm/find-images or elementor/get-page-structure).',
		),
		'field'      => array(
			'type'        => 'string',
			'description' => 'The list key (e.g. icon_list, tabs). Only needed when the widget has more than one list.',
		),
	);

	bcm_agency_register(
		'bcm/list-items',
		array(
			'label'               => 'Show the items in a widget\'s list',
			'description'         => 'Read-only. For one Elementor widget, shows its repeating lists (Icon List bullets, Toggle/Accordion/FAQ items, Price List rows, tabs, slides) with each item\'s index, _id and text, plus which fields can be set on new items. Works on classic widgets.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'    => $target['post_id'],
					'element_id' => $target['element_id'],
				),
				'required'             => array( 'post_id', 'element_id' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_list_items',
			'permission_callback' => function () {
				return current_user_can( 'edit_pages' );
			},
		),
		true
	);

	bcm_agency_register(
		'bcm/add-list-item',
		array(
			'label'               => 'Add an item to a widget\'s list',
			'description'         => 'Adds a bullet / FAQ item / row to a repeating list in an Elementor widget (classic widgets included) by copying an existing item, so the icon and styling match exactly, then setting its text (and optionally link). Insert at any position (default: end). Requires approved: true; revision for undo; caches cleared.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array_merge(
					$target,
					array(
						'values'    => array(
							'type'        => 'object',
							'description' => 'Field → value for the new item, e.g. {"text": "Houston, USA"} for an Icon List, {"tab_title": "...", "tab_content": "..."} for a Toggle. Link fields take a URL string.',
						),
						'position'  => array(
							'type'        => 'integer',
							'description' => 'Index to insert at (0 = first). Default: end of the list.',
						),
						'copy_from' => array(
							'type'        => 'integer',
							'description' => 'Index of the item to copy icon/styling from. Default: the last item.',
						),
						'approved'  => array(
							'type'        => 'boolean',
							'description' => 'Set true only after the person approved the new item and its position.',
						),
					)
				),
				'required'             => array( 'post_id', 'element_id', 'values' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_add_list_item',
			'permission_callback' => function () {
				return current_user_can( 'edit_pages' ) && current_user_can( 'edit_others_posts' );
			},
		)
	);

	bcm_agency_register(
		'bcm/remove-list-item',
		array(
			'label'               => 'Remove an item from a widget\'s list',
			'description'         => 'Removes one bullet / FAQ item / row (by its _id from bcm/list-items) from a repeating list in an Elementor widget. Will not remove the last remaining item. Requires approved: true; revision for undo; caches cleared.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array_merge(
					$target,
					array(
						'item_id'  => array( 'type' => 'string' ),
						'approved' => array(
							'type'        => 'boolean',
							'description' => 'Set true only after the person approved removing this item.',
						),
					)
				),
				'required'             => array( 'post_id', 'element_id', 'item_id' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_remove_list_item',
			'permission_callback' => function () {
				return current_user_can( 'edit_pages' ) && current_user_can( 'edit_others_posts' );
			},
		)
	);
}
