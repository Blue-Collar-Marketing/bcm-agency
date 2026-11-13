<?php
/**
 * Public keys this site trusts for BCM Hub requests.
 *
 * PUBLIC keys only. They can check a signature but never create one, so they
 * are safe in a public repo. The matching private keys live only in
 * Cloudflare's secret storage and are never written anywhere else.
 *
 * Format: key ID => base64url of the 32-byte Ed25519 public key.
 *
 * - "signing":  keys the hub signs every request with (bcm-hub Worker).
 * - "approval": keys that sign a second confirmation (bcm-hub-approve Worker),
 *               needed for form recipients and site-wide settings.
 *
 * Each list ships the CURRENT key and the NEXT one, so a key can be changed
 * with a normal plugin update. To cut every site off from the hub
 * ("disconnect entirely"), release an update with both lists empty.
 *
 * The release build refuses to package this file while any placeholder value
 * is left in it, so a placeholder can never reach client sites.
 */

defined( 'ABSPATH' ) || exit;

return array(
	'signing'  => array(
		'hub-1' => '6QMayx1URDTaShHjVoxvQHIK19IM_NeXnHiUewzLS4o',
		'hub-2' => 'hHqNyWk4Ne0gGvwh8Dp1GZkkhuU1cpum2Ev-_YU_sbI',
	),
	'approval' => array(
		'approve-1' => 'mR5XfOKslqS-N0jy7OBcnUad60y9oXLOLCU2CB6Hlxg',
		'approve-2' => 'pZvElbjuv1xrRFi8E-SuLVdcMmWQidxrJjyf6rBG6B4',
	),
);
