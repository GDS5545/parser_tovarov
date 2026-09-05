<?php
/**
 * Resolves a possibly-relative URL found in scraped markup against the
 * page's own final URL (after redirects). Needed because <img src="/a.jpg">,
 * srcset entries, and background-image URLs are routinely relative.
 *
 * @package Uws\Support
 */

namespace Uws\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UrlResolver {

	/**
	 * @param string $base Absolute URL of the page the fragment was found on.
	 * @param string $url  Possibly relative URL/path.
	 * @return string Absolute URL, or the original string if $base can't be parsed.
	 */
	public static function resolve( $base, $url ) {
		$url = trim( $url );
		if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
			return $url;
		}

		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}

		$base_parts = wp_parse_url( $base );
		if ( empty( $base_parts['scheme'] ) || empty( $base_parts['host'] ) ) {
			return $url;
		}

		$origin = $base_parts['scheme'] . '://' . $base_parts['host'] . ( isset( $base_parts['port'] ) ? ':' . $base_parts['port'] : '' );

		if ( 0 === strpos( $url, '//' ) ) {
			return $base_parts['scheme'] . ':' . $url;
		}

		if ( 0 === strpos( $url, '/' ) ) {
			return $origin . $url;
		}

		$base_path = isset( $base_parts['path'] ) ? $base_parts['path'] : '/';
		$directory = substr( $base_path, 0, strrpos( $base_path, '/' ) + 1 );

		return $origin . $directory . $url;
	}
}
