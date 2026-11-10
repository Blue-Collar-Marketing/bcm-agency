<?php
defined( 'ABSPATH' ) || exit;

/**
 * Known tracking/verification services. The caller passes the ID (or whatever the
 * PM pasted); we extract the ID with a strict pattern and generate the official
 * code ourselves. Pasted markup is never stored.
 */
function bcm_agency_tracking_services() {
	return array(
		'google_search_console' => array(
			'label'   => 'Google Search Console verification',
			'scan'    => '/google-site-verification["\']?\s+content=["\']([A-Za-z0-9_\-]{20,100})/',
			'pattern' => '/google-site-verification["\']?\s+content=["\']([A-Za-z0-9_\-]{20,100})["\']|^([A-Za-z0-9_\-]{20,100})$/',
		),
		'bing_webmaster'        => array(
			'label'   => 'Bing Webmaster verification',
			'scan'    => '/msvalidate\.01["\']?\s+content=["\']([A-Fa-f0-9]{32})/',
			'pattern' => '/msvalidate\.01["\']?\s+content=["\']([A-Fa-f0-9]{32})["\']|^([A-Fa-f0-9]{32})$/',
		),
		'google_analytics_4'    => array(
			'label'   => 'Google Analytics 4',
			'scan'    => '/\b(G-[A-Z0-9]{4,12})\b/',
			'pattern' => '/\b(G-[A-Z0-9]{4,12})\b/',
		),
		'google_tag_manager'    => array(
			'label'   => 'Google Tag Manager',
			'scan'    => '/\b(GTM-[A-Z0-9]{4,10})\b/',
			'pattern' => '/\b(GTM-[A-Z0-9]{4,10})\b/',
		),
		'google_ads'            => array(
			'label'   => 'Google Ads tag',
			'scan'    => '/\b(AW-\d{6,12})\b/',
			'pattern' => '/\b(AW-\d{6,12})\b/',
		),
		'marker_io'             => array(
			'label'   => 'Marker.io feedback widget',
			'pattern' => '/project\s*:\s*["\']([a-f0-9]{20,32})["\']|^([a-f0-9]{20,32})$/',
			'scan'    => '/markerConfig\s*=\s*\{[^}]*?project\s*:\s*["\']([a-f0-9]{20,32})["\']/s',
		),
		'meta_pixel'            => array(
			'label'   => 'Meta (Facebook) Pixel',
			'scan'    => '/fbq\(\s*[\'"]init[\'"]\s*,\s*[\'"](\d{10,20})[\'"]/',
			'pattern' => '/fbq\(\s*[\'"]init[\'"]\s*,\s*[\'"](\d{10,20})[\'"]|^(\d{10,20})$/',
		),
	);
}

function bcm_agency_extract_tracking_id( $service, $value ) {
	$services = bcm_agency_tracking_services();
	if ( empty( $services[ $service ] ) ) {
		return new WP_Error( 'bcm_bad_service', 'Unknown service. Supported: ' . implode( ', ', array_keys( $services ) ) . '. Anything else is added by a developer in WPCode.' );
	}
	$value = trim( (string) $value );
	if ( ! preg_match( $services[ $service ]['pattern'], $value, $m ) ) {
		return new WP_Error( 'bcm_bad_id', 'Could not find a valid ' . $services[ $service ]['label'] . ' ID in what was provided.' );
	}
	$id = '';
	for ( $i = count( $m ) - 1; $i >= 1; $i-- ) {
		if ( '' !== $m[ $i ] ) {
			$id = $m[ $i ];
			break;
		}
	}
	return 'bing_webmaster' === $service ? strtoupper( $id ) : $id;
}

/**
 * Returns [ [ location, code ], ... ] for a service and validated ID.
 */
function bcm_agency_tracking_code( $service, $id ) {
	$e = esc_attr( $id );
	$j = esc_js( $id );
	switch ( $service ) {
		case 'google_search_console':
			return array( array( 'site_wide_header', '<meta name="google-site-verification" content="' . $e . '" />' ) );
		case 'bing_webmaster':
			return array( array( 'site_wide_header', '<meta name="msvalidate.01" content="' . $e . '" />' ) );
		case 'google_analytics_4':
		case 'google_ads':
			return array(
				array(
					'site_wide_header',
					"<!-- Google tag (gtag.js) -->\n<script async src=\"https://www.googletagmanager.com/gtag/js?id={$e}\"></script>\n<script>\n  window.dataLayer = window.dataLayer || [];\n  function gtag(){dataLayer.push(arguments);}\n  gtag('js', new Date());\n  gtag('config', '{$j}');\n</script>",
				),
			);
		case 'google_tag_manager':
			return array(
				array(
					'site_wide_header',
					"<!-- Google Tag Manager -->\n<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':\nnew Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],\nj=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=\n'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);\n})(window,document,'script','dataLayer','{$j}');</script>\n<!-- End Google Tag Manager -->",
				),
				array(
					'site_wide_body',
					"<!-- Google Tag Manager (noscript) -->\n<noscript><iframe src=\"https://www.googletagmanager.com/ns.html?id={$e}\" height=\"0\" width=\"0\" style=\"display:none;visibility:hidden\"></iframe></noscript>\n<!-- End Google Tag Manager (noscript) -->",
				),
			);
		case 'marker_io':
			return array(
				array(
					'site_wide_header',
					"<!-- Marker.io feedback widget -->\n<script>\n  window.markerConfig = {\n    project: '{$j}',\n    source: 'snippet'\n  };\n  !(function (e, r, t) {\n    if (e.__Marker) return;\n    e.__Marker = {};\n    var n = r.createElement('script');\n    n.async = 1;\n    n.src = 'https://edge.marker.io/latest/shim.js';\n    var s = r.getElementsByTagName('script')[0];\n    s.parentNode.insertBefore(n, s);\n  })(window, document);\n</script>",
				),
			);
		case 'meta_pixel':
			return array(
				array(
					'site_wide_header',
					"<!-- Meta Pixel Code -->\n<script>\n!function(f,b,e,v,n,t,s)\n{if(f.fbq)return;n=f.fbq=function(){n.callMethod?\nn.callMethod.apply(n,arguments):n.queue.push(arguments)};\nif(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';\nn.queue=[];t=b.createElement(e);t.async=!0;\nt.src=v;s=b.getElementsByTagName(e)[0];\ns.parentNode.insertBefore(t,s)}(window, document,'script',\n'https://connect.facebook.net/en_US/fbevents.js');\nfbq('init', '{$j}');\nfbq('track', 'PageView');\n</script>\n<noscript><img height=\"1\" width=\"1\" style=\"display:none\"\nsrc=\"https://www.facebook.com/tr?id={$e}&ev=PageView&noscript=1\"/></noscript>\n<!-- End Meta Pixel Code -->",
				),
			);
	}
	return array();
}

/**
 * Everywhere tracking code can already live: WPCode snippets (active or not),
 * WPCode's global Header & Footer fields, and the live homepage HTML.
 */
function bcm_agency_tracking_sources() {
	$sources = array();
	$snippets = get_posts(
		array(
			'post_type'      => 'wpcode',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => 500,
			'no_found_rows'  => true,
		)
	);
	foreach ( $snippets as $s ) {
		$sources[] = array(
			'where'  => 'WPCode snippet #' . $s->ID . ' "' . $s->post_title . '" (' . ( 'publish' === $s->post_status ? 'active' : 'inactive' ) . ')',
			'id'     => $s->ID,
			'active' => 'publish' === $s->post_status,
			'text'   => (string) $s->post_content,
		);
	}
	foreach ( array( 'ihaf_insert_header' => 'header', 'ihaf_insert_body' => 'body', 'ihaf_insert_footer' => 'footer' ) as $opt => $label ) {
		$v = (string) get_option( $opt, '' );
		if ( '' !== trim( $v ) ) {
			$sources[] = array(
				'where'  => 'WPCode Header & Footer: ' . $label,
				'id'     => 0,
				'active' => true,
				'text'   => $v,
			);
		}
	}
	return $sources;
}

function bcm_agency_homepage_html() {
	$res = wp_remote_get(
		home_url( '/' ),
		array(
			'timeout'     => 15,
			'redirection' => 3,
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
		)
	);
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return null;
	}
	return (string) wp_remote_retrieve_body( $res );
}

function bcm_agency_find_tracking_ids( $text ) {
	$found = array();
	foreach ( bcm_agency_tracking_services() as $key => $svc ) {
		$pattern = $svc['scan'];
		if ( preg_match_all( $pattern, $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				for ( $i = count( $match ) - 1; $i >= 1; $i-- ) {
					if ( '' !== $match[ $i ] ) {
						$found[ $key . ':' . $match[ $i ] ] = array( 'service' => $key, 'id' => $match[ $i ] );
						break;
					}
				}
			}
		}
	}
	return array_values( $found );
}

function bcm_agency_list_tracking_codes() {
	$out = array(
		'wpcode_active' => class_exists( 'WPCode_Snippet' ),
		'found'         => array(),
		'snippets'      => array(),
	);
	foreach ( bcm_agency_tracking_sources() as $src ) {
		if ( $src['id'] ) {
			$out['snippets'][] = $src['where'];
		}
		foreach ( bcm_agency_find_tracking_ids( $src['text'] ) as $hit ) {
			$out['found'][] = $hit + array( 'where' => $src['where'] );
		}
	}
	$html = bcm_agency_homepage_html();
	if ( null === $html ) {
		$out['homepage_checked'] = false;
	} else {
		$out['homepage_checked'] = true;
		foreach ( bcm_agency_find_tracking_ids( $html ) as $hit ) {
			$out['found'][] = $hit + array( 'where' => 'live homepage HTML' );
		}
	}
	$out['note'] = 'Live homepage hits can come from any source (another plugin, theme, Site Kit, GTM container). IDs are matched in context (e.g. inside the verification meta tag or fbq init call), so stray text is not reported.';
	return $out;
}

function bcm_agency_add_tracking_code( array $input ) {
	if ( empty( $input['approved'] ) ) {
		return new WP_Error( 'bcm_needs_approval', 'Tracking codes go live on every page immediately. Confirm the service and ID with the person, then call again with approved: true.' );
	}
	if ( ! function_exists( 'wpcode' ) && ! class_exists( 'WPCode_Snippet' ) ) {
		return new WP_Error( 'bcm_no_wpcode', 'WPCode is not active on this site. Activate WPCode (Lite is fine) first; its Header & Footer fields print the codes.' );
	}
	$service = isset( $input['service'] ) ? (string) $input['service'] : '';
	$id      = bcm_agency_extract_tracking_id( $service, isset( $input['value'] ) ? $input['value'] : '' );
	if ( is_wp_error( $id ) ) {
		return $id;
	}

	// Duplicate check across WPCode (snippets + Header & Footer) and the live homepage.
	$dupes = array();
	foreach ( bcm_agency_tracking_sources() as $src ) {
		if ( false !== strpos( $src['text'], $id ) ) {
			$dupes[] = $src['where'];
		}
	}
	$html = bcm_agency_homepage_html();
	if ( null !== $html && false !== strpos( $html, $id ) ) {
		$dupes[] = 'live homepage HTML (possibly from another plugin or the theme)';
	}
	if ( $dupes ) {
		return new WP_Error( 'bcm_duplicate', $id . ' is already on this site: ' . implode( '; ', array_unique( $dupes ) ) . '. Adding it again would double-count. Nothing was added.' );
	}

	$services = bcm_agency_tracking_services();
	$options  = array(
		'site_wide_header' => 'ihaf_insert_header',
		'site_wide_body'   => 'ihaf_insert_body',
	);
	$written  = array();
	foreach ( bcm_agency_tracking_code( $service, $id ) as $part ) {
		list( $location, $code ) = $part;
		$opt   = $options[ $location ];
		$label = $services[ $service ]['label'] . ' ' . $id;
		$block = "<!-- BCM:start {$service} {$id} | {$label} | added " . gmdate( 'Y-m-d' ) . " -->\n{$code}\n<!-- BCM:end {$service} {$id} -->";

		// Append only. Existing Header & Footer content is never changed or reordered.
		// WPCode stores these fields slashed and prints them with wp_unslash(), so the
		// stored value is kept as-is and only our block is slashed (same as WPCode's own save).
		$current = (string) get_option( $opt, '' );
		$new     = '' === trim( $current ) ? wp_slash( $block ) : rtrim( $current ) . "\n\n" . wp_slash( $block );
		update_option( $opt, $new );

		if ( false === strpos( (string) get_option( $opt, '' ), "BCM:start {$service} {$id}" ) ) {
			return new WP_Error( 'bcm_save_failed', 'WordPress did not save the WPCode ' . ( 'ihaf_insert_body' === $opt ? 'Body' : 'Header' ) . ' field' . ( $written ? ' (the Header part was saved)' : '' ) . '.' );
		}
		$written[] = 'ihaf_insert_body' === $opt ? 'Body' : 'Header';
	}

	// Same as WPCode's own Header & Footer save: clear page caches so the change shows.
	$purged = bcm_agency_purge_page_cache();

	$live = null;
	$html = bcm_agency_homepage_html();
	if ( null !== $html ) {
		$live = false !== strpos( $html, $id );
	}
	if ( true === $live ) {
		$status = 'Live: the code is printing on the homepage.';
	} elseif ( false === $live ) {
		$status = 'Saved in WPCode Header & Footer and page caches were cleared, but the homepage does not show it yet. A server or CDN cache (Varnish, Cloudflare) may still be serving an old copy: purge it from Cloudways, then check again with bcm/list-tracking-codes.';
	} else {
		$status = 'Saved in WPCode Header & Footer. The homepage could not be checked from the server.';
	}

	return array(
		'service'          => $services[ $service ]['label'],
		'tracking_id'      => $id,
		'saved_in'         => 'WPCode → Code Snippets → Header & Footer: ' . implode( ' + ', $written ),
		'live_on_homepage' => $live,
		'status'           => $status,
		'caches_cleared'   => $purged,
		'edit_in_wpcode'   => admin_url( 'admin.php?page=wpcode-headers-footers' ),
		'next_step'        => bcm_agency_tracking_next_step( $service, $live ),
		'how_to_remove'    => 'Delete the block between <!-- BCM:start ' . $service . ' ' . $id . ' --> and <!-- BCM:end ' . $service . ' ' . $id . ' --> in Header & Footer.',
		'_log'             => array(
			'service'     => $service,
			'tracking_id' => $id,
			'fields'      => $written,
			'live'        => $live,
		),
	);
}

/**
 * What the PM should do next. Page caches are already cleared by the ability,
 * so purging is only mentioned when the code is not showing yet.
 */
function bcm_agency_tracking_next_step( $service, $live ) {
	$check = array(
		'google_search_console' => 'Click Verify in Search Console.',
		'bing_webmaster'        => 'Click Verify in Bing Webmaster Tools.',
		'google_analytics_4'    => 'Confirm data is arriving in GA4 (Reports → Realtime) or with Google Tag Assistant.',
		'google_tag_manager'    => 'Confirm the container loads with Google Tag Assistant (Preview mode in GTM).',
		'google_ads'            => 'Confirm the tag with Google Tag Assistant; the Ads tag status can take a few hours to update.',
		'marker_io'             => 'Open the site and check the Marker.io feedback button appears (who sees it is set in the Marker.io project\'s widget settings). Remove it at sign-off with bcm/remove-tracking-code.',
		'meta_pixel'            => 'Confirm the PageView fires with the Meta Pixel Helper browser extension or Events Manager → Test Events.',
	);
	$step = isset( $check[ $service ] ) ? $check[ $service ] : 'Confirm the code is working with the service.';
	if ( false === $live ) {
		return 'Once a Cloudways (Varnish) or CDN purge makes it appear on the homepage: ' . $step;
	}
	return $step;
}

/**
 * Removes a BCM-added code block (and only BCM-marked blocks) from WPCode's
 * Header & Footer fields. Code added by hand or by other tools is never touched.
 */
function bcm_agency_remove_tracking_code( array $input ) {
	if ( empty( $input['approved'] ) ) {
		return new WP_Error( 'bcm_needs_approval', 'Removing a tracking code takes it off every page immediately. Confirm the service and ID with the person, then call again with approved: true.' );
	}
	$service = isset( $input['service'] ) ? (string) $input['service'] : '';
	$id      = bcm_agency_extract_tracking_id( $service, isset( $input['value'] ) ? $input['value'] : '' );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	$pattern = '/\n*<!-- BCM:start ' . preg_quote( $service, '/' ) . ' ' . preg_quote( $id, '/' ) . '\b[^>]*-->.*?<!-- BCM:end ' . preg_quote( $service, '/' ) . ' ' . preg_quote( $id, '/' ) . ' -->\n*/s';
	$removed = array();
	foreach ( array( 'ihaf_insert_header' => 'Header', 'ihaf_insert_body' => 'Body', 'ihaf_insert_footer' => 'Footer' ) as $opt => $label ) {
		$current = (string) get_option( $opt, '' );
		if ( '' === $current || ! preg_match( $pattern, $current ) ) {
			continue;
		}
		$new = trim( preg_replace( $pattern, "\n\n", $current ) );
		update_option( $opt, $new );
		$removed[] = $label;
	}
	if ( ! $removed ) {
		$elsewhere = array();
		foreach ( bcm_agency_tracking_sources() as $src ) {
			if ( false !== strpos( $src['text'], $id ) ) {
				$elsewhere[] = $src['where'];
			}
		}
		return new WP_Error(
			'bcm_not_found',
			$elsewhere
				? $id . ' was not added by BCM, so it is not removed automatically. It is in: ' . implode( '; ', $elsewhere ) . '. Remove it by hand in WPCode.'
				: 'No BCM block for ' . $id . ' was found. Nothing was removed.'
		);
	}
	$purged = bcm_agency_purge_page_cache();
	$live   = null;
	$html   = bcm_agency_homepage_html();
	if ( null !== $html ) {
		$live = false !== strpos( $html, $id );
	}
	$services = bcm_agency_tracking_services();
	return array(
		'service'            => $services[ $service ]['label'],
		'tracking_id'        => $id,
		'removed_from'       => 'WPCode → Header & Footer: ' . implode( ' + ', $removed ),
		'still_on_homepage'  => $live,
		'status'             => true === $live ? 'Removed from WPCode, but the ID still appears on the homepage: from another source or a cache that has not cleared yet.' : 'Removed and no longer on the homepage.',
		'caches_cleared'     => $purged,
		'_log'               => array(
			'service'     => $service,
			'tracking_id' => $id,
			'fields'      => $removed,
		),
	);
}

function bcm_agency_register_tracking_abilities() {
	bcm_agency_register(
		'bcm/list-tracking-codes',
		array(
			'label'               => 'List tracking and verification codes',
			'description'         => 'Finds Google Search Console, Bing, GA4, Google Tag Manager, Google Ads and Meta Pixel IDs already on the site: in WPCode snippets (active and inactive), WPCode\'s global Header & Footer fields, and the live homepage HTML. Use before adding a code, and for migration audits.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => new stdClass(),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_list_tracking_codes',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		),
		true
	);

	bcm_agency_register(
		'bcm/add-tracking-code',
		array(
			'label'               => 'Add tracking or verification code (WPCode Header & Footer)',
			'description'         => 'Adds a known service to the whole site through WPCode\'s global Header & Footer fields, as a clearly marked BCM block appended after existing content (existing code is never changed): Google Search Console or Bing verification, Google Analytics 4, Google Tag Manager (header + body), Google Ads, Meta Pixel, or the Marker.io feedback widget (project id from the Marker.io connector). Pass the ID or paste what the service provided as value; the plugin extracts the ID and writes the official code itself (pasted markup is never used). Refuses if the ID is already on the site. Requires approved: true after the person confirms. Reports whether the code is live on the homepage. Other scripts are added by a developer in WPCode; remove with bcm/remove-tracking-code.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'service'  => array(
						'type' => 'string',
						'enum' => array_keys( bcm_agency_tracking_services() ),
					),
					'value'    => array(
						'type'        => 'string',
						'description' => 'The ID (e.g. G-ABC123XYZ, GTM-ABCD123, the Search Console verification token) or the snippet the service provided.',
					),
					'approved' => array(
						'type'        => 'boolean',
						'description' => 'Set true only after the person has confirmed the service and ID.',
					),
				),
				'required'             => array( 'service', 'value' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_add_tracking_code',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);

	bcm_agency_register(
		'bcm/remove-tracking-code',
		array(
			'label'               => 'Remove a tracking or verification code (BCM blocks only)',
			'description'         => 'Removes a code that BCM added (a marked BCM block in WPCode Header & Footer), e.g. turning the Marker.io feedback widget off at sign-off or retiring an old Analytics ID. Never removes code added by hand or by other tools; it reports where that code lives instead. Requires approved: true; clears caches and checks the homepage.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'service'  => array(
						'type' => 'string',
						'enum' => array_keys( bcm_agency_tracking_services() ),
					),
					'value'    => array(
						'type'        => 'string',
						'description' => 'The ID to remove (e.g. G-ABC123, the Marker.io project id).',
					),
					'approved' => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'service', 'value' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_remove_tracking_code',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);
}
