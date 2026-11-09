<?php
defined( 'ABSPATH' ) || exit;

function bcm_agency_create_term( array $input ) {
	$taxonomy = isset( $input['taxonomy'] ) && 'post_tag' === $input['taxonomy'] ? 'post_tag' : 'category';
	$name     = isset( $input['name'] ) ? trim( sanitize_text_field( $input['name'] ) ) : '';
	if ( '' === $name ) {
		return new WP_Error( 'bcm_bad_input', 'name is required.' );
	}

	$parent = 0;
	if ( 'category' === $taxonomy && ! empty( $input['parent'] ) ) {
		$p = is_numeric( $input['parent'] ) ? get_term( (int) $input['parent'], 'category' ) : get_term_by( 'name', (string) $input['parent'], 'category' );
		if ( ! $p || is_wp_error( $p ) ) {
			return new WP_Error( 'bcm_unknown_parent', 'Parent category not found: ' . $input['parent'] );
		}
		$parent = (int) $p->term_id;
	}

	// Already there (case-insensitive name match under the same parent)? Return it instead of duplicating.
	$existing = term_exists( $name, $taxonomy, 'category' === $taxonomy ? $parent : null );
	if ( $existing ) {
		$term = get_term( (int) ( is_array( $existing ) ? $existing['term_id'] : $existing ), $taxonomy );
		return array(
			'id'       => (int) $term->term_id,
			'taxonomy' => $taxonomy,
			'name'     => $term->name,
			'slug'     => $term->slug,
			'parent'   => (int) $term->parent,
			'created'  => false,
			'note'     => 'Already existed; nothing created.',
			'_log'     => array( 'existing' => $term->name ),
		);
	}

	$args = array( 'parent' => $parent );
	if ( ! empty( $input['slug'] ) ) {
		$args['slug'] = sanitize_title( $input['slug'] );
	}
	if ( isset( $input['description'] ) ) {
		$args['description'] = sanitize_textarea_field( $input['description'] );
	}
	$r = wp_insert_term( $name, $taxonomy, $args );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	$term = get_term( (int) $r['term_id'], $taxonomy );
	return array(
		'id'       => (int) $term->term_id,
		'taxonomy' => $taxonomy,
		'name'     => $term->name,
		'slug'     => $term->slug,
		'parent'   => (int) $term->parent,
		'created'  => true,
		'_log'     => array(
			'taxonomy' => $taxonomy,
			'name'     => $term->name,
		),
	);
}

function bcm_agency_register_term_abilities() {
	bcm_agency_register(
		'bcm/create-term',
		array(
			'label'               => 'Create blog category or tag',
			'description'         => 'Creates a blog category (optionally under a parent) or tag. If one with that name already exists it is returned instead, so nothing is duplicated. Check bcm/list-categories first for near-matches (e.g. "Landscape Tips" vs "Landscaping Tips").',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'taxonomy'    => array(
						'type'    => 'string',
						'enum'    => array( 'category', 'post_tag' ),
						'default' => 'category',
					),
					'name'        => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'parent'      => array(
						'type'        => array( 'integer', 'string' ),
						'description' => 'Categories only: parent category ID or exact name.',
					),
					'description' => array( 'type' => 'string' ),
				),
				'required'             => array( 'name' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_create_term',
			'permission_callback' => function () {
				return current_user_can( 'manage_categories' );
			},
		)
	);
}
