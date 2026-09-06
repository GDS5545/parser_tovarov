<?php

namespace Uws\Tests\Pipeline;

use PHPUnit\Framework\TestCase;
use Uws\Database\SourceRepository;
use Uws\Pipeline\ExtractionPipeline;
use Uws\Scraper\ScraperEngineInterface;

final class FakeEngine implements ScraperEngineInterface {
	private $page;
	public function __construct( array $page ) {
		$this->page = $page;
	}
	public function fetch_page( $url, array $options = array() ) {
		return $this->page;
	}
	public function discover_product_urls( $url, array $options = array() ) {
		return array();
	}
	public function health_check() {
		return true;
	}
}

final class ExtractionPipelineTest extends TestCase {

	private function fake_repository( array $selectors ) {
		return new class( $selectors ) extends SourceRepository {
			private $selectors;
			public function __construct( array $selectors ) {
				$this->selectors = $selectors;
			}
			public function find( $domain ) {
				return empty( $this->selectors ) ? null : (object) array( 'domain' => $domain, 'selectors' => $this->selectors );
			}
			public function all() {
				return array();
			}
		};
	}

	public function test_manual_image_selector_excludes_generic_image_extractor_findings() {
		$html = '<html><body>
			<h1>Product</h1>
			<div class="detail_picture"><img src="/uploadedFiles/eshopimages/big/real.jpg" /></div>
			<img src="/uploadedFiles/images/unrelated-carousel.jpg" />
		</body></html>';

		$page = array( 'html' => $html, 'final_url' => 'https://habarovsk.vagner-ural.ru/p', 'json_ld_blocks' => array() );

		$repository = $this->fake_repository( array( 'images' => '//div[@class="detail_picture"]' ) );
		$pipeline   = new ExtractionPipeline( new FakeEngine( $page ), null, null, $repository );

		$result = $pipeline->analyze( 'https://habarovsk.vagner-ural.ru/p' );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['data']->images );
		$this->assertStringContainsString( 'real.jpg', $result['data']->images[0]['url'] );
	}

	public function test_without_a_site_template_generic_extractors_run_normally() {
		$html = '<html><body><h1>Product</h1><img src="/img/a.jpg" /></body></html>';
		$page = array( 'html' => $html, 'final_url' => 'https://example.com/p', 'json_ld_blocks' => array() );

		$pipeline = new ExtractionPipeline( new FakeEngine( $page ), null, null, $this->fake_repository( array() ) );
		$result   = $pipeline->analyze( 'https://example.com/p' );

		$this->assertSame( 'Product', $result['data']->name );
		$this->assertCount( 1, $result['data']->images );
	}

	public function test_analyze_uses_a_prefetched_page_instead_of_fetching_again() {
		$html = '<html><body><h1>Product</h1></body></html>';
		$page = array( 'html' => $html, 'final_url' => 'https://example.com/p', 'json_ld_blocks' => array() );

		// FakeEngine would return this same page anyway, but a spy engine
		// proves fetch_page() was never called when a prefetched page is
		// supplied — exactly what the category-tree crawler relies on to
		// avoid fetching a listing page twice (once to classify it, again
		// to analyze it once it turns out to already be a product page).
		$engine = new class( $page ) implements \Uws\Scraper\ScraperEngineInterface {
			public $fetch_calls = 0;
			private $page;
			public function __construct( array $page ) { $this->page = $page; }
			public function fetch_page( $url, array $options = array() ) {
				$this->fetch_calls++;
				return $this->page;
			}
			public function discover_product_urls( $url, array $options = array() ) { return array(); }
			public function health_check() { return true; }
		};

		$pipeline = new ExtractionPipeline( $engine, null, null, $this->fake_repository( array() ) );
		$result   = $pipeline->analyze( 'https://example.com/p', array(), $page );

		$this->assertSame( 'Product', $result['data']->name );
		$this->assertSame( 0, $engine->fetch_calls );
	}

	public function test_looks_like_product_page_is_true_when_json_ld_declares_a_product() {
		$html = '<html><head><script type="application/ld+json">{"@type":"Product","name":"Widget","offers":{"price":"9.99"}}</script></head><body></body></html>';
		$page = array(
			'html'           => $html,
			'final_url'      => 'https://example.com/p',
			'json_ld_blocks' => array( '{"@type":"Product","name":"Widget","offers":{"price":"9.99"}}' ),
		);

		$pipeline = new ExtractionPipeline( new FakeEngine( $page ), null, null, $this->fake_repository( array() ) );

		$this->assertTrue( $pipeline->looks_like_product_page( $page, 'https://example.com/p' ) );
	}

	public function test_looks_like_product_page_is_false_for_a_plain_listing_page() {
		$html = '<html><body><h1>Category</h1>
			<a href="/p/1">Item one</a>
			<a href="/p/2">Item two</a>
		</body></html>';
		$page = array( 'html' => $html, 'final_url' => 'https://example.com/category', 'json_ld_blocks' => array() );

		$pipeline = new ExtractionPipeline( new FakeEngine( $page ), null, null, $this->fake_repository( array() ) );

		$this->assertFalse( $pipeline->looks_like_product_page( $page, 'https://example.com/category' ) );
	}

	public function test_looks_like_product_page_is_true_for_dom_only_page_with_name_and_price() {
		$html = '<html><body><h1>Кассета фильтрующая</h1><span class="price">808 ₽</span></body></html>';
		$page = array( 'html' => $html, 'final_url' => 'https://example.com/p', 'json_ld_blocks' => array() );

		$pipeline = new ExtractionPipeline( new FakeEngine( $page ), null, null, $this->fake_repository( array() ) );

		$this->assertTrue( $pipeline->looks_like_product_page( $page, 'https://example.com/p' ) );
	}
}
