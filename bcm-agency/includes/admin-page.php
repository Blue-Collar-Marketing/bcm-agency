<?php
defined( 'ABSPATH' ) || exit;

add_action(
	'admin_menu',
	function () {
		add_management_page( 'BCM Activity Log', 'BCM Activity Log', 'manage_options', 'bcm-agency-log', 'bcm_agency_render_log_page' );
	}
);

add_action( 'admin_post_bcm_agency_hub_settings', 'bcm_agency_save_hub_settings' );

/**
 * Saves the Hub access settings. Every change is written to the activity log,
 * so switching the hub off (or on) is on the record.
 */
function bcm_agency_save_hub_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You are not allowed to change these settings.', 403 );
	}
	check_admin_referer( 'bcm_agency_hub_settings' );

	$before = array(
		'enabled' => BCM_Hub::enabled(),
		'user_id' => (int) get_option( BCM_Hub::OPT_USER, 0 ),
	);

	$enabled = isset( $_POST['hub_enabled'] ) && '1' === $_POST['hub_enabled']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	update_option( BCM_Hub::OPT_ENABLED, $enabled ? '1' : '0', true );

	$user_id = isset( $_POST['hub_user_id'] ) ? absint( $_POST['hub_user_id'] ) : 0;
	if ( $user_id && ! user_can( $user_id, 'manage_options' ) ) {
		$user_id = 0; // Only administrators can be chosen.
	}
	update_option( BCM_Hub::OPT_USER, $user_id, true );

	$after = array(
		'enabled' => BCM_Hub::enabled(),
		'user_id' => $user_id,
	);
	if ( $before !== $after ) {
		BCM_Log::add( 'hub/settings', 0, 'ok', array( 'before' => $before, 'after' => $after ) );
	}

	wp_safe_redirect( add_query_arg( array( 'page' => 'bcm-agency-log', 'saved' => '1' ), admin_url( 'tools.php' ) ) );
	exit;
}

function bcm_agency_render_hub_box() {
	$enabled  = BCM_Hub::enabled();
	$forced   = BCM_Hub::forced_off();
	$chosen   = (int) get_option( BCM_Hub::OPT_USER, 0 );
	$acting   = BCM_Hub::hub_user_id();
	$acting_u = $acting ? get_userdata( $acting ) : null;
	$admins   = get_users( array( 'role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 50 ) );
	$last     = (int) get_option( BCM_Hub::OPT_LAST, 0 );
	$signing  = array_keys( BCM_Hub::trusted_keys( 'signing' ) );
	$approval = array_keys( BCM_Hub::trusted_keys( 'approval' ) );
	$sodium   = function_exists( 'sodium_crypto_sign_verify_detached' );

	echo '<h2>Hub access</h2>';
	echo '<p>Lets Blue Collar Marketing\'s team work on this site through the BCM Hub (one Claude connector for all our sites). Only requests signed by the hub\'s key are accepted; code, scripts and tracking changes are never allowed through it.</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'bcm_agency_hub_settings' );
	echo '<input type="hidden" name="action" value="bcm_agency_hub_settings">';
	echo '<table class="form-table" role="presentation"><tbody>';

	echo '<tr><th scope="row">Status</th><td>';
	if ( $forced ) {
		echo '<strong>Off</strong> (switched off in wp-config.php with BCM_AGENCY_HUB_DISABLED)';
	} else {
		echo '<label><input type="checkbox" name="hub_enabled" value="1" ' . checked( $enabled, true, false ) . '> Allow requests from the BCM Hub</label>';
	}
	echo '</td></tr>';

	echo '<tr><th scope="row"><label for="bcm-hub-user">Acts as</label></th><td><select id="bcm-hub-user" name="hub_user_id">';
	$auto_label = 'Automatic: oldest administrator';
	if ( ! $chosen && $acting_u ) {
		$auto_label .= ' (' . $acting_u->user_login . ')';
	}
	echo '<option value="0"' . selected( $chosen, 0, false ) . '>' . esc_html( $auto_label ) . '</option>';
	foreach ( $admins as $u ) {
		echo '<option value="' . (int) $u->ID . '"' . selected( $chosen, (int) $u->ID, false ) . '>' . esc_html( $u->user_login ) . '</option>';
	}
	echo '</select>';
	if ( ! $acting ) {
		echo '<p class="description" style="color:#b32d2e">No usable administrator account: hub requests are refused until one is chosen.</p>';
	} else {
		echo '<p class="description">Hub requests run as this account, without unfiltered HTML and without plugin, theme or user management. The log below shows who asked.</p>';
	}
	echo '</td></tr>';

	echo '<tr><th scope="row">Details</th><td>';
	echo 'Last hub contact: ' . ( $last ? esc_html( gmdate( 'Y-m-d H:i', $last ) ) . ' UTC' : 'never' ) . '<br>';
	echo 'Trusted signing keys: ' . ( $signing ? '<code>' . esc_html( implode( ', ', $signing ) ) . '</code>' : '<strong>none</strong> (the hub cannot reach this site)' ) . '<br>';
	echo 'Trusted approval keys: ' . ( $approval ? '<code>' . esc_html( implode( ', ', $approval ) ) . '</code>' : 'none' ) . '<br>';
	echo 'Signature support (PHP sodium): ' . ( $sodium ? 'yes' : '<strong>missing</strong>' );
	echo '</td></tr>';

	echo '</tbody></table>';
	submit_button( 'Save hub settings' );
	echo '</form>';
}

function bcm_agency_render_log_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$rows = BCM_Log::recent( 100 );
	echo '<div class="wrap"><h1>BCM Activity Log</h1>';
	if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="notice notice-success is-dismissible"><p>Hub settings saved.</p></div>';
	}
	bcm_agency_render_hub_box();

	echo '<h2>Activity</h2>';
	echo '<p>Every change made through BCM Agency Tools (version ' . esc_html( BCM_AGENCY_VERSION ) . '), and every refused hub request. Times are UTC. This log cannot be cleared from here. For hub requests, "Via" shows the hub\'s ID for the person who asked; the hub\'s own log maps it to a name.</p>';
	echo '<table class="widefat striped"><thead><tr><th>When (UTC)</th><th>User</th><th>Via</th><th>Action</th><th>Object</th><th>Result</th><th>Details</th></tr></thead><tbody>';
	if ( ! $rows ) {
		echo '<tr><td colspan="7">No activity yet.</td></tr>';
	}
	foreach ( $rows as $r ) {
		$obj  = (int) $r['object_id'];
		$link = $obj && get_post( $obj ) ? '<a href="' . esc_url( get_edit_post_link( $obj ) ) . '">#' . $obj . '</a>' : ( $obj ? '#' . $obj : '' );
		$via  = isset( $r['via'] ) && 'hub' === $r['via'] ? 'hub' . ( '' !== $r['actor'] ? ' · ' . $r['actor'] : '' ) : '';
		printf(
			'<tr><td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td><td><code>%s</code></td></tr>',
			esc_html( $r['created_gmt'] ),
			esc_html( $r['user_login'] ),
			esc_html( $via ),
			esc_html( $r['ability'] ),
			wp_kses_post( $link ),
			'ok' === $r['result'] ? '✅' : '❌',
			esc_html( $r['summary'] )
		);
	}
	echo '</tbody></table></div>';
}
