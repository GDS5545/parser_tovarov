<?php

namespace Uws\Tests\Extractors;

use PHPUnit\Framework\TestCase;
use Uws\Extractors\BreadcrumbExtractor;
use Uws\Extractors\DomExtractor;
use Uws\Extractors\ImageExtractor;
use Uws\Extractors\JsonLdExtractor;
use Uws\Extractors\MetaExtractor;
use Uws\Extractors\ProductDataMerger;
use Uws\Extractors\SpecificationExtractor;

final class ProductDataMergerTest extends TestCase {

	private function extractors() {
		return array(
			new JsonLdExtractor(),
			new MetaExtractor(),
			new SpecificationExtractor(),
			new ImageExtractor(),
			new BreadcrumbExtractor(),
			new DomExtractor(),
		);
	}

	public function test_json_ld_price_wins_over_dom_heuristic_price() {
		$json_ld = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@type'    => 'Product',
				'name'     => 'Submersible Water Pump',
				'sku'      => 'PUMP-100',
				'offers'   => array( 'price' => '199.99', 'priceCurrency' => 'USD' ),
			)
		);

		$html = '<html><body>
			<h1>Submersible Water Pump (DOM title)</h1>
			<div class="price-old">250.00 USD</div>
			<script type="application/ld+json">' . $json_ld . '</script>
		</body></html>';

		$page = array(
			'html'           => $html,
			'final_url'      => 'https://example.com/product/pump',
			'json_ld_blocks' => array( $json_ld ),
		);

		$data = ( new ProductDataMerger() )->merge( $page, $this->extractors() );

		$this->assertSame( 'Submersible Water Pump', $data->name );
		$this->assertSame( '199.99', $data->regular_price );
		$this->assertSame( 'USD', $data->currency );
		$this->assertSame( 'PUMP-100', $data->sku );
	}

	public function test_specification_table_becomes_attributes() {
		$html = '<html><body>
			<h1>Pump</h1>
			<table>
				<tr><th>Voltage</th><td>220 V</td></tr>
				<tr><th>Power</th><td>1500 W</td></tr>
			</table>
		</body></html>';

		$page = array( 'html' => $html, 'final_url' => 'https://example.com/p', 'json_ld_blocks' => array() );

		$data = ( new ProductDataMerger() )->merge( $page, $this->extractors() );

		$this->assertCount( 2, $data->attributes );
		$this->assertSame( 'Voltage', $data->attributes[0]->attribute_key );
		$this->assertSame( '220 V', $data->attributes[0]->value_raw );
	}

	public function test_json_ld_image_wins_main_slot_over_dom_gallery_image() {
		$json_ld = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@type'    => 'Product',
				'name'     => 'Pump',
				'image'    => 'https://example.com/authoritative-main.jpg',
			)
		);

		$html = '<html><body>
			<h1>Pump</h1>
			<img src="https://example.com/gallery-photo-1.jpg" />
			<script type="application/ld+json">' . $json_ld . '</script>
		</body></html>';

		$page = array( 'html' => $html, 'final_url' => 'https://example.com/p', 'json_ld_blocks' => array( $json_ld ) );

		$data = ( new ProductDataMerger() )->merge( $page, $this->extractors() );

		$this->assertSame( 'https://example.com/authoritative-main.jpg', $data->images[0]['url'] );
		$this->assertTrue( $data->images[0]['is_main'] );
		$this->assertFalse( $data->images[1]['is_main'] );
	}

	public function test_breadcrumb_becomes_categories() {
		$html = '<html><body>
			<nav aria-label="breadcrumb"><a href="/">Home</a><a href="/equip">Equipment</a><a href="/equip/pumps">Pumps</a></nav>
			<h1>Pump</h1>
		</body></html>';

		$page = array( 'html' => $html, 'final_url' => 'https://example.com/p', 'json_ld_blocks' => array() );

		$data = ( new ProductDataMerger() )->merge( $page, $this->extractors() );

		$this->assertSame( array( 'Equipment', 'Pumps' ), $data->categories );
	}
}
