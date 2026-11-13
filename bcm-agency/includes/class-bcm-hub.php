<?php
defined( 'ABSPATH' ) || exit;

/**
 * BCM Hub: signed requests from Blue Collar Marketing's hub.
 *
 * One REST route, POST /wp-json/bcm-agency/v1/hub. It does nothing unless the
 * request is signed with a key listed in hub-keys.php. Every other route,
 * and normal WordPress sign-in, is untouched.
 *
 * Checks, in this order (any failure refuses the request and is logged):
 *   1. Hub access is switched on for this site.
 *   2. The key ID is trusted.
 *   3. The Ed25519 signature is valid.
 *   4. The time is within 5 minutes of this site's clock.
 *   5. The nonce has not been seen before (kept 15 minutes).
 *   6. The host the request was signed for is this site.
 *   7. The ability is in an allowed family, not excluded, its input passes the
 *      content scan, and second-confirmation abilities carry a valid approval.
 * Then the ability runs as the hub's administrator account for this request
 * only, without unfiltered HTML and without plugin, theme or user management.
 *
 * Wire format: docs/HUB-PROTOCOL.md in the repo.
 */
final class BCM_Hub {

	const ROUTE_NAMESPACE = 'bcm-agency/v1';
	const ROUTE_PATH      = '/hub';
	const PROTOCOL        = 'BCM-HUB-V1';
	const APPROVAL        = 'BCM-HUB-APPROVAL-V1';
	const MAX_SKEW        = 300;     // Seconds either side of this site's clock.
	const NONCE_TTL       = 900;     // Longer than the 2 x MAX_SKEW window a nonce could be replayed in.
	const MAX_BODY        = 1048576; // 1 MB.

	const OPT_ENABLED = 'bcm_agency_hub_enabled';
	const OPT_USER    = 'bcm_agency_hub_user_id';
	const OPT_LAST    = 'bcm_agency_hub_last_contact';

	/** Capabilities the hub's administrator does NOT have during a hub request. */
	const REMOVED_CAPS = array(
		'unfiltered_html', 'unfiltered_upload',
		'edit_plugins', 'edit_themes', 'edit_files',
		'install_plugins', 'install_themes', 'activate_plugins', 'delete_plugins', 'delete_themes',
		'update_core', 'update_themes', 'resume_plugins', 'resume_themes',
		'create_users', 'edit_users', 'delete_users', 'promote_users', 'remove_users', 'add_users', 'list_users',
		'export', 'import', 'delete_site', 'manage_network', 'manage_sites', 'manage_network_users',
		'manage_network_plugins', 'manage_network_themes', 'manage_network_options', 'upgrade_network',
		'setup_network', 'install_languages', 'update_languages',
	);

	/** Context of the request that passed every check (null otherwise). */
	private static $context = null;

	/** Set while an ability runs on behalf of the hub. */
	private static $running = false;

	/** Refusal details for the log, set by verify(). */
	private static $refusal = array();

	private static $keys = null;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	public static function register_route() {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_PATH,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'authorize' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	/** Hub access on/off. On unless switched off here or in wp-config.php. */
	public static function enabled() {
		if ( defined( 'BCM_AGENCY_HUB_DISABLED' ) && BCM_AGENCY_HUB_DISABLED ) {
			return false;
		}
		return '0' !== (string) get_option( self::OPT_ENABLED, '1' );
	}

	public static function forced_off() {
		return defined( 'BCM_AGENCY_HUB_DISABLED' ) && BCM_AGENCY_HUB_DISABLED;
	}

	/**
	 * The administrator account hub requests run as: the one chosen on the
	 * settings screen, or (automatic) the oldest administrator. 0 if none is
	 * usable, which refuses the request rather than falling back to someone else.
	 */
	public static function hub_user_id() {
		$chosen = (int) get_option( self::OPT_USER, 0 );
		if ( $chosen > 0 ) {
			$user = get_userdata( $chosen );
			return ( $user && user_can( $user, 'manage_options' ) ) ? (int) $user->ID : 0;
		}
		$ids = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 5,
				'fields'  => 'ID',
			)
		);
		foreach ( (array) $ids as $id ) {
			if ( user_can( (int) $id, 'manage_options' ) ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * Trusted public keys from hub-keys.php, decoded. Placeholders and
	 * malformed entries are ignored.
	 *
	 * @param string $list 'signing' or 'approval'.
	 * @return array<string,string> key ID => 32-byte public key.
	 */
	public static function trusted_keys( $list ) {
		if ( null === self::$keys ) {
			$raw        = include BCM_AGENCY_DIR . 'includes/hub-keys.php';
			self::$keys = array(
				'signing'  => array(),
				'approval' => array(),
			);
			foreach ( array( 'signing', 'approval' ) as $l ) {
				if ( empty( $raw[ $l ] ) || ! is_array( $raw[ $l ] ) ) {
					continue;
				}
				foreach ( $raw[ $l ] as $id => $b64 ) {
					$bin = self::b64url_decode( (string) $b64 );
					if ( is_string( $id ) && self::valid_key_id( $id ) && false !== $bin && 32 === strlen( $bin ) ) {
						self::$keys[ $l ][ $id ] = $bin;
					}
				}
			}
		}
		return isset( self::$keys[ $list ] ) ? self::$keys[ $list ] : array();
	}

	/* ------------------------------------------------------------------ */
	/* Checks                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * REST permission callback: runs every check. Only a request that passes
	 * all of them reaches handle().
	 */
	public static function authorize( $request ) {
		self::$context = null;
		self::$refusal = array();

		$h = self::headers( $request );
		if ( '' === $h['key'] && '' === $h['signature'] && '' === $h['nonce'] ) {
			// Not a hub request at all: say so, don't log (anyone can send these).
			return self::error( 'bcm_hub_unsigned', 'This address only accepts signed requests from the BCM Hub.', 401 );
		}

		$result = self::verify( $request, $h );
		if ( is_wp_error( $result ) ) {
			self::log_refusal( $result );
			return $result;
		}

		$result['request_id'] = spl_object_id( $request );
		self::$context        = $result;
		return true;
	}

	/**
	 * @return array|WP_Error Verified request context, or the refusal.
	 */
	private static function verify( $request, array $h ) {
		// 1. Hub access switched on.
		if ( ! self::enabled() ) {
			return self::error( 'bcm_hub_disabled', 'Hub access is switched off on this site.', 403 );
		}

		// 2. Trusted key.
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return self::error( 'bcm_hub_unavailable', 'This site cannot check signatures (PHP sodium is missing).', 500 );
		}
		$keys = self::trusted_keys( 'signing' );
		if ( ! self::valid_key_id( $h['key'] ) || ! isset( $keys[ $h['key'] ] ) ) {
			return self::error( 'bcm_hub_unknown_key', 'The signing key is not trusted by this site.', 401 );
		}

		// 3. Signature.
		$sig = self::b64url_decode( $h['signature'] );
		if ( ! preg_match( '/^\d{9,11}$/', $h['time'] )
			|| ! preg_match( '/^[A-Za-z0-9_-]{22,128}$/', $h['nonce'] )
			|| ! preg_match( '/^[A-Za-z0-9_-]{2,64}$/', $h['actor'] )
			|| false === $sig || 64 !== strlen( $sig ) ) {
			return self::error( 'bcm_hub_bad_request', 'The hub headers are missing or malformed.', 400 );
		}
		$body = (string) $request->get_body();
		if ( strlen( $body ) > self::MAX_BODY ) {
			return self::error( 'bcm_hub_too_large', 'The request is too large.', 413 );
		}
		$host = self::request_host( $request );
		if ( '' === $host ) {
			return self::error( 'bcm_hub_bad_request', 'The request has no usable Host header.', 400 );
		}
		$body_hash = hash( 'sha256', $body );
		$message   = self::message( strtoupper( (string) $request->get_method() ), $host, (string) $request->get_route(), $h['key'], $h['time'], $h['nonce'], $h['actor'], $body_hash );
		if ( ! self::verify_detached( $sig, $message, $keys[ $h['key'] ] ) ) {
			return self::error( 'bcm_hub_bad_signature', 'The signature is not valid.', 401 );
		}

		// From here on the request really was signed by the hub.
		self::$refusal['actor'] = $h['actor'];

		// 4. Time window.
		if ( abs( time() - (int) $h['time'] ) > self::MAX_SKEW ) {
			return self::error( 'bcm_hub_expired', 'The request is too old, or this site\'s clock is off by more than 5 minutes.', 401, array( 'site_time' => time() ) );
		}

		// 5. Nonce never seen before.
		$fresh = self::consume_nonce( $h['nonce'] );
		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		// 6. Signed for this site.
		$own = self::own_hosts();
		if ( ! in_array( $host, $own, true ) ) {
			return self::error( 'bcm_hub_wrong_host', 'This request was signed for a different site.', 403, array( 'home_host' => $own[0] ) );
		}

		// 7. What it asks for.
		$payload = self::parse_body( $body );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		self::$refusal['action']  = $payload['action'];
		self::$refusal['ability'] = $payload['ability'];

		$context = array(
			'actor'     => $h['actor'],
			'key'       => $h['key'],
			'nonce'     => $h['nonce'],
			'host'      => $host,
			'action'    => $payload['action'],
			'ability'   => $payload['ability'],
			'input'     => $payload['input'],
			'tier'      => null,
			'readonly'  => null,
			'confirmed' => false,
			'approved'  => self::approved_flag( $payload['input'] ),
			'user_id'   => 0,
		);

		if ( 'ping' === $payload['action'] || 'list' === $payload['action'] ) {
			return $context;
		}

		$name = $payload['ability'];
		if ( ! BCM_Hub_Policy::in_family( $name ) ) {
			return self::error( 'bcm_hub_not_allowed', 'Only bcm/, elementor/ and yoast-seo/ abilities can be used through the hub.', 403 );
		}
		$ability = ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) ? wp_get_ability( $name ) : null;
		if ( ! $ability ) {
			return self::error( 'bcm_hub_not_found', 'This site has no ability called ' . $name . '.', 404 );
		}
		$readonly = self::readonly( $ability );
		$tier     = BCM_Hub_Policy::tier( $name, $readonly );
		if ( BCM_Hub_Policy::EXCLUDED === $tier ) {
			return self::error( 'bcm_hub_excluded', $name . ' is not available through the hub. Use the site\'s own connector or wp-admin.', 403 );
		}
		$context['readonly'] = $readonly;
		$context['tier']     = $tier;

		if ( 'info' === $payload['action'] ) {
			return $context;
		}

		// run: scan what it would write.
		if ( true !== $readonly && ! in_array( $name, BCM_Hub_Policy::SCAN_EXEMPT_ABILITIES, true ) ) {
			$scan = BCM_Hub_Policy::scan( $payload['input'] );
			$tier = BCM_Hub_Policy::stricter( $tier, $scan['tier'] );
			if ( BCM_Hub_Policy::EXCLUDED === $tier ) {
				return self::error( 'bcm_hub_excluded', 'This change is not allowed through the hub (' . $scan['reason'] . '). Code, scripts and custom CSS are changed in wp-admin by a developer.', 403 );
			}
			$context['tier'] = $tier;
		}

		if ( BCM_Hub_Policy::CONFIRM === $context['tier'] ) {
			if ( isset( $scan ) && '' !== $scan['reason'] ) {
				$why = $scan['reason'];
			} elseif ( 'bcm/update-form-email' === $name ) {
				$why = 'it changes where a form sends its emails';
			} elseif ( 'bcm/add-tracking-code' === $name ) {
				$why = 'it adds tracking code to every page of the site';
			} elseif ( 'elementor/list-form-submissions' === $name ) {
				$why = "it reads the client's form submissions (personal data)";
			} elseif ( in_array( $name, BCM_Hub_Policy::CONFIRM_ABILITIES, true ) ) {
				$why = 'it changes a site-wide setting';
			} else {
				$why = 'it is a new ability the hub has not reviewed yet';
			}
			$approval = self::verify_approval( $h, $host, $body_hash, $why );
			if ( is_wp_error( $approval ) ) {
				return $approval;
			}
			$context['confirmed'] = true;
		}

		$context['user_id'] = self::hub_user_id();
		if ( ! $context['user_id'] ) {
			return self::error( 'bcm_hub_no_user', 'This site has no administrator account the hub can act as. Check Tools → BCM Activity Log → Hub access.', 500 );
		}
		return $context;
	}

	/**
	 * Second confirmation: a separate signature from the approval key over this
	 * exact request (same nonce, actor, host and body).
	 */
	private static function verify_approval( array $h, $host, $body_hash, $why ) {
		if ( '' === $h['approval_key'] && '' === $h['approval'] ) {
			return self::error( 'bcm_hub_needs_confirmation', 'This change needs a second confirmation before it can run.', 403, array( 'reason' => $why ) );
		}
		$keys = self::trusted_keys( 'approval' );
		$sig  = self::b64url_decode( $h['approval'] );
		if ( ! self::valid_key_id( $h['approval_key'] ) || ! isset( $keys[ $h['approval_key'] ] )
			|| ! preg_match( '/^\d{9,11}$/', $h['approval_time'] )
			|| false === $sig || 64 !== strlen( $sig ) ) {
			return self::error( 'bcm_hub_bad_approval', 'The second confirmation is missing or not trusted.', 401 );
		}
		$message = implode( "\n", array( self::APPROVAL, $h['approval_key'], $host, $h['nonce'], $h['actor'], $h['approval_time'], $body_hash ) );
		if ( ! self::verify_detached( $sig, $message, $keys[ $h['approval_key'] ] ) ) {
			return self::error( 'bcm_hub_bad_approval', 'The second confirmation signature is not valid.', 401 );
		}
		if ( abs( time() - (int) $h['approval_time'] ) > self::MAX_SKEW ) {
			return self::error( 'bcm_hub_bad_approval', 'The second confirmation has expired.', 401 );
		}
		return true;
	}

	/**
	 * The exact bytes the hub signs. See docs/HUB-PROTOCOL.md.
	 */
	public static function message( $method, $host, $route, $key_id, $time, $nonce, $actor, $body_sha256_hex ) {
		return implode( "\n", array( self::PROTOCOL, $method, $host, $route, $key_id, $time, $nonce, $actor, $body_sha256_hex ) );
	}

	private static function verify_detached( $sig, $message, $public_key ) {
		try {
			return true === sodium_crypto_sign_verify_detached( $sig, $message, $public_key );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Records the nonce; refuses if it was already recorded. The table's
	 * primary key makes this atomic, so two copies arriving at once can't both pass.
	 */
	private static function consume_nonce( $nonce ) {
		global $wpdb;
		$table = BCM_Log::nonce_table();
		$now   = time();

		if ( 1 === wp_rand( 1, 20 ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE seen < %d", $now - self::NONCE_TTL ) ); // phpcs:ignore WordPress.DB
		}

		$suppress = $wpdb->suppress_errors( true );
		$sql      = $wpdb->prepare( "INSERT INTO {$table} (nonce, seen) VALUES (%s, %d)", $nonce, $now ); // phpcs:ignore WordPress.DB
		$inserted = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB
		$error    = (string) $wpdb->last_error;
		if ( 1 !== $inserted && false === stripos( $error, 'duplicate' ) ) {
			BCM_Log::install(); // Table missing (e.g. never upgraded): create it and try once more.
			$inserted = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB
			$error    = (string) $wpdb->last_error;
		}
		$wpdb->suppress_errors( $suppress );

		if ( 1 === $inserted ) {
			return true;
		}
		if ( false !== stripos( $error, 'duplicate' ) ) {
			return self::error( 'bcm_hub_replay', 'This request has already been used.', 401 );
		}
		return self::error( 'bcm_hub_storage', 'This site could not record the request, so it was refused.', 500 );
	}

	/**
	 * Strict body: {"action": ping|list|info|run, "ability"?: "...", "input"?: {...}}.
	 */
	private static function parse_body( $body ) {
		$bad  = self::error( 'bcm_hub_bad_request', 'The request body is not a valid hub request.', 400 );
		$data = json_decode( $body, true, 64 );
		if ( ! is_array( $data ) || ( array() !== $data && array_keys( $data ) === range( 0, count( $data ) - 1 ) ) ) {
			return $bad;
		}
		if ( array_diff( array_keys( $data ), array( 'action', 'ability', 'input' ) ) ) {
			return $bad;
		}
		$action = isset( $data['action'] ) ? $data['action'] : null;
		if ( ! in_array( $action, array( 'ping', 'list', 'info', 'run' ), true ) ) {
			return $bad;
		}
		$ability = isset( $data['ability'] ) ? $data['ability'] : null;
		$input   = array_key_exists( 'input', $data ) ? $data['input'] : null;
		if ( in_array( $action, array( 'info', 'run' ), true ) ) {
			if ( ! is_string( $ability ) || ! preg_match( '#^[a-z0-9-]{1,50}/[a-z0-9-]{1,80}$#', $ability ) ) {
				return $bad;
			}
		} elseif ( null !== $ability ) {
			return $bad;
		}
		if ( 'run' !== $action && null !== $input ) {
			return $bad;
		}
		if ( null !== $input && ! is_array( $input ) ) {
			return $bad; // Input is an object (or omitted).
		}
		return array(
			'action'  => $action,
			'ability' => $ability,
			'input'   => $input,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Handling                                                            */
	/* ------------------------------------------------------------------ */

	public static function handle( $request ) {
		$ctx = self::$context;
		if ( ! $ctx || spl_object_id( $request ) !== $ctx['request_id'] ) {
			return self::error( 'bcm_hub_unsigned', 'This address only accepts signed requests from the BCM Hub.', 401 );
		}
		self::$context = null; // One request, one use.
		self::touch_last_contact();

		switch ( $ctx['action'] ) {
			case 'ping':
				return rest_ensure_response( self::ping() );
			case 'list':
				return rest_ensure_response( array( 'ok' => true, 'abilities' => self::list_abilities() ) );
			case 'info':
				return rest_ensure_response( array( 'ok' => true, 'ability' => self::ability_info( $ctx ) ) );
			default:
				return self::run( $ctx );
		}
	}

	private static function ping() {
		$families = array_fill_keys( BCM_Hub_Policy::FAMILIES, 0 );
		foreach ( self::list_abilities() as $a ) {
			$families[ strtok( $a['name'], '/' ) ]++;
		}
		$hosts = self::own_hosts();
		return array(
			'ok'              => true,
			'protocol'        => 1,
			'plugin_version'  => BCM_AGENCY_VERSION,
			'wp_version'      => get_bloginfo( 'version' ),
			'php_version'     => PHP_VERSION,
			'home_host'       => $hosts[0],
			'site_time'       => time(),
			'hub_user_ready'  => self::hub_user_id() > 0,
			'abilities'       => $families,
		);
	}

	private static function list_abilities() {
		$out = array();
		$all = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();
		foreach ( $all as $ability ) {
			$name = $ability->get_name();
			$tier = BCM_Hub_Policy::tier( $name, self::readonly( $ability ) );
			if ( null === $tier || BCM_Hub_Policy::EXCLUDED === $tier ) {
				continue;
			}
			$out[] = array(
				'name'        => $name,
				'label'       => $ability->get_label(),
				'description' => $ability->get_description(),
				'readonly'    => true === self::readonly( $ability ),
				'tier'        => $tier,
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( $a['name'], $b['name'] );
			}
		);
		return $out;
	}

	private static function ability_info( array $ctx ) {
		$ability = wp_get_ability( $ctx['ability'] );
		return array(
			'name'          => $ability->get_name(),
			'label'         => $ability->get_label(),
			'description'   => $ability->get_description(),
			'input_schema'  => $ability->get_input_schema(),
			'output_schema' => $ability->get_output_schema(),
			'readonly'      => true === $ctx['readonly'],
			'tier'          => $ctx['tier'],
		);
	}

	private static function run( array $ctx ) {
		$ability = wp_get_ability( $ctx['ability'] );
		$input   = $ctx['input'];
		if ( array() === $ability->get_input_schema() && ( null === $input || array() === $input ) ) {
			$input = null; // Abilities without an input schema refuse any input, even {}.
		}

		self::begin( $ctx );
		try {
			$result = $ability->execute( $input );
		} catch ( Throwable $e ) {
			$result = new WP_Error( 'bcm_exception', 'Unexpected error: ' . $e->getMessage() );
		}
		// bcm/ abilities log themselves (with before/after); log the others' writes here.
		if ( 0 !== strpos( $ctx['ability'], 'bcm/' ) && true !== $ctx['readonly'] ) {
			$summary = array( 'input_keys' => is_array( $input ) ? array_slice( array_map( 'strval', array_keys( $input ) ), 0, 20 ) : array() );
			if ( is_wp_error( $result ) ) {
				$summary['error'] = $result->get_error_message();
			}
			BCM_Log::add( $ctx['ability'], is_array( $input ) ? bcm_agency_guess_object_id( $input ) : 0, is_wp_error( $result ) ? 'error' : 'ok', $summary );
		}
		self::end();

		if ( is_wp_error( $result ) ) {
			// Keep the code (e.g. bcm_needs_approval) so Claude knows to ask; drop internal data.
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response(
			array(
				'ok'      => true,
				'ability' => $ctx['ability'],
				'result'  => $result,
			)
		);
	}

	/**
	 * Act as the hub's administrator for this request only, with the
	 * capabilities in REMOVED_CAPS taken away. The filter is added before the
	 * user is switched so WordPress's content filters (kses) switch on.
	 */
	private static function begin( array $ctx ) {
		self::$running = $ctx;
		add_filter( 'user_has_cap', array( __CLASS__, 'limit_caps' ), 9999, 4 );
		wp_set_current_user( $ctx['user_id'] );
	}

	private static function end() {
		wp_set_current_user( 0 );
		remove_filter( 'user_has_cap', array( __CLASS__, 'limit_caps' ), 9999 );
		self::$running = false;
	}

	public static function limit_caps( $allcaps, $caps, $args, $user ) {
		if ( self::$running && $user && (int) $user->ID === (int) self::$running['user_id'] ) {
			foreach ( self::REMOVED_CAPS as $cap ) {
				$allcaps[ $cap ] = false;
			}
		}
		return $allcaps;
	}

	/**
	 * For BCM_Log: who asked, while an ability runs for the hub.
	 *
	 * @return array|null
	 */
	public static function log_context() {
		if ( ! self::$running ) {
			return null;
		}
		return array(
			'via'                 => 'hub',
			'actor'               => self::$running['actor'],
			'approved'            => self::$running['approved'],
			'second_confirmation' => self::$running['confirmed'],
		);
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	private static function headers( $request ) {
		$get = function ( $name ) use ( $request ) {
			$v = $request->get_header( $name );
			return is_string( $v ) ? trim( $v ) : '';
		};
		return array(
			'key'           => $get( 'x-bcm-hub-key' ),
			'time'          => $get( 'x-bcm-hub-time' ),
			'nonce'         => $get( 'x-bcm-hub-nonce' ),
			'actor'         => $get( 'x-bcm-hub-actor' ),
			'signature'     => $get( 'x-bcm-hub-signature' ),
			'approval_key'  => $get( 'x-bcm-hub-approval-key' ),
			'approval_time' => $get( 'x-bcm-hub-approval-time' ),
			'approval'      => $get( 'x-bcm-hub-approval' ),
		);
	}

	/** Host the request was sent to, lower case, without port. */
	private static function request_host( $request ) {
		$host = $request->get_header( 'host' );
		if ( ! is_string( $host ) || '' === $host ) {
			$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : ''; // phpcs:ignore WordPress.Security
		}
		$host = rtrim( strtolower( trim( $host ) ), '.' );
		$host = preg_replace( '/:\d+$/', '', $host );
		return preg_match( '/^[a-z0-9.-]{1,253}$/', $host ) ? $host : '';
	}

	/**
	 * This site's own hosts: home and site URL, with and without www.
	 * The first entry is the home host.
	 */
	public static function own_hosts() {
		$hosts = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$h = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' === $h ) {
				continue;
			}
			$hosts[] = $h;
			$hosts[] = 0 === strpos( $h, 'www.' ) ? substr( $h, 4 ) : 'www.' . $h;
		}
		$hosts = apply_filters( 'bcm_agency_hub_hosts', $hosts );
		return array_values( array_unique( array_filter( array_map( 'strval', (array) $hosts ) ) ) );
	}

	/**
	 * true, false, or null (unknown). An explicit readonly annotation wins.
	 * Abilities served as MCP resources (reference documents such as Elementor's
	 * global classes list or style guides) only return content, so they count
	 * as read-only even when their plugin leaves the annotation unset.
	 */
	private static function readonly( $ability ) {
		$annotations = $ability->get_meta_item( 'annotations', array() );
		if ( is_array( $annotations ) && isset( $annotations['readonly'] ) ) {
			return (bool) $annotations['readonly'];
		}
		$mcp = $ability->get_meta_item( 'mcp', array() );
		if ( is_array( $mcp ) && isset( $mcp['type'] ) && 'resource' === $mcp['type'] ) {
			return true;
		}
		return null;
	}

	private static function approved_flag( $input ) {
		return is_array( $input ) && ( ( isset( $input['approved'] ) && true === $input['approved'] ) || ( isset( $input['live_edit_approved'] ) && true === $input['live_edit_approved'] ) );
	}

	private static function valid_key_id( $id ) {
		return is_string( $id ) && (bool) preg_match( '/^[A-Za-z0-9._-]{1,40}$/', $id );
	}

	public static function b64url_decode( $s ) {
		if ( ! is_string( $s ) || '' === $s || ! preg_match( '/^[A-Za-z0-9_-]+$/', $s ) ) {
			return false;
		}
		$pad = strlen( $s ) % 4;
		if ( 1 === $pad ) {
			return false;
		}
		if ( $pad ) {
			$s .= str_repeat( '=', 4 - $pad );
		}
		return base64_decode( strtr( $s, '-_', '+/' ), true );
	}

	private static function error( $code, $message, $status, array $extra = array() ) {
		return new WP_Error( $code, $message, array_merge( array( 'status' => $status ), $extra ) );
	}

	private static function touch_last_contact() {
		if ( time() - (int) get_option( self::OPT_LAST, 0 ) >= 60 ) {
			update_option( self::OPT_LAST, time(), false );
		}
	}

	/**
	 * Logs a refused hub request. Capped at 50 entries per 10 minutes so
	 * junk requests can't flood the log; the actor is recorded only when the
	 * signature checked out.
	 */
	private static function log_refusal( WP_Error $error ) {
		$count = (int) get_transient( 'bcm_agency_hub_refusals' );
		if ( $count >= 50 ) {
			return;
		}
		set_transient( 'bcm_agency_hub_refusals', $count + 1, 10 * MINUTE_IN_SECONDS );

		$r       = self::$refusal;
		$ability = ! empty( $r['ability'] ) ? $r['ability'] : ( ! empty( $r['action'] ) ? 'hub/' . $r['action'] : 'hub/request' );
		BCM_Log::add(
			$ability,
			0,
			'error',
			array( 'hub_refused' => $error->get_error_code() ),
			array(
				'via'   => 'hub',
				'actor' => isset( $r['actor'] ) ? $r['actor'] : '',
			)
		);
	}
}
