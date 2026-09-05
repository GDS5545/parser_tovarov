<?php
/**
 * Reads the category path from a breadcrumb navigation element (spec §9).
 * Looks for the standard accessible pattern first
 * (nav[aria-label="breadcrumb"], schema.org BreadcrumbList markup) before
 * falling back to a generic ".breadcrumb"/".breadcrumbs" class.
 *
 * @package Uws\Extractors
 */

namespace Uws\Extractors;

use Uws\Dto\ProductData;
use Uws\Support\HtmlDocument;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BreadcrumbExtractor implements ProductExtractorInterface {

	public function get_name() {
		return 'breadcrumb';
	}

	public function get_priority() {
		return 55;
	}

	public function supports( array $page ) {
		return ! empty( $page['html'] );
	}

	public function extract( array $page ) {
		$data  = new ProductData();
		$xpath = HtmlDocument::xpath( (string) $page['html'] );

		$container_queries = array(
			"//nav[contains(translate(@aria-label,'BREADCRUMB','breadcrumb'),'breadcrumb')]",
			"//*[contains(concat(' ', normalize-space(@class), ' '), ' breadcrumb ')]",
			"//*[contains(concat(' ', normalize-space(@class), ' '), ' breadcrumbs ')]",
		);

		foreach ( $container_queries as $query ) {
			$containers = $xpath->query( $query );
			if ( 0 === $containers->length ) {
				continue;
			}

			$links = $xpath->query( './/a', $containers->item( 0 ) );
			$path  = array();
			foreach ( $links as $link ) {
				$text = HtmlDocument::text( $link );
				if ( '' !== $text && ! $this->is_home_link( $text ) ) {
					$path[] = $text;
				}
			}

			if ( ! empty( $path ) ) {
				$data->categories             = $path;
				$data->confidence['categories'] = 0.65;
				break;
			}
		}

		return $data;
	}

	/**
	 * @param string $text
	 * @return bool
	 */
	private function is_home_link( $text ) {
		return in_array( mb_strtolower( trim( $text ) ), array( 'home', 'главная' ), true );
	}
}
