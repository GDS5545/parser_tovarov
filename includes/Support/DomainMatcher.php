<?php
/**
 * Decides whether two hostnames belong to "the same site" for the
 * purposes of deciding whether an image found on a page is plausibly a
 * product photo (own domain or own CDN subdomain) versus unrelated
 * third-party content (a social network badge, an analytics beacon, a
 * widget embed) that happens to appear as an <img> tag somewhere on the
 * page.
 *
 * This is a pragmatic same-registrable-domain comparison, not a full
 * Public Suffix List implementation: it special-cases the handful of
 * two-part ccTLD suffixes (co.uk, com.au, etc.) common enough to matter
 * here and otherwise compares the last two labels. A real PSL lookup
 * would be more correct for exotic TLDs; this is good enough for the
 * "is this image on my own site or a completely different one" question
 * it's actually used for.
 *
 * @package Uws\Support
 */

namespace Uws\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DomainMatcher {

	/** Two-label public suffixes where the registrable domain needs 3 labels, not 2. */
	const TWO_PART_SUFFIXES = array( 'co.uk', 'org.uk', 'com.au', 'co.jp', 'co.nz', 'com.br', 'co.za', 'com.tr' );

	/**
	 * @param string $host_a
	 * @param string $host_b
	 * @return bool True if both hosts share the same registrable domain
	 *              (so "cdn.example.com" matches "www.example.com").
	 */
	public static function same_site( $host_a, $host_b ) {
		$a = self::registrable_domain( $host_a );
		$b = self::registrable_domain( $host_b );
		return '' !== $a && $a === $b;
	}

	/**
	 * @param string $host
	 * @return string
	 */
	private static function registrable_domain( $host ) {
		$host   = strtolower( trim( $host, '.' ) );
		$labels = explode( '.', $host );
		$count  = count( $labels );

		if ( $count < 2 ) {
			return $host;
		}

		$last_two = $labels[ $count - 2 ] . '.' . $labels[ $count - 1 ];
		if ( $count >= 3 && in_array( $last_two, self::TWO_PART_SUFFIXES, true ) ) {
			return $labels[ $count - 3 ] . '.' . $last_two;
		}

		return $last_two;
	}
}
