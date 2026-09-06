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
}
