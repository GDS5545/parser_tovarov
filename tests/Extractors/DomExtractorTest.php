<?php

namespace Uws\Tests\Extractors;

use PHPUnit\Framework\TestCase;
use Uws\Extractors\DomExtractor;

final class DomExtractorTest extends TestCase {

	private function extract( $html ) {
		return ( new DomExtractor() )->extract( array( 'html' => $html ) );
	}

	public function test_single_price_is_used_as_regular_price() {
		$html = '<h1>Product</h1><span class="price">808 руб</span>';
		$data = $this->extract( $html );

		$this->assertSame( '808', $data->regular_price );
		$this->assertNull( $data->sale_price );
	}

	public function test_exactly_two_prices_are_paired_as_regular_and_sale() {
		$html = '<h1>Product</h1><span class="price-old">250 USD</span><span class="price">199 USD</span>';
		$data = $this->extract( $html );

		$this->assertSame( '250', $data->regular_price );
		$this->assertSame( '199', $data->sale_price );
	}

	public function test_three_or_more_unrelated_prices_are_not_paired_as_sale() {
		// Regression case: a real page had a "price"-classed element with
		// the real price plus two unrelated numbers elsewhere on the page
		// (e.g. a related-product widget) that also matched the class
		// heuristic; pairing max/min of all of them produced a wrong
		// "sale price" that didn't exist.
		$html = '<h1>Product</h1>
			<span class="price">23820</span>
			<div class="related-product"><span class="price">106506</span></div>
			<div class="related-product"><span class="price">45000</span></div>';

		$data = $this->extract( $html );

		$this->assertSame( '23820', $data->regular_price );
		$this->assertNull( $data->sale_price );
		$this->assertLessThan( 0.4, $data->confidence['regular_price'] );
	}

	public function test_prefers_scoped_product_info_container_when_present() {
		$html = '<h1>Product</h1>
			<div class="unrelated-widget"><span class="price">999</span></div>
			<div class="product-summary"><span class="price">777</span></div>';

		$data = $this->extract( $html );

		$this->assertSame( '777', $data->regular_price );
	}

	public function test_falls_back_to_whole_page_when_container_has_no_price() {
		$html = '<h1>Product</h1>
			<div class="product-summary"><p>No price node here.</p></div>
			<span class="price">555</span>';

		$data = $this->extract( $html );

		$this->assertSame( '555', $data->regular_price );
	}
}
