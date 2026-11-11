<?php
defined( 'ABSPATH' ) || exit;

/**
 * Small, site-wide CSS fixes for Elementor's Atomic (V4) editor.
 *
 * Atomic image fit: Atomic renders an image as
 *   <picture class="e-image-base …"><source …><img …></picture>
 * and puts the element's styles (width, height, object-fit, border-radius)
 * on the <picture>. The <img> inside keeps its natural size, so a photo given
 * a fixed box (a hero, a card, a gallery tile) does not fill it.
 *
 * OFF by default. It is switched on per site (option bcm_agency_atomic_image_fit)
 * with the bcm/set-atomic-image-fit ability, so installing or updating the plugin
 * never changes how an existing site looks. New sites get it from the template.
 *
 * When on, the inner <img> inherits those values from its <picture>:
 * - a sized image (e.g. width 100%, height 26rem, object-fit cover) fills its box;
 * - an unsized image (width/height auto) behaves exactly as before.
 * No page content is changed; turning it off restores the previous look exactly.
 *
 * Images positioned to fill their parent (position: absolute; inset: 0) also
 * need width: 100% and height: 100% on the image element, which the fix then
 * passes through to the photo.
 */
function bcm_agency_frontend_fixes_css() {
	$css = '';
	if ( apply_filters( 'bcm_agency_atomic_image_fit', (bool) get_option( 'bcm_agency_atomic_image_fit', false ) ) ) {
		$css .= '.e-image-base>img{width:inherit;height:inherit;max-width:100%;object-fit:inherit;object-position:inherit;border-radius:inherit;aspect-ratio:inherit;display:block}';
	}
	return $css;
}

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return;
		}
		$css = bcm_agency_frontend_fixes_css();
		if ( '' === $css ) {
			return;
		}
		wp_register_style( 'bcm-agency-fixes', false, array(), BCM_AGENCY_VERSION );
		wp_enqueue_style( 'bcm-agency-fixes' );
		wp_add_inline_style( 'bcm-agency-fixes', $css );
	},
	20
);

function bcm_agency_set_atomic_image_fit( array $input ) {
	if ( ! isset( $input['enabled'] ) ) {
		return new WP_Error( 'bcm_bad_input', 'Pass enabled: true or false.' );
	}
	if ( empty( $input['approved'] ) ) {
		return new WP_Error( 'bcm_needs_approval', 'This changes how Atomic (V4) images display across the whole site. Confirm with the person, then call again with approved: true.' );
	}
	$before = (bool) get_option( 'bcm_agency_atomic_image_fit', false );
	$after  = (bool) $input['enabled'];
	update_option( 'bcm_agency_atomic_image_fit', $after ? 1 : 0 );
	$cleared = bcm_agency_purge_page_cache();
	return array(
		'before'  => $before,
		'after'   => $after,
		'cleared' => $cleared,
		'note'    => $after
			? 'On: Atomic images now fill the width, height, crop and rounding set on their image element. Check pages with Atomic images; images with a fixed height but no object-fit will now stretch to that height, so give them object-fit: cover. Turn off to restore the previous look.'
			: 'Off: Atomic images are back to their previous behaviour.',
		'_log'    => array( 'before' => $before, 'after' => $after ),
	);
}

function bcm_agency_get_atomic_image_fit() {
	return array(
		'enabled' => (bool) get_option( 'bcm_agency_atomic_image_fit', false ),
		'note'    => 'Off by default. Turn on with bcm/set-atomic-image-fit for sites built with Elementor Atomic (V4).',
	);
}

function bcm_agency_register_fix_abilities() {
	bcm_agency_register(
		'bcm/set-atomic-image-fit',
		array(
			'label'               => 'Turn the Atomic image-fit fix on or off',
			'description'         => 'Site-wide, opt-in, off by default. When on, Elementor Atomic (V4) images fill the box set on their image element (width, height, object-fit, border-radius pass through to the photo). Use on new Atomic builds; on existing Atomic sites, check images first, because a fixed height without object-fit will stretch. Classic Elementor images are never affected. Requires approved: true; clears page caches; fully reversible.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'enabled'  => array( 'type' => 'boolean' ),
					'approved' => array(
						'type'        => 'boolean',
						'description' => 'Set true only after the person has approved changing this site-wide setting.',
					),
				),
				'required'             => array( 'enabled' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_set_atomic_image_fit',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);
	bcm_agency_register(
		'bcm/get-atomic-image-fit',
		array(
			'label'               => 'Is the Atomic image-fit fix on?',
			'description'         => 'Read-only. Whether the opt-in Atomic image-fit fix is on for this site.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => new stdClass(),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_get_atomic_image_fit',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		),
		true
	);
}
