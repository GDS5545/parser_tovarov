<?php
/**
 * Strips a rendered page down to plain text and caps its size before it
 * is ever sent to an AI provider (spec §22: "предусмотреть лимит размера
 * HTML/текста и защиту от огромных страниц"). Removing <script>/<style>
 * first avoids burning the token budget on JS bundles and CSS that never
 * contain product data.
 *
 * @package Uws\Ai
 */

namespace Uws\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContentCleaner {

	/** Hard ceiling regardless of what a caller requests — a runaway page must never blow the token budget. */
	const ABSOLUTE_MAX_CHARS = 20000;

	/**
	 * @param string $html
	 * @param int    $max_chars Soft limit; clamped to self::ABSOLUTE_MAX_CHARS.
	 * @return string Plain text, whitespace-collapsed, truncated.
	 */
	public static function clean( $html, $max_chars = 12000 ) {
		$max_chars = max( 500, min( self::ABSOLUTE_MAX_CHARS, (int) $max_chars ) );

		$html = (string) $html;
		$html = preg_replace( '#<script\b[^>]*>.*?</script>#is', ' ', $html );
		$html = preg_replace( '#<style\b[^>]*>.*?</style>#is', ' ', $html );
		$html = preg_replace( '#<!--.*?-->#s', ' ', $html );

		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( (string) $text );

		if ( mb_strlen( $text ) > $max_chars ) {
			$text = mb_substr( $text, 0, $max_chars ) . ' […truncated]';
		}

		return $text;
	}
}
