<?php
defined( 'ABSPATH' ) || exit;

/**
 * Which abilities the BCM Hub may run, and what a request may contain.
 *
 * The hub enforces the same lists on its side; this copy is the one that
 * holds even if the hub's signing key were stolen. Everything here fails
 * closed: unknown families are refused, unreviewed write abilities need a
 * second confirmation, and anything that looks like code is refused.
 *
 * Tiers, from least to most restricted:
 *   allowed  - runs normally (approval rules inside each ability still apply).
 *   confirm  - needs a second confirmation signed by the approval key
 *              (Chris approves in Slack; see docs/HUB-PROTOCOL.md).
 *   excluded - never runs through the hub. Use the site's own connector.
 */
final class BCM_Hub_Policy {

	const ALLOWED  = 'allowed';
	const CONFIRM  = 'confirm';
	const EXCLUDED = 'excluded';

	/** Ability families the hub may reach at all. */
	const FAMILIES = array( 'bcm', 'elementor', 'yoast-seo' );

	/**
	 * Never through the hub.
	 */
	const EXCLUDED_ABILITIES = array(
		// Changing clients' leads (reading them needs a second confirmation).
		'elementor/manage-form-submissions',
		// Developer-level switches that can break a site's editor.
		'elementor/manage-experiments',
		'elementor/enable-agent-ready',
	);

	/**
	 * Need a second confirmation outside Claude.
	 */
	const CONFIRM_ABILITIES = array(
		// Where contact forms send.
		'bcm/update-form-email',
		// Code on every page (official code for known services only; the plugin
		// builds it from the ID and never uses pasted markup).
		'bcm/add-tracking-code',
		// Clients' leads: personal data.
		'elementor/list-form-submissions',
		// Site-wide settings.
		'bcm/set-homepage',
		'bcm/update-site-identity',
		'bcm/set-atomic-image-fit',
		'elementor/manage-global-variable',
		'elementor/manage-woocommerce-settings',
		'yoast-seo/set-llms-txt-status',
		'yoast-seo/set-open-graph-status',
		'yoast-seo/set-schema-aggregation-endpoint-status',
		'yoast-seo/set-schema-framework-status',
		'yoast-seo/set-x-card-status',
		'yoast-seo/set-xml-sitemap-status',
		'yoast-seo/update-site-representation',
	);

	/**
	 * Skipped by the content scan. bcm/add-tracking-code accepts a pasted provider
	 * snippet only to read the ID out of it, then writes the official code itself.
	 */
	const SCAN_EXEMPT_ABILITIES = array( 'bcm/add-tracking-code' );

	/**
	 * Reviewed and allowed. Write abilities here still have their input scanned.
	 * An ability in an allowed family that is not on any list is "unreviewed":
	 * allowed if it declares itself read-only, otherwise it needs confirmation.
	 */
	const ALLOWED_ABILITIES = array(
		// bcm/ (everything not excluded or confirmed above).
		'bcm/add-gallery-image',
		'bcm/add-gallery-tab',
		'bcm/add-list-item',
		'bcm/add-menu-item',
		'bcm/check-for-updates',
		'bcm/create-post',
		'bcm/create-term',
		'bcm/find-images',
		'bcm/find-text',
		'bcm/get-activity-log',
		'bcm/get-atomic-image-fit',
		'bcm/get-post',
		'bcm/get-site-identity',
		'bcm/list-categories',
		'bcm/list-forms',
		'bcm/list-items',
		'bcm/list-media',
		'bcm/list-menus',
		'bcm/list-posts',
		'bcm/list-tracking-codes',
		'bcm/publish-post',
		'bcm/purge-page-cache',
		'bcm/remove-gallery-image',
		'bcm/remove-list-item',
		'bcm/remove-menu-item',
		'bcm/remove-tracking-code',
		'bcm/replace-image',
		'bcm/replace-text',
		'bcm/schedule-post',
		'bcm/set-page-layout',
		'bcm/unpublish-post',
		'bcm/update-image-alt',
		'bcm/update-menu-item',
		'bcm/update-post',
		'bcm/upload-media',
		// elementor/ reads.
		'elementor/agent-ready-scan',
		'elementor/create-preview-link',
		'elementor/find-icons',
		'elementor/get-default-styles',
		'elementor/get-license-status',
		'elementor/get-page-structure',
		'elementor/get-system-info',
		'elementor/get-widget-schemas',
		'elementor/list-assets',
		'elementor/list-components',
		'elementor/list-experiments',
		'elementor/list-posts',
		'elementor/list-resources',
		'elementor/list-site-parts',
		'elementor/list-widget-schemas',
		'elementor/read-resource',
		// elementor/ writes (input scanned). Site parts: Pro "Custom Code" snippets are
		// refused by the scan (type code_snippet). Uploads: SVG only where the site
		// turned on Elementor's unfiltered file uploads. Global classes / default
		// styles: site-wide CSS, no scripts (the scan refuses scripted CSS).
		'elementor/build-composition',
		'elementor/clear-cache',
		'elementor/create-page',
		'elementor/manage-component',
		'elementor/manage-elements',
		'elementor/manage-classes',
		'elementor/manage-default-styles',
		'elementor/manage-revisions',
		'elementor/manage-site-parts',
		'elementor/publish-document',
		'elementor/reorder-classes',
		'elementor/request-media-upload',
		'elementor/update-page-settings',
		'elementor/upload-media',
		// yoast-seo/.
		'yoast-seo/get-post-seo-data',
		'yoast-seo/get-readability-scores',
		'yoast-seo/get-seo-scores',
		'yoast-seo/update-post-seo-data',
	);

	/**
	 * Widget, element or dynamic-tag types that carry code. Matched as a whole
	 * word inside the type (html, e-html, code-highlight, shortcode, custom_html...).
	 */
	const CODE_TYPE_RE = '/(^|[-_])(html|code|codes|script|scripts|shortcode|shortcodes|snippet|snippets|css|js|javascript|iframe)([-_]|$)/i';

	/** HTML tags refused anywhere in a write request. */
	const DANGEROUS_TAGS = array(
		'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
		'base', 'meta', 'link', 'form', 'input', 'button', 'textarea', 'select',
		'svg', 'math', 'template', 'noscript', 'portal', 'xmp', 'plaintext', 'param',
	);

	/**
	 * Field names that hold code or custom attributes (compared after lower-casing,
	 * removing leading underscores and turning "-" into "_"). Elementor's Custom
	 * Attributes can add onclick= handlers, so they count as code.
	 */
	const CODE_FIELDS = array(
		'custom_css', 'custom_js', 'custom_code', 'custom_html', 'css_code', 'html_code',
		'header_code', 'footer_code', 'head_code', 'body_code', 'html', 'script', 'scripts',
		'javascript', 'js', 'code', 'srcdoc', 'attributes', 'custom_attributes',
		'element_custom_attributes',
	);

	/** Field names whose value names another field type (e.g. {"key": "custom_css"}). */
	const CODE_FIELD_VALUES = array(
		'custom_css', 'custom_js', 'custom_code', 'custom_html', 'css_code', 'html_code',
		'header_code', 'footer_code', 'head_code', 'body_code', 'custom_attributes',
		'element_custom_attributes',
	);

	/** Keys whose value is a widget, element or tag type. */
	const TYPE_FIELDS = array( 'widgettype', 'widget_type', 'eltype', 'el_type', 'element_type', 'widget', 'type' );

	/**
	 * Fields that decide where a form's submissions go (Elementor V3 email
	 * actions, V4 e-form email / webhook_url / redirect_url / actions-after-submit).
	 * Changing them needs the same second confirmation as bcm/update-form-email.
	 */
	const FORM_DESTINATION_RE = '/^(email|emails|email_to(_\w+)?|email_(cc|bcc)(_\w+)?|email_from(_\w+)?|email_reply_to(_\w+)?|webhook|webhooks|webhook_url|webhooks_url|webhooks_advanced_data|redirect_url|redirect_to|actions_after_submit|submit_actions)$/';

	/** Nesting deeper than this is refused rather than half-scanned. */
	const MAX_DEPTH = 40;

	/**
	 * Is the name syntactically an ability in an allowed family?
	 */
	public static function in_family( $name ) {
		if ( ! is_string( $name ) || ! preg_match( '#^([a-z0-9-]+)/[a-z0-9-]+$#', $name, $m ) ) {
			return false;
		}
		return in_array( $m[1], self::FAMILIES, true );
	}

	/**
	 * Tier for an ability by name alone.
	 *
	 * @param string    $name     Ability name.
	 * @param bool|null $readonly The ability's readonly annotation.
	 * @return string|null allowed / confirm / excluded, or null if outside the allowed families.
	 */
	public static function tier( $name, $readonly ) {
		if ( ! self::in_family( $name ) ) {
			return null;
		}
		if ( in_array( $name, self::EXCLUDED_ABILITIES, true ) ) {
			return self::EXCLUDED;
		}
		if ( in_array( $name, self::CONFIRM_ABILITIES, true ) ) {
			return self::CONFIRM;
		}
		if ( in_array( $name, self::ALLOWED_ABILITIES, true ) ) {
			return self::ALLOWED;
		}
		return true === $readonly ? self::ALLOWED : self::CONFIRM;
	}

	/**
	 * The stricter of two tiers.
	 */
	public static function stricter( $a, $b ) {
		$rank = array( self::ALLOWED => 0, self::CONFIRM => 1, self::EXCLUDED => 2 );
		return $rank[ $b ] > $rank[ $a ] ? $b : $a;
	}

	/**
	 * Scans the input of a write request.
	 *
	 * @param mixed $input Decoded JSON input.
	 * @return array{tier:string,reason:string} Tier the content requires, with the first reason found.
	 */
	public static function scan( $input ) {
		$state = array(
			'tier'   => self::ALLOWED,
			'reason' => '',
		);
		self::walk( $input, '', 0, $state );
		return $state;
	}

	private static function flag( array &$state, $tier, $reason ) {
		if ( self::stricter( $state['tier'], $tier ) !== $state['tier'] ) {
			$state['tier']   = $tier;
			$state['reason'] = $reason;
		}
	}

	private static function normalize_key( $key ) {
		return str_replace( '-', '_', ltrim( strtolower( (string) $key ), '_' ) );
	}

	private static function walk( $value, $path, $depth, array &$state ) {
		if ( self::EXCLUDED === $state['tier'] ) {
			return; // Nothing can make it stricter.
		}
		if ( $depth > self::MAX_DEPTH ) {
			self::flag( $state, self::EXCLUDED, 'input nested too deeply' );
			return;
		}
		if ( is_array( $value ) ) {
			// A dynamic tag: { name, settings }.
			if ( isset( $value['name'] ) && is_string( $value['name'] ) && array_key_exists( 'settings', $value ) && preg_match( self::CODE_TYPE_RE, $value['name'] ) ) {
				self::flag( $state, self::EXCLUDED, 'dynamic tag "' . $value['name'] . '" at ' . ( '' === $path ? 'input' : $path ) );
			}
			foreach ( $value as $k => $v ) {
				$sub = '' === $path ? (string) $k : $path . '.' . $k;
				if ( is_string( $k ) ) {
					$nk = self::normalize_key( $k );
					if ( in_array( $nk, self::CODE_FIELDS, true ) ) {
						self::flag( $state, self::EXCLUDED, 'code field "' . $k . '"' );
					}
					if ( preg_match( self::FORM_DESTINATION_RE, $nk ) ) {
						self::flag( $state, self::CONFIRM, 'form destination field "' . $k . '"' );
					}
					if ( in_array( $nk, self::TYPE_FIELDS, true ) && is_string( $v ) && preg_match( self::CODE_TYPE_RE, $v ) ) {
						self::flag( $state, self::EXCLUDED, 'code widget type "' . $v . '"' );
					}
				}
				self::walk( $v, $sub, $depth + 1, $state );
			}
			return;
		}
		if ( is_string( $value ) ) {
			$reason = self::string_problem( $value );
			if ( null !== $reason ) {
				self::flag( $state, self::EXCLUDED, $reason . ' in ' . ( '' === $path ? 'input' : $path ) );
			}
		}
	}

	/**
	 * Returns why a string is refused, or null if it's fine.
	 */
	public static function string_problem( $s ) {
		if ( '' === $s ) {
			return null;
		}
		if ( in_array( self::normalize_key( trim( $s ) ), self::CODE_FIELD_VALUES, true ) ) {
			return 'code field name';
		}

		// Decode entities twice (catches &amp;lt;), then drop the characters
		// browsers ignore inside URL schemes and tag names.
		$decoded = html_entity_decode( html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$squeezed = (string) preg_replace( '/[\x00-\x1F\x7F]+/', '', $decoded );
		$variants = array_unique( array( $s, $decoded, $squeezed ) );

		foreach ( $variants as $v ) {
			if ( preg_match( '/<\?(php|=)?/i', $v ) ) {
				return 'PHP code';
			}
			if ( preg_match_all( '/<\s*\/?\s*([a-z][a-z0-9_:-]*)/i', $v, $m ) ) {
				foreach ( $m[1] as $tag ) {
					$t = strtolower( $tag );
					if ( in_array( $t, self::DANGEROUS_TAGS, true ) ) {
						return '<' . $t . '> tag';
					}
					// Custom-element / widget tags such as <e-html> in build-composition XML.
					if ( false !== strpos( $t, '-' ) && preg_match( self::CODE_TYPE_RE, $t ) ) {
						return '<' . $t . '> element';
					}
				}
			}
			// Event handlers and similar attributes inside a tag.
			if ( preg_match( '/<[a-z][^>]*?[\s\/"\'](on[a-z]+|srcdoc|formaction|xlink:href)\s*=/i', $v ) ) {
				return 'script attribute';
			}
			// Script URLs in attributes or CSS.
			if ( preg_match( '/\b(href|src|action|data|poster|background|cite|url)\s*=\s*["\']?\s*(javascript|vbscript|livescript|mocha)\s*:/i', $v )
				|| preg_match( '/url\s*\(\s*["\']?\s*(javascript|vbscript)\s*:/i', $v ) ) {
				return 'script URL';
			}
			if ( preg_match( '/\b(href|src|action|data|poster|background|url)\s*=\s*["\']?\s*data\s*:(?!\s*image\/(png|jpe?g|gif|webp|avif)[;,])/i', $v ) ) {
				return 'data URL';
			}
			// Dangerous CSS.
			if ( preg_match( '/:\s*expression\s*\(|-moz-binding\s*:|behavior\s*:\s*url\s*\(|@import\b/i', $v ) ) {
				return 'scripted CSS';
			}
			// Elementor V3 dynamic tag strings: [elementor-tag ... name="shortcode" ...].
			if ( preg_match_all( '/\[elementor-tag[^\]]*?name=\\\\?"?([a-z0-9_-]+)/i', $v, $tm ) ) {
				foreach ( $tm[1] as $tag_name ) {
					if ( preg_match( self::CODE_TYPE_RE, $tag_name ) ) {
						return 'dynamic tag "' . $tag_name . '"';
					}
				}
			}
		}

		// A whole value that is a script or non-image data URL (link fields).
		$compact = strtolower( preg_replace( '/[\s\x00-\x1F]+/', '', $decoded ) );
		if ( preg_match( '/^(javascript|vbscript|livescript|mocha):/', $compact ) ) {
			return 'script URL';
		}
		if ( preg_match( '/^data:(?!image\/(png|jpe?g|gif|webp|avif)[;,])/', $compact ) ) {
			return 'data URL';
		}
		return null;
	}
}
