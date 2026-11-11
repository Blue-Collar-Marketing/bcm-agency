<?php
defined( 'ABSPATH' ) || exit;

const BCM_AGENCY_MAX_IMAGE_BYTES = 10485760; // 10 MB.

/**
 * Hosts images may be downloaded from. Entries starting with "." match any
 * subdomain. Extend per site with the bcm_agency_media_hosts filter.
 */
function bcm_agency_media_hosts() {
	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
	$hosts     = array(
		$site_host,
		'drive.google.com',
		'drive.usercontent.google.com',
		'docs.google.com',
		'.googleusercontent.com',
		// Marker.io comment attachments: only Marker.io's own attachment storage, never all of S3.
		's3.eu-west-1.amazonaws.com/marker.attachments.prod/',
		'marker.attachments.prod.s3.eu-west-1.amazonaws.com',
		// Higgsfield AI image generations: only Higgsfield's own CloudFront distribution, never all of CloudFront.
		'd8j0ntlcm91z4.cloudfront.net',
	);
	return array_values( array_filter( (array) apply_filters( 'bcm_agency_media_hosts', $hosts ) ) );
}

function bcm_agency_host_allowed( $url ) {
	$parts = wp_parse_url( $url );
	if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return false;
	}
	$host = strtolower( $parts['host'] );
	$path = isset( $parts['path'] ) ? $parts['path'] : '/';
	foreach ( bcm_agency_media_hosts() as $allowed ) {
		$allowed = strtolower( $allowed );
		// "host/path/prefix/" entries allow only URLs under that path on that host.
		if ( false !== strpos( $allowed, '/' ) ) {
			list( $a_host, $a_path ) = explode( '/', $allowed, 2 );
			if ( $host === $a_host && 0 === strpos( $path, '/' . $a_path ) && false === strpos( $path, '..' ) ) {
				return true;
			}
			continue;
		}
		if ( '.' === $allowed[0] ? ( substr( $host, -strlen( $allowed ) ) === $allowed ) : ( $host === $allowed ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Downloads to a temp file, following redirects by hand so every hop is
 * checked against the allowlist (wp_safe_remote_get also blocks private IPs).
 */
function bcm_agency_download( $url ) {
	$tmp = wp_tempnam( 'bcm-media' );
	for ( $hop = 0; $hop <= 5; $hop++ ) {
		if ( ! bcm_agency_host_allowed( $url ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'bcm_host_not_allowed', 'Images can only come from approved HTTPS sources: ' . implode( ', ', bcm_agency_media_hosts() ) . '. Blocked: ' . wp_parse_url( $url, PHP_URL_HOST ) );
		}
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 30,
				'redirection'         => 0,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => BCM_AGENCY_MAX_IMAGE_BYTES + 1,
			)
		);
		if ( is_wp_error( $res ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 300 && $code < 400 ) {
			$location = wp_remote_retrieve_header( $res, 'location' );
			if ( ! $location ) {
				break;
			}
			$url = WP_Http::make_absolute_url( $location, $url );
			continue;
		}
		if ( 200 !== $code ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'bcm_download_failed', 'Download failed with HTTP ' . $code . '. For Google Drive, the file must be shared as "anyone with the link".' );
		}
		return $tmp;
	}
	@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	return new WP_Error( 'bcm_download_failed', 'Too many redirects.' );
}

function bcm_agency_upload_media( array $input ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$has_url  = ! empty( $input['source_url'] );
	$has_data = ! empty( $input['data_base64'] );
	if ( $has_url === $has_data ) {
		return new WP_Error( 'bcm_bad_input', 'Provide exactly one of source_url or data_base64.' );
	}

	if ( $has_url ) {
		$tmp = bcm_agency_download( (string) $input['source_url'] );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		$name   = ! empty( $input['filename'] ) ? $input['filename'] : basename( (string) wp_parse_url( $input['source_url'], PHP_URL_PATH ) );
		$source = wp_parse_url( $input['source_url'], PHP_URL_HOST );
	} else {
		$raw = base64_decode( preg_replace( '/^data:[^;]+;base64,/', '', (string) $input['data_base64'] ), true );
		if ( false === $raw ) {
			return new WP_Error( 'bcm_bad_input', 'data_base64 is not valid base64.' );
		}
		if ( strlen( $raw ) > 5242880 ) {
			return new WP_Error( 'bcm_too_large', 'Inline images are limited to 5 MB. Use source_url for larger files.' );
		}
		$tmp = wp_tempnam( 'bcm-media' );
		file_put_contents( $tmp, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$name   = ! empty( $input['filename'] ) ? $input['filename'] : 'image';
		$source = 'inline';
	}

	$size = (int) filesize( $tmp );
	if ( $size > BCM_AGENCY_MAX_IMAGE_BYTES ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return new WP_Error( 'bcm_too_large', 'Images are limited to 10 MB.' );
	}

	// Trust the bytes, not the name: must be a real JPEG, PNG, WebP or GIF.
	$info  = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	$types = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
		'image/gif'  => 'gif',
	);
	if ( ! $info || empty( $types[ $info['mime'] ] ) ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return new WP_Error( 'bcm_bad_type', 'Only JPEG, PNG, WebP and GIF images are accepted (SVG is not). For Google Drive, check the link points to the file itself.' );
	}
	$ext  = $types[ $info['mime'] ];
	$base = sanitize_file_name( pathinfo( (string) $name, PATHINFO_FILENAME ) );
	$name = ( $base ? $base : 'image' ) . '.' . $ext;

	$attach_to = ! empty( $input['attach_to_post_id'] ) ? (int) $input['attach_to_post_id'] : 0;
	if ( $attach_to && ! current_user_can( 'edit_post', $attach_to ) ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return new WP_Error( 'bcm_forbidden', 'You cannot attach media to that post.' );
	}

	$title = ! empty( $input['title'] ) ? sanitize_text_field( $input['title'] ) : null;
	$id    = media_handle_sideload(
		array(
			'name'     => $name,
			'tmp_name' => $tmp,
		),
		$attach_to,
		$title
	);
	if ( is_wp_error( $id ) ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return $id;
	}

	if ( isset( $input['alt_text'] ) ) {
		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );
	}
	if ( ! empty( $input['caption'] ) ) {
		wp_update_post(
			array(
				'ID'           => $id,
				'post_excerpt' => sanitize_text_field( $input['caption'] ),
			)
		);
	}

	return array(
		'id'       => (int) $id,
		'url'      => wp_get_attachment_url( $id ),
		'width'    => (int) $info[0],
		'height'   => (int) $info[1],
		'filename' => $name,
		'alt_text' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		'_log'     => array(
			'filename' => $name,
			'source'   => $source,
			'bytes'    => $size,
		),
	);
}

function bcm_agency_register_media_abilities() {
	bcm_agency_register(
		'bcm/upload-media',
		array(
			'label'               => 'Upload image to Media Library',
			'description'         => 'Adds one image (JPEG, PNG, WebP or GIF, max 10 MB) to the Media Library from an approved HTTPS source (this site, Google Drive, Marker.io comment attachments via issue_get_attachments, Higgsfield image generations) or from inline base64 (max 5 MB), with alt text. Returns the attachment ID to use as a featured image. Google Drive files must be shared as "anyone with the link"; use the direct download form https://drive.google.com/uc?export=download&id=FILE_ID.',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'source_url'        => array(
						'type'        => 'string',
						'description' => 'HTTPS URL of the image. Provide this or data_base64.',
					),
					'data_base64'       => array(
						'type'        => 'string',
						'description' => 'Base64 image bytes (optionally a data: URI). Provide this or source_url.',
					),
					'filename'          => array(
						'type'        => 'string',
						'description' => 'Descriptive file name, e.g. "ottawa-interlock-patio". The correct extension is added automatically.',
					),
					'alt_text'          => array(
						'type'        => 'string',
						'description' => 'Alt text describing the image. Strongly recommended for SEO and accessibility.',
					),
					'title'             => array( 'type' => 'string' ),
					'caption'           => array( 'type' => 'string' ),
					'attach_to_post_id' => array(
						'type'        => 'integer',
						'description' => 'Optional post to attach the image to.',
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'bcm_agency_upload_media',
			'permission_callback' => function () {
				return current_user_can( 'upload_files' );
			},
		)
	);
}
