<?php
/**
 * Finds the first element on a page whose class or id matches one of a
 * list of naming conventions (e.g. "detail_picture" for a 1C-Bitrix
 * product photo, "product-gallery" for a generic theme). Shared by any
 * extractor that needs to prefer a recognized container over scanning the
 * whole page — real-site testing showed both ImageExtractor and
 * SpecificationExtractor picking up unrelated same-page content (a
 * homepage-style photo carousel; "why choose us" marketing bullet points
 * marked up as a two-column table) when they scanned the entire document
 * indiscriminately.
 *
 * @package Uws\Support
 */

namespace Uws\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContainerFinder {

	/**
	 * @param \DOMXPath $xpath
	 * @param string[]  $hints class/id substrings (case-insensitive, ASCII only),
	 *                         checked in order — the first one present on the page wins.
	 * @return \DOMElement|null
	 */
	public static function find( \DOMXPath $xpath, array $hints ) {
		foreach ( $hints as $hint ) {
			$nodes = $xpath->query(
				"//*[contains(translate(concat(@class,' ',@id), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), '{$hint}')]"
			);
			if ( $nodes->length > 0 ) {
				return $nodes->item( 0 );
			}
		}
		return null;
	}
}
