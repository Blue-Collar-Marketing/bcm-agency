<?php
defined( 'ABSPATH' ) || exit;

/*
 * Elementor Pro Form widget: list forms and change who they email.
 *
 * A form can have two email actions ("Email" and "Email 2"). Sites usually use
 * one to notify the business and one to thank the person who submitted. Which
 * is which varies, so each action is classified by its recipient:
 *   - a fixed address (or blank = the site admin email)  -> "business"
 *   - a form field shortcode like [field id="email"]      -> "submitter" (never changed here)
 */

function bcm_agency_form_action_fields( $suffix ) {
	return array(
		'to'         => 'email_to' . $suffix,
		'cc'         => 'email_to_cc' . $suffix,
		'bcc'        => 'email_to_bcc' . $suffix,
		'subject'    => 'email_subject' . $suffix,
		'from'       => 'email_from' . $suffix,
		'from_name'  => 'email_from_name' . $suffix,
		'reply_to'   => 'email_reply_to' . $suffix,
	);
}

function bcm_agency_describe_email_action( array $settings, $action ) {
	$suffix  = 'email2' === $action ? '_2' : '';
	$fields  = bcm_agency_form_action_fields( $suffix );
	$actions = isset( $settings['submit_actions'] ) && is_array( $settings['submit_actions'] ) ? $settings['submit_actions'] : array( 'email' );
	$out     = array(
		'action'  => $action,
		'label'   => 'email2' === $action ? 'Email 2' : 'Email',
		'enabled' => in_array( $action, $actions, true ),
	);
	foreach ( $fields as $k => $key ) {
		$out[ $k ] = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
	}
	$to = trim( $out['to'] );
	if ( '' === $to ) {
		$out['sends_to']    = 'business';
		$out['to_resolved'] = (string) get_option( 'admin_email' );
		$out['note']        = 'To is blank, so Elementor uses the site admin email.';
	} elseif ( false !== strpos( $to, '[field' ) ) {
		$out['sends_to'] = 'submitter';
		$out['note']     = 'Sends to the person who filled in the form (e.g. a thank-you). Not changed by bcm/update-form-email.';
	} else {
		$out['sends_to']    = 'business';
		$out['to_resolved'] = $to;
	}
	return $out;
}

/** Collects every form widget in an element tree. */
function bcm_agency_collect_forms( array $elements, array &$forms ) {
	foreach ( $elements as $el ) {
		if ( ! is_array( $el ) ) {
			continue;
		}
		if ( isset( $el['widgetType'] ) && 'form' === $el['widgetType'] ) {
			$s       = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
			$forms[] = array(
				'element_id' => isset( $el['id'] ) ? (string) $el['id'] : '',
				'form_name'  => isset( $s['form_name'] ) ? (string) $s['form_name'] : '',
				'actions'    => isset( $s['submit_actions'] ) && is_array( $s['submit_actions'] ) ? array_values( $s['submit_actions'] ) : array( 'email' ),
				'email'      => bcm_agency_describe_email_action( $s, 'email' ),
				'email2'     => bcm_agency_describe_email_action( $s, 'email2' ),
				// Diagnostic: every email-related setting key actually stored, in case a site's Elementor version names them differently.
				'stored_email_keys' => array_values( preg_grep( '/^email/', array_keys( $s ) ) ),
			);
		}
		if ( isset( $el['elements'] ) && is_array( $el['elements'] ) ) {
			bcm_agency_collect_forms( $el['elements'], $forms );
		}
	}
}

/** Finds a form widget by element id and returns a reference to its settings. */
function &bcm_agency_find_form_settings( array &$elements, $element_id ) {
	$null = null;
	foreach ( $elements as &$el ) {
		if ( ! is_array( $el ) ) {
			continue;
		}
		if ( isset( $el['id'], $el['widgetType'] ) && (string) $el['id'] === (string) $element_id && 'form' === $el['widgetType'] ) {
			if ( ! isset( $el['settings'] ) || ! is_array( $el['settings'] ) ) {
				$el['settings'] = array();
			}
			return $el['settings'];
		}
		if ( isset( $el['elements'] ) && is_array( $el['elements'] ) ) {
			$found = &bcm_agency_find_form_settings( $el['elements'], $element_id );
			if ( null !== $found ) {
				return $found;
			}
		}
	}
	unset( $el );
	return $null;
}

function bcm_agency_list_forms( array $input ) {
	global $wpdb;
	$statuses = "'" . implode( "','", array_map( 'esc_sql', bcm_agency_replace_statuses() ) ) . "'";
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$ids = $wpdb->get_col(
		"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
		 WHERE m.meta_key = '_elementor_data' AND m.meta_value LIKE '%\"widgetType\":\"form\"%'
		 AND p.post_type NOT IN ('revision') AND p.post_status IN ($statuses)"
	);
	$out = array();
	foreach ( $ids as $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			continue;
		}
		$data = json_decode( (string) get_post_meta( $post->ID, '_elementor_data', true ), true );
		if ( ! is_array( $data ) ) {
			continue;
		}
		$forms = array();
		bcm_agency_collect_forms( $data, $forms );
		foreach ( $forms as $f ) {
			$out[] = array_merge(
				array(
					'post_id' => $post->ID,
					'page'    => get_the_title( $post ),
					'type'    => bcm_agency_post_label( $post ),
					'status'  => $post->post_status,
				),
				$f
			);
		}
	}
	return array(
		'forms'       => $out,
		'admin_email' => (string) get_option( 'admin_email' ),
		'note'        => 'sends_to "business" = notifies the client; "submitter" = thank-you to the person who filled in the form (left alone). Forms inside popups, headers and footers are included.',
	);
}

function bcm_agency_clean_email_list( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return '';
	}
	$parts = array_filter( array_map( 'trim', explode( ',', $value ) ) );
	foreach ( $parts as $p ) {
		if ( ! is_email( $p ) ) {
			return new WP_Error( 'bcm_bad_email', 'Not a valid email address: ' . $p );
		}
	}
	return implode( ', ', array_map( 'sanitize_email', $parts ) );
}

function bcm_agency_update_form_email( array $input ) {
	if ( empty( $input['approved'] ) ) {
		return new WP_Error( 'bcm_needs_approval', 'This changes where a live form sends its emails. Show the person the form (bcm/list-forms) and the new address, then call again with approved: true.' );
	}
	$post_id    = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$element_id = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';
	if ( ! $post_id || '' === $element_id ) {
		return new WP_Error( 'bcm_bad_input', 'post_id and element_id are required (from bcm/list-forms).' );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'bcm_forbidden', 'You cannot edit this page.' );
	}
	if ( ! isset( $input['to'] ) && ! isset( $input['cc'] ) && ! isset( $input['bcc'] ) ) {
		return new WP_Error( 'bcm_bad_input', 'Pass to, cc and/or bcc.' );
	}

	$data = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'bcm_not_found', 'No Elementor data on that page.' );
	}
	$settings = &bcm_agency_find_form_settings( $data, $element_id );
	if ( null === $settings ) {
		return new WP_Error( 'bcm_not_found', 'No form with that element_id on that page. Run bcm/list-forms again.' );
	}

	// Which action to change: the one that notifies the business, unless told explicitly.
	$want = isset( $input['action'] ) ? (string) $input['action'] : 'auto';
	$desc = array(
		'email'  => bcm_agency_describe_email_action( $settings, 'email' ),
		'email2' => bcm_agency_describe_email_action( $settings, 'email2' ),
	);
	if ( 'auto' === $want ) {
		$candidates = array_keys(
			array_filter(
				$desc,
				function ( $d ) {
					return $d['enabled'] && 'business' === $d['sends_to'];
				}
			)
		);
		if ( 1 !== count( $candidates ) ) {
			return new WP_Error(
				'bcm_ambiguous',
				0 === count( $candidates )
					? 'This form has no enabled email action that sends to the business. Check bcm/list-forms.'
					: 'Both Email and Email 2 send to fixed addresses on this form. Ask which one to change and pass action: "email" or "email2".'
			);
		}
		$want = $candidates[0];
	}
	if ( ! isset( $desc[ $want ] ) ) {
		return new WP_Error( 'bcm_bad_input', 'action must be auto, email or email2.' );
	}
	if ( 'submitter' === $desc[ $want ]['sends_to'] ) {
		return new WP_Error( 'bcm_protected', 'That action emails the person who submitted the form (e.g. a thank-you), so it is not changed here.' );
	}

	$fields  = bcm_agency_form_action_fields( 'email2' === $want ? '_2' : '' );
	$before  = $desc[ $want ];
	$changed = array();
	foreach ( array( 'to', 'cc', 'bcc' ) as $k ) {
		if ( ! isset( $input[ $k ] ) ) {
			continue;
		}
		$clean = bcm_agency_clean_email_list( $input[ $k ] );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( 'to' === $k && '' === $clean ) {
			return new WP_Error( 'bcm_bad_input', 'to cannot be empty. To use the site admin email instead, change it in Settings → General.' );
		}
		$settings[ $fields[ $k ] ] = $clean;
		$changed[ $k ]             = $clean;
	}

	$how   = bcm_agency_save_elementor_data( $post_id, $data );
	$check = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
	$after = null;
	if ( is_array( $check ) ) {
		$s = &bcm_agency_find_form_settings( $check, $element_id );
		if ( null !== $s ) {
			$after = bcm_agency_describe_email_action( $s, $want );
		}
	}

	return array(
		'id'        => $post_id,
		'page'      => get_the_title( $post_id ),
		'form_name' => isset( $settings['form_name'] ) ? $settings['form_name'] : '',
		'action'    => $before['label'],
		'before'    => array_intersect_key( $before, array_flip( array( 'to', 'to_resolved', 'cc', 'bcc' ) ) ),
		'after'     => $after ? array_intersect_key( $after, array_flip( array( 'to', 'cc', 'bcc' ) ) ) : $changed,
		'untouched' => 'The other email action (' . ( 'email' === $want ? 'Email 2' : 'Email' ) . ') was not changed.',
		'saved_by'  => $how,
		'next_step' => 'Send a test submission to confirm the new address receives it. Undo: the page\'s Revisions (Elementor: History → Revisions).',
		'_log'      => array(
			'form'    => isset( $settings['form_name'] ) ? $settings['form_name'] : $element_id,
			'action'  => $want,
			'before'  => $before['to'],
			'changed' => $changed,
		),
	);
}

function bcm_agency_register_form_abilities() {
	bcm_agency_register(
		'bcm/list-forms',
		array(
			'label'               => 'List Elementor forms and their emails',
			'description'         => 'Read-only. Lists every Elementor Pro form on the site (pages, popups, headers, footers): page, form name, enabled actions, and for Email and Email 2 the recipients, subject, from and reply-to. Each email action is labelled sends_to "business" (fixed address, or blank = site admin email) or "submitter" (sends to the person who filled in the form, e.g. a thank-you).',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => new stdClass(),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_list_forms',
			'permission_callback' => function () {
				return current_user_can( 'edit_pages' );
			},
		),
		true
	);

	bcm_agency_register(
		'bcm/update-form-email',
		array(
			'label'               => 'Change where a form sends its emails',
			'description'         => 'Changes To, CC and/or BCC of the email action that notifies the business on one Elementor form (from bcm/list-forms). With action "auto" (default) it picks the enabled action that sends to a fixed address, whether that is Email or Email 2. The thank-you email to the submitter is never changed. Validates addresses, saves a revision for undo, and requires approved: true.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'    => array( 'type' => 'integer' ),
					'element_id' => array( 'type' => 'string' ),
					'action'     => array(
						'type'    => 'string',
						'enum'    => array( 'auto', 'email', 'email2' ),
						'default' => 'auto',
					),
					'to'         => array(
						'type'        => 'string',
						'description' => 'New recipient(s), comma-separated.',
					),
					'cc'         => array(
						'type'        => 'string',
						'description' => 'CC address(es), comma-separated. Empty string clears it.',
					),
					'bcc'        => array(
						'type'        => 'string',
						'description' => 'BCC address(es), comma-separated. Empty string clears it.',
					),
					'approved'   => array(
						'type'        => 'boolean',
						'description' => 'Set true only after the person has approved the change.',
					),
				),
				'required'             => array( 'post_id', 'element_id' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_update_form_email',
			'permission_callback' => function () {
				return current_user_can( 'edit_pages' ) && current_user_can( 'edit_others_posts' );
			},
		)
	);
}
