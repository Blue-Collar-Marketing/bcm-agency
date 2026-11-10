<?php
defined( 'ABSPATH' ) || exit;

/**
 * Asks this site to check the BCM update feed right now (instead of waiting for
 * the next scheduled check), so a Cloudways rollout sees the new version.
 */
function bcm_agency_check_for_updates() {
	$checker = isset( $GLOBALS['bcm_agency_update_checker'] ) ? $GLOBALS['bcm_agency_update_checker'] : null;
	if ( ! $checker || ! method_exists( $checker, 'checkForUpdates' ) ) {
		return new WP_Error( 'bcm_no_checker', 'The update checker is not loaded on this site.' );
	}
	$checker->checkForUpdates();
	$update = method_exists( $checker, 'getUpdate' ) ? $checker->getUpdate() : null;
	return array(
		'installed_version' => BCM_AGENCY_VERSION,
		'latest_version'    => $update && ! empty( $update->version ) ? $update->version : BCM_AGENCY_VERSION,
		'update_available'  => (bool) $update,
		'next_step'         => $update ? 'Sync this app in Cloudways WP Manager, then update BCM Agency Tools there.' : 'Already on the latest version.',
	);
}

function bcm_agency_register_update_abilities() {
	bcm_agency_register(
		'bcm/check-for-updates',
		array(
			'label'               => 'Check for BCM Agency Tools updates now',
			'description'         => 'Makes this site check the BCM update feed immediately and reports installed vs latest version. Use before a Cloudways WP Manager sync during a rollout.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => new stdClass(),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_check_for_updates',
			'permission_callback' => function () {
				return current_user_can( 'update_plugins' );
			},
		),
		true
	);
}
