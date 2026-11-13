<?php
/**
 * Plugin Name:       BCM Agency Tools
 * Description:       Blue Collar Marketing's site toolkit. Powers faster, safer updates across our managed websites: content and image changes, blog publishing, contact form settings and tracking codes, with every change approved and logged.
 * Version:           1.4.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Blue Collar Marketing
 * Author URI:        https://bluecollarmarketing.ca
 * License:           GPL-2.0-or-later
 * Text Domain:       bcm-agency
 */

defined( 'ABSPATH' ) || exit;

define( 'BCM_AGENCY_VERSION', '1.4.0' );
define( 'BCM_AGENCY_FILE', __FILE__ );
define( 'BCM_AGENCY_DIR', plugin_dir_path( __FILE__ ) );

/*
 * Where sites check for new versions of this plugin. Point the subdomain at
 * wherever the release JSON lives (e.g. a public GitHub release). Override per
 * site in wp-config.php only if you really need to.
 */
if ( ! defined( 'BCM_AGENCY_UPDATE_URL' ) ) {
	define( 'BCM_AGENCY_UPDATE_URL', 'https://updates.bluecollarmarketing.ca/bcm-agency/info.json' );
}

require_once BCM_AGENCY_DIR . 'includes/class-bcm-log.php';
require_once BCM_AGENCY_DIR . 'includes/helpers.php';
require_once BCM_AGENCY_DIR . 'includes/abilities-media.php';
require_once BCM_AGENCY_DIR . 'includes/abilities-posts.php';
require_once BCM_AGENCY_DIR . 'includes/abilities-site.php';
require_once BCM_AGENCY_DIR . 'includes/abilities-terms.php';
require_once BCM_AGENCY_DIR . 'includes/abilities-tracking.php';
require_once BCM_AGENCY_DIR . 'includes/abilities-replace.php';
require_once BCM_AGENCY_DIR . 'includes/abilities-forms.php';
require_once BCM_AGENCY_DIR . 'includes/abilities-images.php';
require_once BCM_AGENCY_DIR . 'includes/abilities-lists.php';
require_once BCM_AGENCY_DIR . 'includes/abilities-menus.php';
require_once BCM_AGENCY_DIR . 'includes/abilities-updates.php';
require_once BCM_AGENCY_DIR . 'includes/frontend-fixes.php';
require_once BCM_AGENCY_DIR . 'includes/class-bcm-hub-policy.php';
require_once BCM_AGENCY_DIR . 'includes/class-bcm-hub.php';
require_once BCM_AGENCY_DIR . 'includes/admin-page.php';

register_activation_hook( __FILE__, array( 'BCM_Log', 'install' ) );

/*
 * Abilities API (WordPress 6.9+). If it's missing, show a notice instead of fataling.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p><strong>BCM Agency Tools</strong> needs WordPress 6.9 or newer (the Abilities API). No abilities were registered.</p></div>';
				}
			);
			return;
		}
		BCM_Log::maybe_upgrade();
		BCM_Hub::init(); // Signed requests from the BCM Hub (one REST route, inert without a valid signature).
		add_action( 'wp_abilities_api_categories_init', 'bcm_agency_register_category' );
		add_action( 'wp_abilities_api_init', 'bcm_agency_register_media_abilities' );
		add_action( 'wp_abilities_api_init', 'bcm_agency_register_post_abilities' );
		add_action( 'wp_abilities_api_init', 'bcm_agency_register_site_abilities' );
		add_action( 'wp_abilities_api_init', 'bcm_agency_register_term_abilities' );
		add_action( 'wp_abilities_api_init', 'bcm_agency_register_tracking_abilities' );
		add_action( 'wp_abilities_api_init', 'bcm_agency_register_replace_abilities' );
		add_action( 'wp_abilities_api_init', 'bcm_agency_register_form_abilities' );
		add_action( 'wp_abilities_api_init', 'bcm_agency_register_image_abilities' );
		add_action( 'wp_abilities_api_init', 'bcm_agency_register_gallery_abilities' );
		add_action( 'wp_abilities_api_init', 'bcm_agency_register_list_abilities' );
		add_action( 'wp_abilities_api_init', 'bcm_agency_register_menu_abilities' );
		add_action( 'wp_abilities_api_init', 'bcm_agency_register_update_abilities' );
		add_action( 'wp_abilities_api_init', 'bcm_agency_register_fix_abilities' );
	}
);

function bcm_agency_register_category() {
	wp_register_ability_category(
		'bcm-agency',
		array(
			'label'       => 'BCM Agency',
			'description' => 'Blue Collar Marketing agency tools: media, blog posts and site identity.',
		)
	);
}

/*
 * Self-updates from our own release feed (Plugin Update Checker v5.6).
 * If the feed is unreachable, nothing breaks: the site just sees no update.
 */
add_action(
	'plugins_loaded',
	function () {
		$puc = BCM_AGENCY_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
		if ( ! file_exists( $puc ) || ! BCM_AGENCY_UPDATE_URL ) {
			return;
		}
		require_once $puc;
		if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
			// Check every 2 hours so a release reaches sites quickly; Cloudways rollouts follow.
			$GLOBALS['bcm_agency_update_checker'] = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
				BCM_AGENCY_UPDATE_URL,
				BCM_AGENCY_FILE,
				'bcm-agency',
				2
			);
		}
	},
	20
);
