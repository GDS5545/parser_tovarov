<?php
/**
 * Shared HTML-parsing helper for the DOM-based extractors. Wraps
 * DOMDocument/DOMXPath construction (including the UTF-8 + libxml warning
 * dance every one of them would otherwise repeat) in one place.
 *
 * @package Uws\Support
 */

namespace Uws\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HtmlDocument {

	/**
	 * @param string $html
	 * @return \DOMXPath
	 */
	public static function xpath( $html ) {
		$dom = new \DOMDocument();

		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . (string) $html );
		libxml_clear_errors();

		return new \DOMXPath( $dom );
	}

	/**
	 * @param \DOMNode $node
	 * @return string Trimmed, whitespace-collapsed text content.
	 */
	public static function text( \DOMNode $node ) {
		return trim( preg_replace( '/\s+/u', ' ', $node->textContent ) );
	}
}
