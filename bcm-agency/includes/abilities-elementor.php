<?php
/**
 * Elementor's V4 (Atomic) editor: check it, and turn it on.
 *
 * Elementor switches V4 on by itself only for sites first installed on
 * Elementor 4.0 or later; older sites keep the classic editor until someone
 * opts in. bcm/enable-atomic-editor does exactly what Elementor's own opt-in
 * does (Opt_In::opt_in_v4()): it activates the V4 editor, containers and
 * Atomic elements, and nothing else. Elementor's other experiments stay out of
 * reach through the hub. Existing classic pages keep working.
 *
 * @package BCM_Agency
 */

defined( 'ABSPATH' ) || exit;

/** The features Elementor's own V4 opt-in turns on. */
const BCM_AGENCY_V4_FEATURES = array( 'e_opt_in_v4', 'container', 'e_atomic_elements' );

/** Elementor's experiments manager, or null when Elementor is not active. */
function bcm_agency_elementor_experiments() {
	if ( ! class_exists( '\\Elementor\\Plugin' ) || ! isset( \Elementor\Plugin::$instance ) || ! isset( \Elementor\Plugin::$instance->experiments ) ) {
		return null;
	}
	return \Elementor\Plugin::$instance->experiments;
}

/** State of each V4 feature: true (on), false (off) or null (not in this Elementor version). */
function bcm_agency_v4_states( $experiments ) {
	$states = array();
	foreach ( BCM_AGENCY_V4_FEATURES as $feature ) {
		$known              = method_exists( $experiments, 'get_features' ) ? $experiments->get_features( $feature ) : null;
		$states[ $feature ] = empty( $known ) ? null : (bool) $experiments->is_feature_active( $feature );
	}
	return $states;
}

function bcm_agency_get_atomic_status( array $input ) {
	$experiments = bcm_agency_elementor_experiments();
	if ( ! $experiments ) {
		return new WP_Error( 'bcm_no_elementor', 'Elementor is not active on this site.' );
	}
	$states  = bcm_agency_v4_states( $experiments );
	$missing = array_keys( $states, null, true );
	$on      = ! $missing && ! in_array( false, $states, true );
	return array(
		'atomic_editor_on' => $on,
		'features'         => $states,
		'elementor'        => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
		'note'             => $on ? 'Elementor V4 (Atomic) is on: new pages and sections can use Atomic elements.' : ( $missing ? 'This Elementor version does not offer V4 (Atomic) yet. Update Elementor to 4.x first.' : 'V4 (Atomic) is off: Claude builds with classic Elementor here. bcm/enable-atomic-editor turns it on (Slack approval through the hub).' ),
	);
}

function bcm_agency_enable_atomic_editor( array $input ) {
	$experiments = bcm_agency_elementor_experiments();
	if ( ! $experiments ) {
		return new WP_Error( 'bcm_no_elementor', 'Elementor is not active on this site.' );
	}
	$before  = bcm_agency_v4_states( $experiments );
	$missing = array_keys( $before, null, true );
	if ( $missing ) {
		return new WP_Error( 'bcm_v4_unavailable', 'This Elementor version does not offer V4 (Atomic) yet (missing: ' . implode( ', ', $missing ) . '). Update Elementor to 4.x first.' );
	}
	if ( ! in_array( false, $before, true ) ) {
		return new WP_Error( 'bcm_no_change', 'Elementor V4 (Atomic) is already on for this site.' );
	}
	if ( empty( $input['approved'] ) ) {
		return new WP_Error( 'bcm_needs_approval', 'This turns on Elementor\'s V4 (Atomic) editor for the whole site, exactly as Elementor\'s own opt-in does (V4 editor, containers, Atomic elements). Existing classic pages keep working. Confirm with the person, then call again with approved: true.' );
	}

	try {
		if ( class_exists( '\\Elementor\\Modules\\AtomicWidgets\\OptIn\\Opt_In' ) && method_exists( '\\Elementor\\Modules\\AtomicWidgets\\OptIn\\Opt_In', 'opt_in_v4' ) ) {
			( new \Elementor\Modules\AtomicWidgets\OptIn\Opt_In() )->opt_in_v4();
			$how = 'elementor_opt_in';
		} else {
			foreach ( BCM_AGENCY_V4_FEATURES as $feature ) {
				$experiments->set_feature_state( $feature, 'active' );
			}
			update_option( 'elementor_v4_opt_in_clicked', true );
			$how = 'experiments_manager';
		}
	} catch ( Throwable $e ) {
		return new WP_Error( 'bcm_v4_failed', 'Elementor refused to turn V4 on: ' . $e->getMessage() . ' Nothing else was changed; check Elementor → Settings → Features.' );
	}

	$after = bcm_agency_v4_states( $experiments );
	if ( in_array( false, $after, true ) ) {
		return new WP_Error( 'bcm_v4_failed', 'Elementor did not turn every V4 feature on (' . wp_json_encode( $after ) . '). Check Elementor → Settings → Features.' );
	}
	return array(
		'atomic_editor_on' => true,
		'features'         => $after,
		'note'             => 'Elementor V4 (Atomic) is on. New pages and sections can use Atomic elements; existing classic pages are unchanged. Elementor regenerates its CSS on the next page views.',
		'_log'             => array(
			'action' => 'atomic editor on',
			'how'    => $how,
			'before' => $before,
		),
	);
}

function bcm_agency_register_elementor_abilities() {
	bcm_agency_register(
		'bcm/get-atomic-status',
		array(
			'label'               => 'Check Elementor V4 (Atomic) editor',
			'description'         => 'Read-only. Whether Elementor\'s V4 (Atomic) editor is on for this site (the V4 editor, containers and Atomic elements), and the Elementor version. When it is off, Claude builds with classic Elementor; bcm/enable-atomic-editor turns it on.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => new stdClass(),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_get_atomic_status',
			'permission_callback' => function () {
				return current_user_can( 'edit_pages' );
			},
		),
		true
	);

	bcm_agency_register(
		'bcm/enable-atomic-editor',
		array(
			'label'               => 'Turn on Elementor V4 (Atomic) editor',
			'description'         => 'Turns on Elementor\'s V4 (Atomic) editor for the whole site, exactly as Elementor\'s own opt-in does: the V4 editor, containers and Atomic elements, and nothing else. Existing classic pages keep working. Check first with bcm/get-atomic-status. Requires approved: true after the person confirms; through the BCM hub it also needs Slack approval.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'approved' => array(
						'type'        => 'boolean',
						'description' => 'Set true only after the person approved turning V4 on for this site.',
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_enable_atomic_editor',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);
}
