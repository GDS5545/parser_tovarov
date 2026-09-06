<?php
/**
 * Applies a merchant-configured Site Template (spec §47: per-domain XPath
 * overrides saved via the "Site Templates" admin page) when one exists for
 * the page's domain. This is the highest-priority extractor by design:
 * once a human has looked at a specific site and said "the name is here,
 * the price is here", that instruction should win over every automatic
 * heuristic — JSON-LD included — because generic extraction is
 * necessarily a guess and this isn't.
 *
 * Selectors are XPath (not CSS) expressions: every browser's DevTools can
 * produce one directly (right-click an element → Copy → Copy XPath in
 * Chrome/Edge, or Copy → XPath in Firefox), so this needs no CSS-to-XPath
 * translation layer to get right, and a user pointing at the wrong node
 * fails obviously (empty result) rather than silently matching something
 * else the way a loose CSS selector can.
 *
 * @package Uws\Extractors
 */

namespace Uws\Extractors;

use Uws\Database\SourceRepository;
use Uws\Dto\ProductAttribute;
use Uws\Dto\ProductData;
use Uws\Support\HtmlDocument;
use Uws\Support\UrlResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ManualSelectorExtractor implements ProductExtractorInterface {

	/** Explicit merchant configuration always wins over automatic guesses, JSON-LD included. */
	const CONFIDENCE = 1.0;

	/** @var SourceRepository */
	private $sources;

	/**
	 * Per-domain selectors cache, keyed by domain — an instance property
	 * (not static) so it only ever reflects this extractor instance's own
	 * repository/lifetime: supports() and extract() both call
	 * selectors_for() for the same page, so this avoids a second
	 * identical lookup within that one pair of calls without risking
	 * stale data leaking across a different instance (e.g. in tests, or a
	 * future long-running process that constructs a fresh extractor per
	 * request as the pipeline does today).
	 */
	private $cache = array();

	public function __construct( SourceRepository $sources = null ) {
		$this->sources = $sources ?: new SourceRepository();
	}

	public function get_name() {
		return 'manual_selector';
	}

	public function get_priority() {
		return 200; // Highest: processed first, so its findings occupy the "main"/first slot for list fields too.
	}

	public function supports( array $page ) {
		return ! empty( $page['html'] ) && ! empty( $this->selectors_for( $page ) );
	}

	public function extract( array $page ) {
		$data      = new ProductData();
		$selectors = $this->selectors_for( $page );
		if ( empty( $selectors ) ) {
			return $data;
		}

		$xpath = HtmlDocument::xpath( (string) $page['html'] );
		$base  = ! empty( $page['final_url'] ) ? $page['final_url'] : '';

		$this->apply_text_field( $data, $xpath, $selectors, 'name', 'name' );
		$this->apply_text_field( $data, $xpath, $selectors, 'sku', 'sku' );
		$this->apply_text_field( $data, $xpath, $selectors, 'brand', 'brand' );
		$this->apply_text_field( $data, $xpath, $selectors, 'price', 'regular_price' );
		$this->apply_text_field( $data, $xpath, $selectors, 'sale_price', 'sale_price' );
		$this->apply_html_field( $data, $xpath, $selectors, 'description', 'description' );
		$this->apply_html_field( $data, $xpath, $selectors, 'short_description', 'short_description' );
		$this->apply_specifications( $data, $xpath, $selectors );
		$this->apply_images( $data, $xpath, $selectors, $base );
		$this->apply_categories( $data, $xpath, $selectors );

		return $data;
	}

	/**
	 * @param array<string,mixed> $page
	 * @return array<string,string>
	 */
	private function selectors_for( array $page ) {
		$domain = ! empty( $page['final_url'] ) ? (string) ( wp_parse_url( $page['final_url'], PHP_URL_HOST ) ?: '' ) : '';
		if ( '' === $domain ) {
			return array();
		}

		if ( ! array_key_exists( $domain, $this->cache ) ) {
			$template               = $this->sources->find_for_host( $domain );
			$this->cache[ $domain ] = $template ? $template->selectors : array();
		}

		return $this->cache[ $domain ];
	}

	private function apply_text_field( ProductData $data, \DOMXPath $xpath, array $selectors, $key, $field ) {
		if ( empty( $selectors[ $key ] ) ) {
			return;
		}
		$node = $this->first_node( $xpath, $selectors[ $key ] );
		if ( $node ) {
			$data->set_field( $field, HtmlDocument::text( $node ), self::CONFIDENCE );
		}
	}

	private function apply_html_field( ProductData $data, \DOMXPath $xpath, array $selectors, $key, $field ) {
		if ( empty( $selectors[ $key ] ) ) {
			return;
		}
		$node = $this->first_node( $xpath, $selectors[ $key ] );
		if ( $node ) {
			$data->set_field( $field, HtmlDocument::inner_html( $node ), self::CONFIDENCE );
		}
	}

	private function apply_specifications( ProductData $data, \DOMXPath $xpath, array $selectors ) {
		if ( empty( $selectors['specifications'] ) ) {
			return;
		}
		$container = $this->first_node( $xpath, $selectors['specifications'] );
		if ( ! $container ) {
			return;
		}

		foreach ( $xpath->query( './/tr', $container ) as $row ) {
			$cells = $xpath->query( './th|./td', $row );
			if ( $cells->length < 2 ) {
				continue;
			}
			$this->maybe_add_attribute( $data, HtmlDocument::text( $cells->item( 0 ) ), HtmlDocument::text( $cells->item( $cells->length - 1 ) ) );
		}

		foreach ( $xpath->query( './/dl', $container ) as $list ) {
			$terms       = $xpath->query( './dt', $list );
			$definitions = $xpath->query( './dd', $list );
			$count       = min( $terms->length, $definitions->length );
			for ( $i = 0; $i < $count; $i++ ) {
				$this->maybe_add_attribute( $data, HtmlDocument::text( $terms->item( $i ) ), HtmlDocument::text( $definitions->item( $i ) ) );
			}
		}
	}

	private function maybe_add_attribute( ProductData $data, $key, $value ) {
		if ( '' === $key || '' === $value ) {
			return;
		}
		$attribute             = new ProductAttribute( $key, $value, $this->get_name() );
		$attribute->confidence = self::CONFIDENCE;
		$data->attributes[]    = $attribute;
	}

	private function apply_images( ProductData $data, \DOMXPath $xpath, array $selectors, $base ) {
		if ( empty( $selectors['images'] ) ) {
			return;
		}

		$nodes = $xpath->query( $selectors['images'] );
		$seen  = array();

		foreach ( $nodes as $node ) {
			$img = $this->as_img_element( $xpath, $node );
			if ( ! $img ) {
				continue;
			}

			$candidate = $img->getAttribute( 'src' ) ?: $img->getAttribute( 'data-src' ) ?: $img->getAttribute( 'data-lazy-src' );
			if ( '' === $candidate ) {
				continue;
			}

			$absolute = $base ? UrlResolver::resolve( $base, $candidate ) : $candidate;
			if ( isset( $seen[ $absolute ] ) ) {
				continue;
			}
			$seen[ $absolute ] = true;
			$data->images[]    = array( 'url' => $absolute, 'is_main' => empty( $data->images ), 'variation_key' => null );
		}
	}

	/**
	 * @param \DOMXPath $xpath
	 * @param \DOMNode  $node The node the selector matched — either an <img> itself or a container.
	 * @return \DOMElement|null
	 */
	private function as_img_element( \DOMXPath $xpath, \DOMNode $node ) {
		if ( $node instanceof \DOMElement && 'img' === strtolower( $node->tagName ) ) {
			return $node;
		}
		if ( $node instanceof \DOMElement ) {
			$descendant = $xpath->query( './/img', $node );
			if ( $descendant->length > 0 ) {
				return $descendant->item( 0 );
			}
		}
		return null;
	}

	private function apply_categories( ProductData $data, \DOMXPath $xpath, array $selectors ) {
		if ( empty( $selectors['categories'] ) ) {
			return;
		}
		$nodes = $xpath->query( $selectors['categories'] );
		$path  = array();
		foreach ( $nodes as $node ) {
			$text = HtmlDocument::text( $node );
			if ( '' !== $text ) {
				$path[] = $text;
			}
		}
		if ( ! empty( $path ) ) {
			$data->categories               = $path;
			$data->confidence['categories'] = self::CONFIDENCE;
		}
	}

	/**
	 * @param \DOMXPath $xpath
	 * @param string    $expression
	 * @return \DOMElement|null First matching element, or null if the
	 *                          expression is invalid or matches nothing.
	 */
	private function first_node( \DOMXPath $xpath, $expression ) {
		$nodes = @$xpath->query( $expression ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- an invalid XPath from user input must not fatal the whole analyze request.
		if ( ! $nodes || 0 === $nodes->length ) {
			return null;
		}
		return $nodes->item( 0 );
	}
}
