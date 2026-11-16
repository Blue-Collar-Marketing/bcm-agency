<?php
/**
 * HTML embeds: places (or updates) an Elementor HTML widget holding a form
 * embed, a calculator or similar code.
 *
 * Through the BCM hub this always needs Chris's second confirmation, which
 * shows him the exact code (checked here as well as by the hub gate). Outside
 * the hub it needs an administrator with WordPress's unfiltered_html
 * capability, the same rule Elementor applies.
 * The code is written straight to the page's Elementor data so it is stored
 * exactly as approved.
 *
 * @package BCM_Agency
 */

defined( 'ABSPATH' ) || exit;

const BCM_AGENCY_EMBED_MAX_BYTES = 102400;

/** Path (list of indexes) to an element in an Elementor tree, or null. */
function bcm_agency_element_path( array $elements, $id, array $path = array() ) {
	foreach ( $elements as $i => $el ) {
		if ( ! is_array( $el ) ) {
			continue;
		}
		if ( isset( $el['id'] ) && (string) $el['id'] === (string) $id ) {
			return array_merge( $path, array( $i ) );
		}
		if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
			$found = bcm_agency_element_path( $el['elements'], $id, array_merge( $path, array( $i ) ) );
			if ( null !== $found ) {
				return $found;
			}
		}
	}
	return null;
}

/** Every element id in a tree. */
function bcm_agency_element_ids( array $elements, array &$ids ) {
	foreach ( $elements as $el ) {
		if ( is_array( $el ) && isset( $el['id'] ) ) {
			$ids[ (string) $el['id'] ] = true;
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				bcm_agency_element_ids( $el['elements'], $ids );
			}
		}
	}
}

/** A new Elementor-style element id (7 hex characters) not used in the tree. */
function bcm_agency_new_element_id( array $data ) {
	$ids = array();
	bcm_agency_element_ids( $data, $ids );
	do {
		$id = substr( md5( uniqid( '', true ) . wp_rand() ), 0, 7 );
	} while ( isset( $ids[ $id ] ) );
	return $id;
}

function bcm_agency_embed_allowed() {
	if ( class_exists( 'BCM_Hub' ) && BCM_Hub::running() ) {
		return BCM_Hub::running_confirmed(); // Through the hub: only with Chris's approval of this exact code.
	}
	return current_user_can( 'unfiltered_html' );
}

function bcm_agency_add_html_embed( array $input ) {
	if ( ! bcm_agency_embed_allowed() ) {
		return new WP_Error( 'bcm_forbidden', 'Adding code needs an administrator who may post unfiltered HTML, or Chris\'s approval through the BCM hub.' );
	}
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$post    = get_post( $post_id );
	if ( ! $post || 'trash' === $post->post_status || ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'bcm_not_found', 'No page you can edit with that ID.' );
	}
	if ( ! bcm_agency_is_elementor_post( $post_id ) ) {
		return new WP_Error( 'bcm_not_elementor', 'This page is not built with Elementor, so it has no HTML widget to hold the code.' );
	}
	$html = isset( $input['html'] ) && is_string( $input['html'] ) ? $input['html'] : '';
	if ( '' === trim( $html ) ) {
		return new WP_Error( 'bcm_bad_input', 'html is required: the embed code or calculator markup.' );
	}
	if ( strlen( $html ) > BCM_AGENCY_EMBED_MAX_BYTES ) {
		return new WP_Error( 'bcm_bad_input', 'The code is larger than 100 KB. Host large tools elsewhere and embed them, or ask a developer.' );
	}
	if ( preg_match( '/<\?(php|=)?/i', $html ) ) {
		return new WP_Error( 'bcm_bad_input', 'PHP code does not run in an HTML widget. Use HTML, CSS and JavaScript only.' );
	}
	$mode = isset( $input['mode'] ) ? (string) $input['mode'] : 'add';
	if ( ! in_array( $mode, array( 'add', 'update' ), true ) ) {
		return new WP_Error( 'bcm_bad_input', 'mode must be add or update.' );
	}

	$data = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
	$data = is_array( $data ) ? $data : array();
	$label = isset( $input['label'] ) ? trim( sanitize_text_field( $input['label'] ) ) : '';

	if ( 'update' === $mode ) {
		$id   = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
		$path = '' !== $id ? bcm_agency_element_path( $data, $id ) : null;
		if ( null === $path ) {
			return new WP_Error( 'bcm_not_found', 'No element with that element_id on this page (find it with elementor/get-page-structure).' );
		}
		$node = &$data;
		foreach ( $path as $k => $i ) {
			$node = &$node[ $i ];
			if ( $k < count( $path ) - 1 ) {
				$node = &$node['elements'];
			}
		}
		if ( ! isset( $node['widgetType'] ) || 'html' !== $node['widgetType'] ) {
			unset( $node );
			return new WP_Error( 'bcm_bad_input', 'That element is not an HTML widget; only HTML widgets can be updated here.' );
		}
		$before = isset( $node['settings']['html'] ) ? (string) $node['settings']['html'] : '';
		if ( $before === $html ) {
			unset( $node );
			return new WP_Error( 'bcm_no_change', 'The widget already holds exactly this code.' );
		}
		$node['settings']['html'] = $html;
		if ( '' !== $label ) {
			$node['settings']['_title'] = $label;
		}
		unset( $node );
		$widget_id = $id;
		$where     = 'updated';
	} else {
		$widget_id = bcm_agency_new_element_id( $data );
		$widget    = array(
			'id'         => $widget_id,
			'elType'     => 'widget',
			'widgetType' => 'html',
			'settings'   => array_merge( array( 'html' => $html ), '' !== $label ? array( '_title' => $label ) : array() ),
			'elements'   => array(),
		);
		$parent_id = isset( $input['parent_id'] ) ? (string) $input['parent_id'] : '';
		$after_id  = isset( $input['after_id'] ) ? (string) $input['after_id'] : '';

		if ( '' !== $parent_id ) {
			$path = bcm_agency_element_path( $data, $parent_id );
			if ( null === $path ) {
				return new WP_Error( 'bcm_not_found', 'No element with that parent_id on this page.' );
			}
			$node = &$data;
			foreach ( $path as $k => $i ) {
				$node = &$node[ $i ];
				if ( $k < count( $path ) - 1 ) {
					$node = &$node['elements'];
				}
			}
			$type = isset( $node['elType'] ) ? (string) $node['elType'] : '';
			if ( ! in_array( $type, array( 'container', 'column' ), true ) ) {
				unset( $node );
				return new WP_Error( 'bcm_bad_input', 'parent_id must be a classic Elementor container or column. On Atomic (V4) pages, leave parent_id out and use after_id to place the embed in its own section.' );
			}
			$children = isset( $node['elements'] ) && is_array( $node['elements'] ) ? array_values( $node['elements'] ) : array();
			$slot     = count( $children );
			if ( '' !== $after_id ) {
				$slot = null;
				foreach ( $children as $i => $child ) {
					if ( isset( $child['id'] ) && (string) $child['id'] === $after_id ) {
						$slot = $i + 1;
					}
				}
				if ( null === $slot ) {
					unset( $node );
					return new WP_Error( 'bcm_bad_input', 'after_id must be a direct child of parent_id.' );
				}
			}
			array_splice( $children, $slot, 0, array( $widget ) );
			$node['elements'] = $children;
			unset( $node );
			$where = 'inside ' . $parent_id;
		} else {
			$section = array(
				'id'       => bcm_agency_new_element_id( array_merge( $data, array( $widget ) ) ),
				'elType'   => 'container',
				'isInner'  => false,
				'settings' => array( 'content_width' => 'boxed' ),
				'elements' => array( $widget ),
			);
			$data = array_values( $data );
			$slot = count( $data );
			if ( '' !== $after_id ) {
				$slot = null;
				foreach ( $data as $i => $el ) {
					if ( isset( $el['id'] ) && (string) $el['id'] === $after_id ) {
						$slot = $i + 1;
					}
				}
				if ( null === $slot ) {
					return new WP_Error( 'bcm_bad_input', 'Without parent_id, after_id must be a top-level section of the page.' );
				}
			}
			array_splice( $data, $slot, 0, array( $section ) );
			$where = '' !== $after_id ? 'new section after ' . $after_id : 'new section at the end of the page';
		}
	}

	if ( empty( $input['approved'] ) ) {
		return new WP_Error( 'bcm_needs_approval', 'This places code on "' . get_the_title( $post ) . '" (' . $where . '). Show the person the exact code, then call again with approved: true.' );
	}

	update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
	delete_post_meta( $post_id, '_elementor_css' );
	if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}
	return array(
		'id'         => $post_id,
		'element_id' => $widget_id,
		'mode'       => $mode,
		'where'      => $where,
		'live'       => 'publish' === $post->post_status,
		'url'        => get_permalink( $post ),
		'cleared'    => bcm_agency_purge_page_cache(),
		'note'       => 'Open the page and test the embed (submit a test entry or try the calculator). To change it later, call again with mode update and this element_id.',
		'_log'       => array(
			'action'     => 'html embed ' . $mode,
			'element_id' => $widget_id,
			'bytes'      => strlen( $html ),
			'sha256'     => hash( 'sha256', $html ),
		),
	);
}

function bcm_agency_register_embed_abilities() {
	bcm_agency_register(
		'bcm/add-html-embed',
		array(
			'label'               => 'Add or update an HTML embed (Elementor HTML widget)',
			'description'         => 'Places a form embed, calculator or similar HTML/CSS/JavaScript on an Elementor page in an Elementor HTML widget, or replaces the code in an existing HTML widget (mode update with element_id). mode add: parent_id = a classic container or column to place it in (after_id = a child to place it after), or leave parent_id out to add it in its own new section after the top-level section after_id (or at the end). Up to 100 KB; no PHP. Through the BCM hub this always needs Chris\'s second confirmation showing the exact code; show the person the code first and pass approved: true.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'    => array( 'type' => 'integer' ),
					'html'       => array( 'type' => 'string' ),
					'mode'       => array(
						'type' => 'string',
						'enum' => array( 'add', 'update' ),
					),
					'parent_id'  => array( 'type' => 'string' ),
					'after_id'   => array( 'type' => 'string' ),
					'element_id' => array( 'type' => 'string' ),
					'label'      => array( 'type' => 'string' ),
					'approved'   => array(
						'type'        => 'boolean',
						'description' => 'Set true only after the person approved this exact code and placement.',
					),
				),
				'required'             => array( 'post_id', 'html' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_add_html_embed',
			'permission_callback' => function () {
				return current_user_can( 'edit_pages' ) && current_user_can( 'edit_others_posts' );
			},
		)
	);
}
