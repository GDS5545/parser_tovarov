<?php
/**
 * Minimal PSR-4 autoloader fallback for `Uws\` when Composer's vendor/
 * directory has not been generated (e.g. plugin installed as a plain ZIP
 * without running `composer install`). Mirrors composer.json's mapping:
 * Uws\ => includes/.
 *
 * @package Uws
 */

namespace Uws;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	function ( $class ) {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$path     = __DIR__ . DIRECTORY_SEPARATOR . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);
