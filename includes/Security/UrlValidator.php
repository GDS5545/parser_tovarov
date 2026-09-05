<?php
/**
 * SSRF-safe validation for any URL supplied by a user or discovered by the
 * scraper before it is ever dispatched to the worker (spec §39, §66).
 *
 * A URL is accepted only if:
 *  - it parses cleanly and uses http/https,
 *  - it carries no embedded credentials (user:pass@host),
 *  - its hostname resolves to at least one address, and every resolved
 *    address is a public, routable IP (no loopback/private/link-local/
 *    multicast/reserved ranges).
 *
 * This blocks the common SSRF vectors (http://127.0.0.1, http://169.254.169.254/
 * for cloud metadata, http://[::1], internal hostnames that resolve to RFC1918
 * addresses, etc.) without trying to defeat CAPTCHAs or anti-bot systems,
 * which this plugin explicitly does not attempt (spec §36).
 *
 * @package Uws\Security
 */

namespace Uws\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UrlValidator {

	const ALLOWED_SCHEMES = array( 'http', 'https' );

	/**
	 * @param string $url Raw URL supplied by a user or extracted from a page.
	 * @return true|\WP_Error
	 */
	public static function validate( $url ) {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return new \WP_Error( 'uws_invalid_url', __( 'URL is empty.', 'universal-woo-scraper' ) );
		}

		$parts = wp_parse_url( trim( $url ) );
		if ( false === $parts || empty( $parts['host'] ) ) {
			return new \WP_Error( 'uws_invalid_url', __( 'The URL could not be parsed.', 'universal-woo-scraper' ) );
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		if ( ! in_array( $scheme, self::ALLOWED_SCHEMES, true ) ) {
			return new \WP_Error(
				'uws_invalid_scheme',
				__( 'Only http and https URLs are allowed.', 'universal-woo-scraper' )
			);
		}

		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return new \WP_Error(
				'uws_credentials_in_url',
				__( 'URLs containing credentials are not allowed.', 'universal-woo-scraper' )
			);
		}

		$host = $parts['host'];

		// Literal IPs: validate directly without a DNS round trip.
		if ( filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) ) {
			if ( self::is_disallowed_ip( trim( $host, '[]' ) ) ) {
				return self::blocked_error();
			}
			return true;
		}

		$addresses = self::resolve_all( $host );
		if ( empty( $addresses ) ) {
			return new \WP_Error(
				'uws_dns_failure',
				__( 'The host name could not be resolved.', 'universal-woo-scraper' )
			);
		}

		foreach ( $addresses as $address ) {
			if ( self::is_disallowed_ip( $address ) ) {
				return self::blocked_error();
			}
		}

		return true;
	}

	/**
	 * @return \WP_Error
	 */
	private static function blocked_error() {
		return new \WP_Error(
			'uws_ssrf_blocked',
			__( 'This URL resolves to a private, loopback, or otherwise non-public address and cannot be scraped.', 'universal-woo-scraper' )
		);
	}

	/**
	 * Resolves a hostname to both A and AAAA records. Falls back to
	 * gethostbynamel() when dns_get_record() is disabled (some hosts
	 * restrict DNS functions).
	 *
	 * @param string $host
	 * @return string[]
	 */
	private static function resolve_all( $host ) {
		$addresses = array();

		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_A + DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( ! empty( $record['ip'] ) ) {
						$addresses[] = $record['ip'];
					} elseif ( ! empty( $record['ipv6'] ) ) {
						$addresses[] = $record['ipv6'];
					}
				}
			}
		}

		if ( empty( $addresses ) && function_exists( 'gethostbynamel' ) ) {
			$ipv4 = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_array( $ipv4 ) ) {
				$addresses = $ipv4;
			}
		}

		return array_unique( $addresses );
	}

	/**
	 * @param string $ip
	 * @return bool True if the IP is loopback, private, link-local, multicast,
	 *              reserved, or otherwise not a public routable address.
	 */
	private static function is_disallowed_ip( $ip ) {
		$is_public = filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);

		if ( false === $is_public ) {
			return true;
		}

		// FILTER_FLAG_NO_RES_RANGE does not reliably catch link-local/
		// multicast/loopback on every PHP build; double-check explicitly.
		$disallowed_v4_prefixes = array( '127.', '169.254.', '0.', '224.', '240.' );
		foreach ( $disallowed_v4_prefixes as $prefix ) {
			if ( 0 === strpos( $ip, $prefix ) ) {
				return true;
			}
		}

		if ( false !== strpos( $ip, ':' ) ) {
			$lower = strtolower( $ip );
			if ( '::1' === $lower || 0 === strpos( $lower, 'fe80:' ) || 0 === strpos( $lower, 'fc' ) || 0 === strpos( $lower, 'fd' ) ) {
				return true;
			}
		}

		return false;
	}
}
