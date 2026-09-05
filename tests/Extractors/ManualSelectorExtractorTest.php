<?php

namespace Uws\Tests\Extractors;

use PHPUnit\Framework\TestCase;
use Uws\Database\SourceRepository;
use Uws\Extractors\ManualSelectorExtractor;

final class ManualSelectorExtractorTest extends TestCase {

	/**
	 * @param array<string,string> $selectors
	 * @return SourceRepository
	 */
	private function fake_repository( array $selectors ) {
		return new class( $selectors ) extends SourceRepository {
			private $selectors;
			public function __construct( array $selectors ) {
				$this->selectors = $selectors;
			}
			public function find( $domain ) {
				if ( empty( $this->selectors ) ) {
					return null;
				}
				return (object) array( 'domain' => $domain, 'selectors' => $this->selectors );
			}
		};
	}

	public function test_no_template_means_extractor_does_not_support_the_page() {
		$extractor = new ManualSelectorExtractor( $this->fake_repository( array() ) );
		$page      = array( 'html' => '<html><body><h1>X</h1></body></html>', 'final_url' => 'https://example.com/p' );

		$this->assertFalse( $extractor->supports( $page ) );
	}

	public function test_extracts_name_sku_and_price_via_xpath() {
		$html = '<html><body>
			<h1 id="pagetitle">Кассета фильтрующая БАРЬЕР</h1>
			<div class="sku-box">Артикул: 6703636560</div>
			<span class="price-value">808</span>
		</body></html>';

		$repository = $this->fake_repository(
			array(
				'name'  => '//h1[@id="pagetitle"]',
				'sku'   => '//div[@class="sku-box"]',
				'price' => '//span[@class="price-value"]',
			)
		);

		$data = ( new ManualSelectorExtractor( $repository ) )->extract( array( 'html' => $html, 'final_url' => 'https://habarovsk.example.ru/p' ) );

		$this->assertSame( 'Кассета фильтрующая БАРЬЕР', $data->name );
		$this->assertSame( 'Артикул: 6703636560', $data->sku );
		$this->assertSame( '808', $data->regular_price );
		$this->assertSame( 1.0, $data->confidence['name'] );
	}

	public function test_image_selector_pointing_at_container_finds_descendant_img() {
		$html = '<div class="detail_picture"><img src="/uploadedFiles/eshopimages/big/6703636560.jpg" /></div>';

		$repository = $this->fake_repository( array( 'images' => '//div[@class="detail_picture"]' ) );
		$data       = ( new ManualSelectorExtractor( $repository ) )->extract( array( 'html' => $html, 'final_url' => 'https://habarovsk.example.ru/p' ) );

		$this->assertCount( 1, $data->images );
		$this->assertStringContainsString( '6703636560.jpg', $data->images[0]['url'] );
		$this->assertTrue( $data->images[0]['is_main'] );
	}

	public function test_image_selector_pointing_directly_at_img_works() {
		$html = '<img class="main-photo" src="/img/product.jpg" />';

		$repository = $this->fake_repository( array( 'images' => '//img[@class="main-photo"]' ) );
		$data       = ( new ManualSelectorExtractor( $repository ) )->extract( array( 'html' => $html, 'final_url' => 'https://example.com/p' ) );

		$this->assertCount( 1, $data->images );
	}

	public function test_specifications_selector_reads_table_rows_within_container() {
		$html = '<div id="specs"><table>
			<tr><th>Жёсткость</th><td>до 15 °Ж</td></tr>
			<tr><th>Ресурс</th><td>350 л</td></tr>
		</table></div>
		<table><tr><th>Unrelated</th><td>Should not be picked up</td></tr></table>';

		$repository = $this->fake_repository( array( 'specifications' => '//div[@id="specs"]' ) );
		$data       = ( new ManualSelectorExtractor( $repository ) )->extract( array( 'html' => $html, 'final_url' => 'https://example.com/p' ) );

		$this->assertCount( 2, $data->attributes );
		$this->assertSame( 'Жёсткость', $data->attributes[0]->attribute_key );
	}

	public function test_description_selector_preserves_html_formatting() {
		$html = '<div class="descr"><p>Первый абзац.</p><ul><li>Пункт</li></ul></div>';

		$repository = $this->fake_repository( array( 'description' => '//div[@class="descr"]' ) );
		$data       = ( new ManualSelectorExtractor( $repository ) )->extract( array( 'html' => $html, 'final_url' => 'https://example.com/p' ) );

		$this->assertStringContainsString( '<p>', $data->description );
		$this->assertStringContainsString( '<li>', $data->description );
	}

	public function test_invalid_xpath_does_not_crash_and_yields_no_data() {
		$repository = $this->fake_repository( array( 'name' => '///[[[invalid' ) );
		$data       = ( new ManualSelectorExtractor( $repository ) )->extract( array( 'html' => '<h1>X</h1>', 'final_url' => 'https://example.com/p' ) );

		$this->assertSame( '', $data->name );
	}

	public function test_categories_selector_collects_breadcrumb_links_in_order() {
		$html = '<nav class="crumbs"><a href="/">Home</a><a href="/cat1">Насосы</a><a href="/cat1/cat2">Погружные</a></nav>';

		$repository = $this->fake_repository( array( 'categories' => '//nav[@class="crumbs"]/a' ) );
		$data       = ( new ManualSelectorExtractor( $repository ) )->extract( array( 'html' => $html, 'final_url' => 'https://example.com/p' ) );

		$this->assertSame( array( 'Home', 'Насосы', 'Погружные' ), $data->categories );
	}
}
