<?php
defined( 'ABSPATH' ) || exit;

/*
 * Find and replace text across a site's content.
 *
 * Covers every Elementor generation the same way: classic sections/columns,
 * flexbox containers and Atomic (V4) elements all store their layout as a JSON
 * tree in the _elementor_data post meta. We walk that tree and only touch
 * string VALUES, never keys, element ids, widget types or styling identifiers.
 * Headers, footers, popups and global widgets (elementor_library) are included.
 * Non-Elementor posts and pages are searched in post_content.
 *
 * Phone mode matches the same digits in any common format
 * (613-299-2053, (613) 299-2053, 613.299.2053, +1 613 299 2053, tel:6132992053)
 * and writes the new number back in the style each spot used.
 */

/** Setting keys that are structure or styling identifiers, never visible text. */
function bcm_agency_replace_skip_keys() {
	return array( 'id', 'elType', 'widgetType', '$$type', '_element_id', '_css_classes', 'css_classes', 'classes', 'custom_css', '_custom_css', 'isInner', 'isLocked', 'templateID' );
}

/**
 * Builds the matcher. Returns array( 'mode' => 'text'|'phone', 'regex' => ..., 'digits' => ..., 'prefilter' => ... ).
 */
function bcm_agency_build_matcher( $find, $phone_mode ) {
	$find = (string) $find;
	if ( $phone_mode ) {
		$digits = preg_replace( '/\D+/', '', $find );
		if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
			$digits = substr( $digits, 1 );
		}
		if ( strlen( $digits ) < 7 || strlen( $digits ) > 15 ) {
			return new WP_Error( 'bcm_bad_phone', 'Phone mode needs a phone number with 7 to 15 digits.' );
		}
		// Each digit may be separated by spaces, dots, dashes or parentheses; optional +1 / 1 prefix.
		$parts = array_map(
			function ( $d ) {
				return preg_quote( $d, '/' );
			},
			str_split( $digits )
		);
		$body  = implode( '[\s\.\-\(\)\x{00A0}]*', $parts );
		$regex = '/(?<![\d])(?:\+?1[\s\.\-]*)?\(?' . $body . '(?![\d])/u';
		return array(
			'mode'      => 'phone',
			'regex'     => $regex,
			'digits'    => $digits,
			'prefilter' => substr( $digits, -4 ), // Cheap database pre-filter; exact matching happens in PHP.
		);
	}
	if ( mb_strlen( $find ) < 3 ) {
		return new WP_Error( 'bcm_too_short', 'Search text must be at least 3 characters.' );
	}
	// Database pre-filter: the longest plain run of letters/digits/spaces (JSON escapes punctuation and accents).
	preg_match_all( '/[A-Za-z0-9 ]{3,}/', $find, $runs );
	$prefilter = '';
	foreach ( $runs[0] as $r ) {
		if ( strlen( trim( $r ) ) > strlen( $prefilter ) ) {
			$prefilter = trim( $r );
		}
	}
	return array(
		'mode'      => 'text',
		'regex'     => '/' . preg_quote( $find, '/' ) . '/u',
		'digits'    => '',
		'prefilter' => $prefilter,
	);
}

/**
 * Formats the replacement for one match. In phone mode a digits-only match
 * (e.g. inside tel: links) gets digits only, keeping a +1 / 1 prefix if it had one.
 */
function bcm_agency_format_replacement( $matched, $replace, $matcher ) {
	if ( 'phone' !== $matcher['mode'] ) {
		return $replace;
	}
	$new_digits = preg_replace( '/\D+/', '', $replace );
	if ( 11 === strlen( $new_digits ) && '1' === $new_digits[0] ) {
		$new_digits = substr( $new_digits, 1 );
	}
	if ( preg_match( '/^\+?1?\d+$/', $matched ) ) {
		if ( 0 === strpos( $matched, '+1' ) ) {
			return '+1' . $new_digits;
		}
		if ( strlen( $matched ) === strlen( $matcher['digits'] ) + 1 && '1' === $matched[0] ) {
			return '1' . $new_digits;
		}
		return $new_digits;
	}
	return $replace;
}

/**
 * Walks an Elementor element tree. Collects matches, and if $replace is not null,
 * replaces them in place. Returns number of matches.
 */
function bcm_agency_walk_elements( array &$elements, $matcher, $replace, array &$found, $path = '', $only = null, $inside = false ) {
	$count = 0;
	foreach ( $elements as $i => &$el ) {
		if ( ! is_array( $el ) ) {
			continue;
		}
		$label = isset( $el['widgetType'] ) ? $el['widgetType'] : ( isset( $el['elType'] ) ? $el['elType'] : 'element' );
		$el_id = isset( $el['id'] ) ? (string) $el['id'] : '';
		// With element_ids, only the listed elements (and everything inside them) are searched or changed.
		$in_scope = $inside || null === $only || in_array( $el_id, $only, true );
		if ( $in_scope && isset( $el['settings'] ) && is_array( $el['settings'] ) ) {
			$count += bcm_agency_walk_values( $el['settings'], $matcher, $replace, $found, $el_id, $label, 'settings' );
		}
		if ( isset( $el['elements'] ) && is_array( $el['elements'] ) ) {
			$count += bcm_agency_walk_elements( $el['elements'], $matcher, $replace, $found, $path . '/' . $el_id, $only, $in_scope );
		}
	}
	unset( $el );
	return $count;
}

/** Normalised element_ids input, or null for "whole page". */
function bcm_agency_only_ids( array $input ) {
	if ( empty( $input['element_ids'] ) ) {
		return null;
	}
	$ids = array_values( array_filter( array_map( 'strval', (array) $input['element_ids'] ) ) );
	return $ids ? $ids : null;
}

function bcm_agency_walk_values( array &$node, $matcher, $replace, array &$found, $el_id, $widget, $keypath ) {
	$count = 0;
	$skip  = bcm_agency_replace_skip_keys();
	foreach ( $node as $key => &$value ) {
		if ( in_array( (string) $key, $skip, true ) ) {
			continue;
		}
		$here = $keypath . '.' . $key;
		if ( is_array( $value ) ) {
			$count += bcm_agency_walk_values( $value, $matcher, $replace, $found, $el_id, $widget, $here );
		} elseif ( is_string( $value ) && '' !== $value ) {
			if ( preg_match_all( $matcher['regex'], $value, $m, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $m[0] as $hit ) {
					$found[] = array(
						'element_id' => $el_id,
						'widget'     => $widget,
						'field'      => preg_replace( '/^settings\./', '', $here ),
						'matched'    => $hit[0],
						'context'    => bcm_agency_context( $value, $hit[1], strlen( $hit[0] ) ),
					);
					++$count;
				}
				if ( null !== $replace ) {
					$value = preg_replace_callback(
						$matcher['regex'],
						function ( $mm ) use ( $replace, $matcher ) {
							return bcm_agency_format_replacement( $mm[0], $replace, $matcher );
						},
						$value
					);
				}
			}
		}
	}
	unset( $value );
	return $count;
}

/** ~50 characters either side of a match, tags stripped, for previews. */
function bcm_agency_context( $text, $offset, $length ) {
	$start  = max( 0, $offset - 60 );
	$before = substr( $text, $start, $offset - $start );
	$after  = substr( $text, $offset + $length, 60 );
	$clean  = function ( $s ) {
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $s ) ) );
	};
	return ( $start > 0 ? '…' : '' ) . $clean( $before ) . ' [[' . $clean( substr( $text, $offset, $length ) ) . ']] ' . $clean( $after ) . '…';
}

function bcm_agency_replace_statuses() {
	return array( 'publish', 'future', 'draft', 'pending', 'private' );
}

/**
 * Candidate posts: Elementor documents whose data may contain the text, plus
 * non-Elementor posts/pages whose content may contain it.
 */
function bcm_agency_replace_candidates( $matcher, array $only_ids = array() ) {
	global $wpdb;
	$statuses = bcm_agency_replace_statuses();
	$in_stat  = "'" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "'";
	$like     = '%' . $wpdb->esc_like( $matcher['prefilter'] ) . '%';
	$id_sql   = '';
	if ( $only_ids ) {
		$id_sql = ' AND p.ID IN (' . implode( ',', array_map( 'intval', $only_ids ) ) . ')';
	}
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$elementor = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			 WHERE m.meta_key = '_elementor_data' AND m.meta_value LIKE %s
			 AND p.post_type NOT IN ('revision','nav_menu_item') AND p.post_status IN ($in_stat) $id_sql",
			$like
		)
	);
	$classic = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_elementor_edit_mode' AND e.meta_value = 'builder'
			 WHERE e.post_id IS NULL AND p.post_content LIKE %s
			 AND p.post_type NOT IN ('revision','nav_menu_item','attachment','wpcode','elementor_library') AND p.post_status IN ($in_stat) $id_sql",
			$like
		)
	);
	// phpcs:enable
	return array(
		'elementor' => array_map( 'intval', $elementor ),
		'classic'   => array_map( 'intval', $classic ),
	);
}

function bcm_agency_post_label( WP_Post $p ) {
	$type = $p->post_type;
	if ( 'elementor_library' === $type ) {
		$tpl  = get_post_meta( $p->ID, '_elementor_template_type', true );
		$type = 'elementor template' . ( $tpl ? ': ' . $tpl : '' );
	}
	return $type;
}

function bcm_agency_find_text( array $input ) {
	$matcher = bcm_agency_build_matcher( isset( $input['find'] ) ? $input['find'] : '', ! empty( $input['phone_mode'] ) );
	if ( is_wp_error( $matcher ) ) {
		return $matcher;
	}
	$only    = bcm_agency_only_ids( $input );
	$cands   = bcm_agency_replace_candidates( $matcher );
	if ( null !== $only ) {
		$cands['classic'] = array(); // element_ids only apply to Elementor content.
	}
	$results = array();
	$total   = 0;

	foreach ( $cands['elementor'] as $id ) {
		$post = get_post( $id );
		if ( ! $post || ! current_user_can( 'edit_post', $id ) ) {
			continue;
		}
		$data = json_decode( (string) get_post_meta( $id, '_elementor_data', true ), true );
		if ( ! is_array( $data ) ) {
			continue;
		}
		$found = array();
		$n     = bcm_agency_walk_elements( $data, $matcher, null, $found, '', $only );
		if ( $n ) {
			$results[] = bcm_agency_result_row( $post, 'elementor', $n, $found );
			$total    += $n;
		}
	}
	foreach ( $cands['classic'] as $id ) {
		$post = get_post( $id );
		if ( ! $post || ! current_user_can( 'edit_post', $id ) ) {
			continue;
		}
		$found = array();
		$node  = array( 'post_content' => $post->post_content );
		$n     = bcm_agency_walk_values( $node, $matcher, null, $found, '', 'post content', 'content' );
		if ( $n ) {
			$results[] = bcm_agency_result_row( $post, 'content', $n, $found );
			$total    += $n;
		}
	}

	usort(
		$results,
		function ( $a, $b ) {
			return ( $b['site_wide'] <=> $a['site_wide'] ) ?: ( $b['matches'] <=> $a['matches'] );
		}
	);

	return array(
		'find'          => (string) $input['find'],
		'phone_mode'    => 'phone' === $matcher['mode'],
		'total_matches' => $total,
		'posts'         => count( $results ),
		'results'       => $results,
		'next_step'     => $total ? 'Show the person every match (site-wide templates first). To change them, call bcm/replace-text with the post_ids they approve and approved: true.' : 'No matches. Check spelling or try phone_mode for phone numbers. Text in theme options, menus or widgets outside Elementor is not covered.',
		'not_covered'   => 'WordPress menus, sidebar widgets, theme/customizer options, plugin settings, and Yoast SEO fields (use Yoast\'s own ability) are not searched.',
	);
}

function bcm_agency_result_row( WP_Post $post, $source, $n, array $found ) {
	$type      = bcm_agency_post_label( $post );
	$site_wide = 'elementor_library' === $post->post_type && in_array( get_post_meta( $post->ID, '_elementor_template_type', true ), array( 'header', 'footer', 'popup', 'widget', 'kit' ), true );
	return array(
		'post_id'   => $post->ID,
		'title'     => get_the_title( $post ),
		'type'      => $type,
		'status'    => $post->post_status,
		'source'    => $source,
		'site_wide' => $site_wide,
		'matches'   => $n,
		'edit_url'  => 'elementor' === $source ? admin_url( 'post.php?post=' . $post->ID . '&action=elementor' ) : admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
		'view_url'  => 'publish' === $post->post_status && 'elementor_library' !== $post->post_type ? get_permalink( $post ) : '',
		'found'     => array_slice( $found, 0, 25 ),
	);
}

/**
 * Saves new Elementor data the way Elementor itself does (revision, regenerated
 * CSS and plain-text copy), falling back to the raw meta if the API is missing.
 */
function bcm_agency_save_elementor_data( $post_id, array $data ) {
	if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->documents ) ) {
		$document = \Elementor\Plugin::$instance->documents->get( $post_id, false );
		if ( $document ) {
			$ok = $document->save( array( 'elements' => $data ) );
			if ( false !== $ok ) {
				return 'elementor';
			}
		}
	}
	update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
	delete_post_meta( $post_id, '_elementor_css' );
	if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}
	return 'meta';
}

function bcm_agency_replace_text( array $input ) {
	if ( empty( $input['approved'] ) ) {
		return new WP_Error( 'bcm_needs_approval', 'Replacing text changes live pages. Run bcm/find-text, show the person every match, then call again with the approved post_ids and approved: true.' );
	}
	$ids = isset( $input['post_ids'] ) ? array_values( array_filter( array_map( 'intval', (array) $input['post_ids'] ) ) ) : array();
	if ( ! $ids ) {
		return new WP_Error( 'bcm_bad_input', 'post_ids is required: the posts the person approved from bcm/find-text. There is no site-wide replace without a list.' );
	}
	if ( count( $ids ) > 200 ) {
		return new WP_Error( 'bcm_bad_input', 'At most 200 posts per call.' );
	}
	$replace = isset( $input['replace'] ) ? (string) $input['replace'] : null;
	if ( null === $replace ) {
		return new WP_Error( 'bcm_bad_input', 'replace is required (an empty string removes the text).' );
	}
	if ( preg_match( '/<\s*(script|iframe|object|embed|style|form)\b|\son[a-z]+\s*=|javascript:/i', $replace ) ) {
		return new WP_Error( 'bcm_bad_input', 'The replacement contains markup that is not allowed (scripts, iframes or event handlers).' );
	}
	$matcher = bcm_agency_build_matcher( isset( $input['find'] ) ? $input['find'] : '', ! empty( $input['phone_mode'] ) );
	if ( is_wp_error( $matcher ) ) {
		return $matcher;
	}

	$only    = bcm_agency_only_ids( $input );
	$cands   = bcm_agency_replace_candidates( $matcher, $ids );
	if ( null !== $only ) {
		$cands['classic'] = array();
	}
	$changed = array();
	$total   = 0;

	foreach ( $cands['elementor'] as $id ) {
		if ( ! current_user_can( 'edit_post', $id ) ) {
			continue;
		}
		$data = json_decode( (string) get_post_meta( $id, '_elementor_data', true ), true );
		if ( ! is_array( $data ) ) {
			continue;
		}
		$found = array();
		$n     = bcm_agency_walk_elements( $data, $matcher, $replace, $found, '', $only );
		if ( $n ) {
			$how       = bcm_agency_save_elementor_data( $id, $data );
			$changed[] = array(
				'post_id'  => $id,
				'title'    => get_the_title( $id ),
				'replaced' => $n,
				'saved_by' => $how,
			);
			$total    += $n;
		}
	}
	foreach ( $cands['classic'] as $id ) {
		if ( ! current_user_can( 'edit_post', $id ) ) {
			continue;
		}
		$post  = get_post( $id );
		$found = array();
		$node  = array( 'post_content' => $post->post_content );
		$n     = bcm_agency_walk_values( $node, $matcher, $replace, $found, '', 'post content', 'content' );
		if ( $n ) {
			$r = wp_update_post(
				array(
					'ID'           => $id,
					'post_content' => wp_slash( $node['post_content'] ),
				),
				true
			);
			if ( ! is_wp_error( $r ) ) {
				$changed[] = array(
					'post_id'  => $id,
					'title'    => get_the_title( $id ),
					'replaced' => $n,
					'saved_by' => 'content',
				);
				$total    += $n;
			}
		}
	}

	$purged = $total ? bcm_agency_purge_page_cache() : array();

	return array(
		'id'             => $changed ? $changed[0]['post_id'] : 0,
		'total_replaced' => $total,
		'posts_changed'  => $changed,
		'skipped'        => array_values( array_diff( $ids, wp_list_pluck( $changed, 'post_id' ) ) ),
		'caches_cleared' => $purged,
		'undo'           => 'Each change is saved as a new revision: restore the previous version from the page\'s Revisions (Elementor: History → Revisions).',
		'_log'           => array(
			'find'     => 'phone' === $matcher['mode'] ? 'phone:' . $matcher['digits'] : $input['find'],
			'replace'  => $replace,
			'posts'    => wp_list_pluck( $changed, 'post_id' ),
			'replaced' => $total,
		),
	);
}

function bcm_agency_register_replace_abilities() {
	$common = array(
		'find'       => array(
			'type'        => 'string',
			'description' => 'Exact text to find (case-sensitive), or a phone number with phone_mode.',
		),
		'phone_mode' => array(
			'type'        => 'boolean',
			'default'     => false,
			'description' => 'Match the same phone number in any format: 613-299-2053, (613) 299-2053, 613.299.2053, +1 613 299 2053, tel:6132992053.',
		),
		'element_ids' => array(
			'type'        => 'array',
			'items'       => array( 'type' => 'string' ),
			'description' => 'Optional. Limit to these Elementor element ids (and anything inside them), e.g. the one widget a feedback pin points at. Without it, every match on the page is included.',
		),
	);

	bcm_agency_register(
		'bcm/find-text',
		array(
			'label'               => 'Find text across the site',
			'description'         => 'Read-only. Finds text (or a phone number in any format) in Elementor content of every version (classic sections, containers and Atomic), Elementor headers, footers, popups and global widgets, and non-Elementor posts and pages. Returns each match with the page, the element id, the widget, the field and surrounding text. Optional element_ids limits the search to specific elements. Site-wide templates are listed first. Use before bcm/replace-text.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => $common,
				'required'             => array( 'find' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_find_text',
			'permission_callback' => function () {
				return current_user_can( 'edit_pages' );
			},
		),
		true
	);

	bcm_agency_register(
		'bcm/replace-text',
		array(
			'label'               => 'Replace text across selected pages',
			'description'         => 'Replaces text found by bcm/find-text in the posts the person approved, optionally only inside specific element_ids (use this when the same text appears more than once on a page and only one spot should change). Changes live content, so it requires post_ids and approved: true. Only visible text values are changed (never element ids, widget types or styling). Phone mode writes the new number in the format each spot used (tel: links get digits only). Each changed page gets a revision for undo, Elementor styles are rebuilt and page caches are cleared.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array_merge(
					$common,
					array(
						'replace'  => array(
							'type'        => 'string',
							'description' => 'Replacement text. In phone mode, the new number as it should appear in visible text, e.g. 613-555-0199.',
						),
						'post_ids' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Posts to change, taken from bcm/find-text and approved by the person.',
						),
						'approved' => array(
							'type'        => 'boolean',
							'description' => 'Set true only after the person has seen the matches and approved the change.',
						),
					)
				),
				'required'             => array( 'find', 'replace', 'post_ids' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_replace_text',
			'permission_callback' => function () {
				return current_user_can( 'edit_pages' ) && current_user_can( 'edit_others_posts' );
			},
		)
	);
}
