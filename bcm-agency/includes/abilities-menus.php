<?php
defined( 'ABSPATH' ) || exit;

/*
 * WordPress navigation menus (Appearance → Menus). Elementor's Nav Menu widget
 * and most themes display these, so changing a menu here changes the site's
 * header / footer navigation. Every write needs approved: true and is logged.
 */

function bcm_agency_menu_item_row( $item ) {
	return array(
		'item_id'   => (int) $item->ID,
		'title'     => $item->title,
		'url'       => $item->url,
		'type'      => $item->type,      // post_type, taxonomy or custom.
		'object'    => $item->object,    // page, post, category, custom…
		'object_id' => (int) $item->object_id,
		'parent'    => (int) $item->menu_item_parent,
		'order'     => (int) $item->menu_order,
		'target'    => $item->target,
	);
}

function bcm_agency_list_menus() {
	$locations = get_nav_menu_locations();
	$registered = get_registered_nav_menus();
	$out = array();
	foreach ( wp_get_nav_menus() as $menu ) {
		$where = array();
		foreach ( $locations as $loc => $menu_id ) {
			if ( (int) $menu_id === (int) $menu->term_id ) {
				$where[] = isset( $registered[ $loc ] ) ? $registered[ $loc ] : $loc;
			}
		}
		$items = wp_get_nav_menu_items( $menu->term_id, array( 'update_post_term_cache' => false ) );
		$out[] = array(
			'menu_id'   => (int) $menu->term_id,
			'name'      => $menu->name,
			'slug'      => $menu->slug,
			'locations' => $where,
			'items'     => $items ? array_map( 'bcm_agency_menu_item_row', $items ) : array(),
		);
	}
	return array(
		'menus' => $out,
		'note'  => 'Menus shown through an Elementor Nav Menu widget may not be assigned to a theme location; the widget names the menu by slug.',
	);
}

function bcm_agency_get_menu_item( $item_id ) {
	$post = get_post( (int) $item_id );
	if ( ! $post || 'nav_menu_item' !== $post->post_type ) {
		return new WP_Error( 'bcm_not_found', 'No menu item with that item_id. Run bcm/list-menus.' );
	}
	$item  = wp_setup_nav_menu_item( $post );
	$terms = wp_get_object_terms( $post->ID, 'nav_menu', array( 'fields' => 'ids' ) );
	$item->bcm_menu_id = $terms && ! is_wp_error( $terms ) ? (int) $terms[0] : 0;
	return $item;
}

/** Full args for wp_update_nav_menu_item from an existing item, so unspecified fields keep their values. */
function bcm_agency_menu_item_args( $item ) {
	return array(
		'menu-item-db-id'       => (int) $item->ID,
		'menu-item-object-id'   => (int) $item->object_id,
		'menu-item-object'      => $item->object,
		'menu-item-parent-id'   => (int) $item->menu_item_parent,
		'menu-item-position'    => (int) $item->menu_order,
		'menu-item-type'        => $item->type,
		'menu-item-title'       => $item->title,
		'menu-item-url'         => $item->url,
		'menu-item-description' => $item->description,
		'menu-item-attr-title'  => $item->attr_title,
		'menu-item-target'      => $item->target,
		'menu-item-classes'     => is_array( $item->classes ) ? implode( ' ', $item->classes ) : '',
		'menu-item-xfn'         => $item->xfn,
		'menu-item-status'      => 'publish',
	);
}

function bcm_agency_clean_menu_url( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return new WP_Error( 'bcm_bad_url', 'url cannot be empty.' );
	}
	if ( '#' === $url[0] || '/' === $url[0] ) {
		return $url;
	}
	$clean = esc_url_raw( $url, array( 'http', 'https', 'tel', 'mailto' ) );
	if ( '' === $clean ) {
		return new WP_Error( 'bcm_bad_url', 'Links must start with https://, /, #, tel: or mailto:.' );
	}
	return $clean;
}

/**
 * Re-numbers a menu so $item_id sits right after $after_item_id (0 = first)
 * among its siblings, keeping everyone else in their current order.
 */
function bcm_agency_reorder_menu( $menu_id, $item_id, $after_item_id ) {
	$items = wp_get_nav_menu_items( $menu_id, array( 'update_post_term_cache' => false ) );
	if ( ! $items ) {
		return;
	}
	usort(
		$items,
		function ( $a, $b ) {
			return (int) $a->menu_order <=> (int) $b->menu_order;
		}
	);
	$moving = null;
	$rest   = array();
	foreach ( $items as $it ) {
		if ( (int) $it->ID === (int) $item_id ) {
			$moving = $it;
		} else {
			$rest[] = $it;
		}
	}
	if ( ! $moving ) {
		return;
	}
	$insert_at = 0;
	if ( $after_item_id ) {
		foreach ( $rest as $i => $it ) {
			if ( (int) $it->ID === (int) $after_item_id ) {
				$insert_at = $i + 1;
				break;
			}
		}
	}
	array_splice( $rest, $insert_at, 0, array( $moving ) );
	foreach ( $rest as $i => $it ) {
		if ( (int) $it->menu_order !== $i + 1 ) {
			wp_update_post(
				array(
					'ID'         => (int) $it->ID,
					'menu_order' => $i + 1,
				)
			);
		}
	}
}

function bcm_agency_add_menu_item( array $input ) {
	if ( empty( $input['approved'] ) ) {
		return new WP_Error( 'bcm_needs_approval', 'Menu changes show on every page right away. Confirm the menu, label, link and position with the person, then call again with approved: true.' );
	}
	$menu_id = isset( $input['menu_id'] ) ? (int) $input['menu_id'] : 0;
	$menu    = $menu_id ? wp_get_nav_menu_object( $menu_id ) : false;
	if ( ! $menu ) {
		return new WP_Error( 'bcm_not_found', 'No menu with that menu_id. Run bcm/list-menus.' );
	}
	$args = array(
		'menu-item-status' => 'publish',
		'menu-item-title'  => isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '',
	);
	if ( ! empty( $input['page_id'] ) ) {
		$page = get_post( (int) $input['page_id'] );
		if ( ! $page || 'publish' !== $page->post_status ) {
			return new WP_Error( 'bcm_not_found', 'page_id must be a published page or post.' );
		}
		$args['menu-item-type']      = 'post_type';
		$args['menu-item-object']    = $page->post_type;
		$args['menu-item-object-id'] = (int) $page->ID;
		if ( '' === $args['menu-item-title'] ) {
			unset( $args['menu-item-title'] ); // Uses the page title, and follows it if renamed.
		}
	} elseif ( ! empty( $input['url'] ) ) {
		$url = bcm_agency_clean_menu_url( $input['url'] );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		if ( '' === $args['menu-item-title'] ) {
			return new WP_Error( 'bcm_bad_input', 'title is required for a custom link.' );
		}
		$args['menu-item-type'] = 'custom';
		$args['menu-item-url']  = $url;
	} else {
		return new WP_Error( 'bcm_bad_input', 'Pass page_id (to link a page) or url (custom link).' );
	}
	$parent = isset( $input['parent_item_id'] ) ? (int) $input['parent_item_id'] : 0;
	if ( $parent ) {
		$p = bcm_agency_get_menu_item( $parent );
		if ( is_wp_error( $p ) || (int) $p->bcm_menu_id !== $menu_id ) {
			return new WP_Error( 'bcm_bad_input', 'parent_item_id must be an item in the same menu.' );
		}
		$args['menu-item-parent-id'] = $parent;
	}
	if ( ! empty( $input['new_tab'] ) ) {
		$args['menu-item-target'] = '_blank';
	}

	$id = wp_update_nav_menu_item( $menu_id, 0, $args );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	$after = isset( $input['after_item_id'] ) ? (int) $input['after_item_id'] : -1;
	if ( $after >= 0 ) {
		bcm_agency_reorder_menu( $menu_id, $id, $after );
	}
	$purged = bcm_agency_purge_page_cache();
	$item   = bcm_agency_get_menu_item( $id );

	return array(
		'id'             => (int) $id,
		'menu'           => $menu->name,
		'item'           => is_wp_error( $item ) ? null : bcm_agency_menu_item_row( $item ),
		'caches_cleared' => $purged,
		'note'           => 'Undo: bcm/remove-menu-item with this item_id, or Appearance → Menus.',
		'_log'           => array(
			'menu'  => $menu->name,
			'title' => is_wp_error( $item ) ? '' : $item->title,
			'url'   => is_wp_error( $item ) ? '' : $item->url,
		),
	);
}

function bcm_agency_update_menu_item( array $input ) {
	if ( empty( $input['approved'] ) ) {
		return new WP_Error( 'bcm_needs_approval', 'Menu changes show on every page right away. Confirm the change with the person, then call again with approved: true.' );
	}
	$item = bcm_agency_get_menu_item( isset( $input['item_id'] ) ? $input['item_id'] : 0 );
	if ( is_wp_error( $item ) ) {
		return $item;
	}
	$before = bcm_agency_menu_item_row( $item );
	$args   = bcm_agency_menu_item_args( $item );
	if ( isset( $input['title'] ) ) {
		$args['menu-item-title'] = sanitize_text_field( $input['title'] );
	}
	if ( isset( $input['url'] ) ) {
		if ( 'custom' !== $item->type ) {
			return new WP_Error( 'bcm_bad_input', 'This item links to a page, so its address follows that page. Remove it and add a custom link instead.' );
		}
		$url = bcm_agency_clean_menu_url( $input['url'] );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		$args['menu-item-url'] = $url;
	}
	if ( isset( $input['parent_item_id'] ) ) {
		$parent = (int) $input['parent_item_id'];
		if ( $parent ) {
			$p = bcm_agency_get_menu_item( $parent );
			if ( is_wp_error( $p ) || (int) $p->bcm_menu_id !== (int) $item->bcm_menu_id || $parent === (int) $item->ID ) {
				return new WP_Error( 'bcm_bad_input', 'parent_item_id must be another item in the same menu (0 for top level).' );
			}
		}
		$args['menu-item-parent-id'] = $parent;
	}
	if ( isset( $input['new_tab'] ) ) {
		$args['menu-item-target'] = $input['new_tab'] ? '_blank' : '';
	}
	$r = wp_update_nav_menu_item( (int) $item->bcm_menu_id, (int) $item->ID, $args );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	if ( isset( $input['after_item_id'] ) ) {
		bcm_agency_reorder_menu( (int) $item->bcm_menu_id, (int) $item->ID, (int) $input['after_item_id'] );
	}
	$purged = bcm_agency_purge_page_cache();
	$after  = bcm_agency_menu_item_row( bcm_agency_get_menu_item( $item->ID ) );
	return array(
		'id'             => (int) $item->ID,
		'before'         => $before,
		'after'          => $after,
		'caches_cleared' => $purged,
		'_log'           => array(
			'before' => array( $before['title'], $before['url'] ),
			'after'  => array( $after['title'], $after['url'] ),
		),
	);
}

function bcm_agency_remove_menu_item( array $input ) {
	if ( empty( $input['approved'] ) ) {
		return new WP_Error( 'bcm_needs_approval', 'Removing a menu item changes navigation on every page. Confirm with the person, then call again with approved: true.' );
	}
	$item = bcm_agency_get_menu_item( isset( $input['item_id'] ) ? $input['item_id'] : 0 );
	if ( is_wp_error( $item ) ) {
		return $item;
	}
	$row = bcm_agency_menu_item_row( $item );
	// Children move up to this item's parent instead of being orphaned.
	$children = get_posts(
		array(
			'post_type'      => 'nav_menu_item',
			'posts_per_page' => -1,
			'meta_key'       => '_menu_item_menu_item_parent', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => (string) $item->ID, // phpcs:ignore WordPress.DB.SlowDBQuery
			'fields'         => 'ids',
		)
	);
	foreach ( $children as $child_id ) {
		update_post_meta( $child_id, '_menu_item_menu_item_parent', (string) (int) $item->menu_item_parent );
	}
	// This deletes the menu link only, never the page it points to.
	$deleted = wp_delete_post( (int) $item->ID, true );
	if ( ! $deleted ) {
		return new WP_Error( 'bcm_failed', 'WordPress did not remove the menu item.' );
	}
	$purged = bcm_agency_purge_page_cache();
	return array(
		'id'               => (int) $item->ID,
		'removed'          => $row,
		'children_moved'   => count( $children ),
		'caches_cleared'   => $purged,
		'note'             => 'Only the menu link was removed; the page it pointed to is untouched. To restore it, add it again with bcm/add-menu-item.',
		'_log'             => array(
			'removed' => array( $row['title'], $row['url'] ),
		),
	);
}

function bcm_agency_register_menu_abilities() {
	$can = function () {
		return current_user_can( 'edit_theme_options' );
	};

	bcm_agency_register(
		'bcm/list-menus',
		array(
			'label'               => 'List navigation menus',
			'description'         => 'Read-only. Lists the site\'s WordPress menus (Appearance → Menus) with where they display and every item: item_id, title, URL, what it links to, parent and order.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => new stdClass(),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_list_menus',
			'permission_callback' => $can,
		),
		true
	);

	bcm_agency_register(
		'bcm/add-menu-item',
		array(
			'label'               => 'Add a menu item',
			'description'         => 'Adds a link to a WordPress menu: a page (page_id, follows the page if renamed) or a custom URL (url + title). Optional parent_item_id makes it a dropdown child; after_item_id places it right after that item (0 = first). Requires approved: true; caches cleared.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'menu_id'        => array( 'type' => 'integer' ),
					'page_id'        => array( 'type' => 'integer' ),
					'url'            => array( 'type' => 'string' ),
					'title'          => array(
						'type'        => 'string',
						'description' => 'Menu label. Optional for pages (defaults to the page title); required for custom links.',
					),
					'parent_item_id' => array( 'type' => 'integer' ),
					'after_item_id'  => array( 'type' => 'integer' ),
					'new_tab'        => array( 'type' => 'boolean' ),
					'approved'       => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'menu_id' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_add_menu_item',
			'permission_callback' => $can,
		)
	);

	bcm_agency_register(
		'bcm/update-menu-item',
		array(
			'label'               => 'Change a menu item',
			'description'         => 'Renames a menu item, changes a custom link\'s URL, moves it under another item (parent_item_id, 0 = top level) or to a new position (after_item_id), or toggles new_tab. Requires approved: true; caches cleared.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'item_id'        => array( 'type' => 'integer' ),
					'title'          => array( 'type' => 'string' ),
					'url'            => array( 'type' => 'string' ),
					'parent_item_id' => array( 'type' => 'integer' ),
					'after_item_id'  => array( 'type' => 'integer' ),
					'new_tab'        => array( 'type' => 'boolean' ),
					'approved'       => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'item_id' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_update_menu_item',
			'permission_callback' => $can,
		)
	);

	bcm_agency_register(
		'bcm/remove-menu-item',
		array(
			'label'               => 'Remove a menu item',
			'description'         => 'Removes one link from a menu (never the page it points to). Its dropdown children move up a level instead of disappearing. Requires approved: true; caches cleared.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'item_id'  => array( 'type' => 'integer' ),
					'approved' => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'item_id' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_remove_menu_item',
			'permission_callback' => $can,
		)
	);
}
