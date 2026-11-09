<?php
defined( 'ABSPATH' ) || exit;

add_action(
	'admin_menu',
	function () {
		add_management_page( 'BCM Activity Log', 'BCM Activity Log', 'manage_options', 'bcm-agency-log', 'bcm_agency_render_log_page' );
	}
);

function bcm_agency_render_log_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$rows = BCM_Log::recent( 100 );
	echo '<div class="wrap"><h1>BCM Activity Log</h1>';
	echo '<p>Every change made through BCM Agency Tools (version ' . esc_html( BCM_AGENCY_VERSION ) . '). Times are UTC. This log cannot be cleared from here.</p>';
	echo '<table class="widefat striped"><thead><tr><th>When (UTC)</th><th>User</th><th>Action</th><th>Object</th><th>Result</th><th>Details</th></tr></thead><tbody>';
	if ( ! $rows ) {
		echo '<tr><td colspan="6">No activity yet.</td></tr>';
	}
	foreach ( $rows as $r ) {
		$obj = (int) $r['object_id'];
		$link = $obj && get_post( $obj ) ? '<a href="' . esc_url( get_edit_post_link( $obj ) ) . '">#' . $obj . '</a>' : ( $obj ? '#' . $obj : '' );
		printf(
			'<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td><td><code>%s</code></td></tr>',
			esc_html( $r['created_gmt'] ),
			esc_html( $r['user_login'] ),
			esc_html( $r['ability'] ),
			wp_kses_post( $link ),
			'ok' === $r['result'] ? '✅' : '❌',
			esc_html( $r['summary'] )
		);
	}
	echo '</tbody></table></div>';
}
