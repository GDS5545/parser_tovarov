<?php
/**
 * Single source of truth for the capability required to use the plugin's
 * admin pages and REST endpoints.
 *
 * @package Uws\Security
 */

namespace Uws\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Capabilities {

	const MANAGE = 'manage_woocommerce';

	/**
	 * @return bool
	 */
	public static function current_user_can_manage() {
		return current_user_can( self::MANAGE );
	}

	/**
	 * REST permission_callback for every uws/v1 route.
	 *
	 * @return bool
	 */
	public static function rest_permission_check() {
		return current_user_can( self::MANAGE );
	}
}
