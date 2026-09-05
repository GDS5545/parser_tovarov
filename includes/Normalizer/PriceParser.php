<?php
/**
 * Parses a raw price string in any of the common formats a scraped page
 * might use ($100, 100 USD, €99, ₸ 50 000, 50 000 KZT, 50 000 тг,
 * "1,234.56", "1 234,56") into a plain numeric amount plus an ISO 4217
 * currency code, without ever guessing at an exchange rate (spec §17–18:
 * the price *formula* — markup/rounding — is a separate, explicit step
 * applied later by the importer, never silently here).
 *
 * @package Uws\Normalizer
 */

namespace Uws\Normalizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PriceParser {

	/** @var array<string,string> Symbol/word => ISO 4217 code. */
	const CURRENCY_MAP = array(
		'$'   => 'USD',
		'usd' => 'USD',
		'€'   => 'EUR',
		'eur' => 'EUR',
		'£'   => 'GBP',
		'gbp' => 'GBP',
		'₸'   => 'KZT',
		'kzt' => 'KZT',
		'тг'  => 'KZT',
		'тенге' => 'KZT',
		'₽'   => 'RUB',
		'rub' => 'RUB',
		'руб' => 'RUB',
		'р.'  => 'RUB',
	);

	/**
	 * @param string $raw
	 * @return array{amount: float|null, currency: string|null}
	 */
	public static function parse( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return array( 'amount' => null, 'currency' => null );
		}

		$currency = self::detect_currency( $raw );
		$amount   = self::detect_amount( $raw );

		return array( 'amount' => $amount, 'currency' => $currency );
	}

	/**
	 * @param string $raw
	 * @return string|null
	 */
	private static function detect_currency( $raw ) {
		$lower = mb_strtolower( $raw );
		foreach ( self::CURRENCY_MAP as $needle => $code ) {
			if ( false !== mb_strpos( $lower, mb_strtolower( $needle ) ) ) {
				return $code;
			}
		}
		return null;
	}

	/**
	 * @param string $raw
	 * @return float|null
	 */
	private static function detect_amount( $raw ) {
		// Strip everything except digits, separators, and the minus sign.
		$stripped = preg_replace( '/[^0-9.,\s-]/u', '', $raw );
		$stripped = trim( $stripped );

		if ( '' === $stripped ) {
			return null;
		}

		// Collapse spaces used as thousands separators ("50 000" -> "50000").
		$stripped = preg_replace( '/(\d)\s+(?=\d)/u', '$1', $stripped );

		$last_comma = strrpos( $stripped, ',' );
		$last_dot   = strrpos( $stripped, '.' );

		if ( false !== $last_comma && false !== $last_dot ) {
			// Whichever separator appears last is the decimal point.
			if ( $last_comma > $last_dot ) {
				$stripped = str_replace( '.', '', $stripped );
				$stripped = str_replace( ',', '.', $stripped );
			} else {
				$stripped = str_replace( ',', '', $stripped );
			}
		} elseif ( false !== $last_comma ) {
			// A single comma with exactly 2 trailing digits is a decimal
			// separator ("99,90"); otherwise it's a thousands separator
			// ("50,000").
			$decimals = strlen( $stripped ) - $last_comma - 1;
			$stripped = ( 2 === $decimals )
				? str_replace( ',', '.', $stripped )
				: str_replace( ',', '', $stripped );
		}

		return is_numeric( $stripped ) ? (float) $stripped : null;
	}
}
