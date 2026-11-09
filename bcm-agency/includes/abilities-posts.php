<?php
defined( 'ABSPATH' ) || exit;

/**
 * Shared field handling for create and update. Returns [ postarr, tax, thumb ] or WP_Error.
 */
function bcm_agency_post_fields( array $input ) {
	$postarr = array();
	if ( isset( $input['title'] ) ) {
		$postarr['post_title'] = sanitize_text_field( $input['title'] );
	}
	if ( isset( $input['content'] ) ) {
		// Always filtered, even for admins: no scripts, iframes or inline event handlers.
		$postarr['post_content'] = wp_kses_post( (string) $input['content'] );
	}
	if ( isset( $input['excerpt'] ) ) {
		$postarr['post_excerpt'] = sanitize_textarea_field( $input['excerpt'] );
	}
	if ( isset( $input['slug'] ) ) {
		$postarr['post_name'] = sanitize_title( $input['slug'] );
	}

	$cats = null;
	if ( isset( $input['categories'] ) ) {
		$cats = bcm_agency_resolve_categories( (array) $input['categories'] );
		if ( is_wp_error( $cats ) ) {
			return $cats;
		}
	}

	$tags = null;
	if ( isset( $input['tags'] ) ) {
		$tags = array_values( array_filter( array_map( 'sanitize_text_field', (array) $input['tags'] ) ) );
	}

	$thumb = null;
	if ( isset( $input['featured_image_id'] ) ) {
		$thumb = (int) $input['featured_image_id'];
		if ( $thumb ) {
			$thumb = bcm_agency_check_image( $thumb );
			if ( is_wp_error( $thumb ) ) {
				return $thumb;
			}
		}
	}

	$focus = isset( $input['focus_keyphrase'] ) ? sanitize_text_field( $input['focus_keyphrase'] ) : null;

	return array( $postarr, $cats, $tags, $thumb, $focus );
}

function bcm_agency_apply_focus( $post_id, $focus ) {
	if ( null === $focus ) {
		return;
	}
	if ( '' === $focus ) {
		delete_post_meta( $post_id, '_yoast_wpseo_focuskw' );
	} else {
		update_post_meta( $post_id, '_yoast_wpseo_focuskw', $focus );
	}
}

function bcm_agency_apply_terms_and_thumb( $post_id, $cats, $tags, $thumb ) {
	if ( null !== $cats ) {
		wp_set_post_categories( $post_id, $cats );
	}
	if ( null !== $tags ) {
		wp_set_post_tags( $post_id, $tags, false );
	}
	if ( null !== $thumb ) {
		if ( $thumb ) {
			set_post_thumbnail( $post_id, $thumb );
		} else {
			delete_post_thumbnail( $post_id );
		}
	}
}

function bcm_agency_readiness_warnings( WP_Post $post ) {
	$w = array();
	if ( ! get_post_thumbnail_id( $post ) ) {
		$w[] = 'No featured image.';
	}
	$cats = wp_get_post_categories( $post->ID );
	if ( ! $cats || array( (int) get_option( 'default_category' ) ) === array_map( 'intval', $cats ) ) {
		$w[] = 'Only the default category is set.';
	}
	if ( '' === trim( $post->post_excerpt ) ) {
		$w[] = 'No excerpt.';
	}
	if ( '' === trim( wp_strip_all_tags( $post->post_content ) ) ) {
		$w[] = 'Content is empty.';
	}
	return $w;
}

function bcm_agency_create_post( array $input ) {
	if ( empty( $input['title'] ) || ! isset( $input['content'] ) ) {
		return new WP_Error( 'bcm_bad_input', 'title and content are required.' );
	}
	$fields = bcm_agency_post_fields( $input );
	if ( is_wp_error( $fields ) ) {
		return $fields;
	}
	list( $postarr, $cats, $tags, $thumb, $focus ) = $fields;

	$postarr['post_type']   = 'post';
	$postarr['post_status'] = 'draft'; // Always a draft. Going live is bcm/schedule-post, after approval.
	$postarr['post_author'] = get_current_user_id();

	$id = wp_insert_post( wp_slash( $postarr ), true );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	bcm_agency_apply_terms_and_thumb( $id, $cats, $tags, $thumb );
	bcm_agency_apply_focus( $id, $focus );

	$post = get_post( $id );
	$out  = bcm_agency_format_post( $post );
	$out['warnings'] = bcm_agency_readiness_warnings( $post );
	$out['_log']     = array( 'title' => $out['title'] );
	return $out;
}

function bcm_agency_update_post( array $input ) {
	$post = bcm_agency_get_editable_post( isset( $input['post_id'] ) ? $input['post_id'] : 0 );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$changes_content = isset( $input['content'] );
	if ( $changes_content && bcm_agency_is_elementor_post( $post->ID ) ) {
		return new WP_Error( 'bcm_elementor_post', 'This post is built with Elementor, so its body must be edited with the Elementor tools. Title, excerpt, categories, tags and featured image can still be changed here.' );
	}

	// A post that's live or scheduled changes for real: require an explicit approval flag.
	if ( in_array( $post->post_status, array( 'publish', 'future' ), true ) && empty( $input['live_edit_approved'] ) ) {
		return new WP_Error( 'bcm_needs_approval', 'This post is ' . $post->post_status . ', so edits take effect immediately. Show the person the exact changes, get their approval, then call again with live_edit_approved: true.' );
	}

	$fields = bcm_agency_post_fields( $input );
	if ( is_wp_error( $fields ) ) {
		return $fields;
	}
	list( $postarr, $cats, $tags, $thumb, $focus ) = $fields;

	$changed = array_keys( $postarr );
	if ( $postarr ) {
		$postarr['ID'] = $post->ID;
		$r             = wp_update_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
	}
	bcm_agency_apply_terms_and_thumb( $post->ID, $cats, $tags, $thumb );
	bcm_agency_apply_focus( $post->ID, $focus );
	foreach ( array( 'categories' => $cats, 'tags' => $tags, 'featured_image_id' => $thumb, 'focus_keyphrase' => $focus ) as $k => $v ) {
		if ( null !== $v ) {
			$changed[] = $k;
		}
	}
	if ( ! $changed ) {
		return new WP_Error( 'bcm_bad_input', 'Nothing to change. Pass at least one field.' );
	}

	$post = get_post( $post->ID );
	$out  = bcm_agency_format_post( $post );
	$out['changed']  = $changed;
	$out['warnings'] = bcm_agency_readiness_warnings( $post );
	$out['_log']     = array(
		'changed'    => $changed,
		'was_live'   => ! empty( $input['live_edit_approved'] ),
	);
	return $out;
}

function bcm_agency_schedule_post( array $input ) {
	$post = bcm_agency_get_editable_post( isset( $input['post_id'] ) ? $input['post_id'] : 0 );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	if ( 'publish' === $post->post_status ) {
		return new WP_Error( 'bcm_already_live', 'This post is already published. Unpublishing is a human decision in wp-admin.' );
	}

	// Unschedule: back to draft.
	if ( ! empty( $input['unschedule'] ) ) {
		if ( 'future' !== $post->post_status ) {
			return new WP_Error( 'bcm_not_scheduled', 'This post is not scheduled.' );
		}
		$r = wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => 'draft',
			),
			true
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$out         = bcm_agency_format_post( get_post( $post->ID ) );
		$out['_log'] = array( 'action' => 'unscheduled' );
		return $out;
	}

	$when = bcm_agency_parse_time( isset( $input['publish_at'] ) ? $input['publish_at'] : '' );
	if ( is_wp_error( $when ) ) {
		return $when;
	}
	$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	if ( $when < $now->modify( '+5 minutes' ) ) {
		return new WP_Error( 'bcm_bad_date', 'publish_at must be at least 5 minutes in the future. Publishing right now is done by a person.' );
	}
	if ( $when > $now->modify( '+2 years' ) ) {
		return new WP_Error( 'bcm_bad_date', 'publish_at is more than 2 years away.' );
	}

	$local = $when->setTimezone( wp_timezone() );
	$r     = wp_update_post(
		array(
			'ID'            => $post->ID,
			'post_status'   => 'future',
			'post_date'     => $local->format( 'Y-m-d H:i:s' ),
			'post_date_gmt' => $when->format( 'Y-m-d H:i:s' ),
			'edit_date'     => true,
		),
		true
	);
	if ( is_wp_error( $r ) ) {
		return $r;
	}

	$post = get_post( $post->ID );
	$out  = bcm_agency_format_post( $post );
	$out['scheduled_for_site_time'] = $local->format( 'Y-m-d H:i' ) . ' (' . wp_timezone_string() . ')';
	$out['warnings']                = bcm_agency_readiness_warnings( $post );
	$out['_log']                    = array(
		'action'   => 'scheduled',
		'for_gmt'  => $when->format( 'Y-m-d H:i' ),
		'title'    => $out['title'],
	);
	return $out;
}

function bcm_agency_publish_post( array $input ) {
	if ( empty( $input['approved'] ) ) {
		return new WP_Error( 'bcm_needs_approval', 'Publishing makes the post live immediately. Confirm with the person, then call again with approved: true.' );
	}
	$post = bcm_agency_get_editable_post( isset( $input['post_id'] ) ? $input['post_id'] : 0 );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	if ( 'publish' === $post->post_status ) {
		return new WP_Error( 'bcm_already_live', 'This post is already published.' );
	}
	$was = $post->post_status;
	$r   = wp_update_post(
		array(
			'ID'            => $post->ID,
			'post_status'   => 'publish',
			'post_date'     => current_time( 'mysql' ),
			'post_date_gmt' => current_time( 'mysql', true ),
			'edit_date'     => true,
		),
		true
	);
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	$post = get_post( $post->ID );
	$out  = bcm_agency_format_post( $post );
	$out['warnings'] = bcm_agency_readiness_warnings( $post );
	$out['_log']     = array(
		'action' => 'published now',
		'was'    => $was,
		'title'  => $out['title'],
	);
	return $out;
}

function bcm_agency_get_post( array $input ) {
	$post = bcm_agency_get_editable_post( isset( $input['post_id'] ) ? $input['post_id'] : 0 );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$with_content = ! isset( $input['include_content'] ) || ! empty( $input['include_content'] );
	$out          = bcm_agency_format_post( $post, $with_content );
	$out['warnings'] = bcm_agency_readiness_warnings( $post );
	return $out;
}

function bcm_agency_list_posts( array $input ) {
	$allowed = array( 'draft', 'pending', 'future', 'publish', 'any' );
	$status  = isset( $input['status'] ) && in_array( $input['status'], $allowed, true ) ? $input['status'] : 'any';
	$q       = new WP_Query(
		array(
			'post_type'      => 'post',
			'post_status'    => 'any' === $status ? array( 'draft', 'pending', 'future', 'publish' ) : $status,
			's'              => isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '',
			'posts_per_page' => isset( $input['per_page'] ) ? max( 1, min( 50, (int) $input['per_page'] ) ) : 20,
			'paged'          => isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => false,
		)
	);
	return array(
		'total' => (int) $q->found_posts,
		'pages' => (int) $q->max_num_pages,
		'posts' => array_map(
			function ( $p ) {
				return bcm_agency_format_post( $p );
			},
			array_filter(
				$q->posts,
				function ( $p ) {
					return current_user_can( 'edit_post', $p->ID );
				}
			)
		),
	);
}

function bcm_agency_list_categories() {
	$terms = get_terms(
		array(
			'taxonomy'   => 'category',
			'hide_empty' => false,
		)
	);
	if ( is_wp_error( $terms ) ) {
		return $terms;
	}
	return array(
		'default_category_id' => (int) get_option( 'default_category' ),
		'categories'          => array_map(
			function ( $t ) {
				return array(
					'id'     => $t->term_id,
					'name'   => $t->name,
					'slug'   => $t->slug,
					'parent' => $t->parent,
					'count'  => $t->count,
				);
			},
			$terms
		),
	);
}

function bcm_agency_register_post_abilities() {
	$post_fields = array(
		'title'             => array( 'type' => 'string' ),
		'content'           => array(
			'type'        => 'string',
			'description' => 'Post body as HTML or block markup. Scripts and iframes are stripped.',
		),
		'excerpt'           => array(
			'type'        => 'string',
			'description' => 'Short summary shown in blog listings.',
		),
		'slug'              => array( 'type' => 'string' ),
		'categories'        => array(
			'type'        => 'array',
			'items'       => array( 'type' => array( 'integer', 'string' ) ),
			'description' => 'Existing category IDs or exact names (see bcm/list-categories). Unknown names are refused to avoid near-duplicates; create new ones first with bcm/create-term.',
		),
		'tags'              => array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		),
		'featured_image_id' => array(
			'type'        => 'integer',
			'description' => 'Media Library image ID (from bcm/upload-media). 0 removes the featured image.',
		),
		'focus_keyphrase'   => array(
			'type'        => 'string',
			'description' => 'Yoast SEO focus keyphrase (Yoast\'s own ability cannot set this). Empty string clears it.',
		),
	);

	bcm_agency_register(
		'bcm/create-post',
		array(
			'label'               => 'Create blog post (draft)',
			'description'         => 'Creates a standard WordPress blog post (not an Elementor page) as a draft, with categories, tags, excerpt, featured image and Yoast focus keyphrase. Never publishes. Set the Yoast SEO title and meta description with yoast-seo/update-post-seo-data, then use bcm/schedule-post or bcm/publish-post once a person approves.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => $post_fields,
				'required'             => array( 'title', 'content' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_create_post',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		)
	);

	bcm_agency_register(
		'bcm/update-post',
		array(
			'label'               => 'Update blog post',
			'description'         => 'Edits an existing blog post. Only the fields passed are changed. Drafts can be edited freely; published or scheduled posts change immediately, so they require live_edit_approved: true after a person has approved the exact change.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array_merge(
					array( 'post_id' => array( 'type' => 'integer' ) ),
					$post_fields,
					array(
						'live_edit_approved' => array(
							'type'        => 'boolean',
							'description' => 'Only set true after a person has approved this exact change to a live or scheduled post.',
						),
					)
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_update_post',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		)
	);

	bcm_agency_register(
		'bcm/schedule-post',
		array(
			'label'               => 'Schedule blog post',
			'description'         => 'Schedules a draft blog post to publish at a future time (at least 5 minutes ahead), or moves a scheduled post back to draft with unschedule: true. Only call after a person has approved the post. Cannot publish immediately or unpublish a live post.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'    => array( 'type' => 'integer' ),
					'publish_at' => array(
						'type'        => 'string',
						'description' => 'ISO 8601 with offset (2026-12-01T09:00:00-05:00), or site-local time without one (2026-12-01 09:00).',
					),
					'unschedule' => array(
						'type'        => 'boolean',
						'description' => 'Move a scheduled post back to draft.',
					),
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_schedule_post',
			'permission_callback' => function () {
				return current_user_can( 'publish_posts' );
			},
		)
	);

	bcm_agency_register(
		'bcm/publish-post',
		array(
			'label'               => 'Publish blog post now',
			'description'         => 'Publishes a draft or scheduled blog post immediately (for quick posts a PM asks for). Requires approved: true after the person confirms. Cannot unpublish.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'  => array( 'type' => 'integer' ),
					'approved' => array(
						'type'        => 'boolean',
						'description' => 'Set true only after the person has approved publishing this post now.',
					),
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_publish_post',
			'permission_callback' => function () {
				return current_user_can( 'publish_posts' );
			},
		)
	);

	bcm_agency_register(
		'bcm/get-post',
		array(
			'label'               => 'Get blog post',
			'description'         => 'Reads one blog post: title, status, dates, categories, tags, featured image, preview link, readiness warnings and (by default) content.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'         => array( 'type' => 'integer' ),
					'include_content' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_get_post',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		),
		true
	);

	bcm_agency_register(
		'bcm/list-posts',
		array(
			'label'               => 'List blog posts',
			'description'         => 'Lists blog posts by status (draft, pending, future = scheduled, publish, any), newest first, with optional search. No content, just summaries.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'status'   => array(
						'type' => 'string',
						'enum' => array( 'draft', 'pending', 'future', 'publish', 'any' ),
					),
					'search'   => array( 'type' => 'string' ),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 50,
					),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_list_posts',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		),
		true
	);

	bcm_agency_register(
		'bcm/list-categories',
		array(
			'label'               => 'List blog categories',
			'description'         => 'Lists all blog categories with IDs and post counts, so new posts are filed under existing categories.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => new stdClass(),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_list_categories',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		),
		true
	);
}
