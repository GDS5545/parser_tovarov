<?php

namespace Uws\Tests\Scraper;

use PHPUnit\Framework\TestCase;
use Uws\Scraper\HttpEngine;

final class HttpEngineTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['uws_test_http_responses'] = array();
	}

	public function test_fetch_page_extracts_json_ld_from_server_rendered_html() {
		$html = '<html><body>
			<h1>Water Filter Cassette</h1>
			<script type="application/ld+json">{"@type":"Product","name":"Water Filter Cassette","offers":{"price":"350","priceCurrency":"RUB"}}</script>
		</body></html>';

		$GLOBALS['uws_test_http_responses']['https://example.com/product/x'] = array( 'body' => $html, 'status' => 200 );

		$engine = new HttpEngine();
		$page   = $engine->fetch_page( 'https://example.com/product/x' );

		$this->assertIsArray( $page );
		$this->assertCount( 1, $page['json_ld_blocks'] );
		$this->assertStringContainsString( 'Water Filter Cassette', $page['json_ld_blocks'][0] );
		$this->assertNull( $page['screenshot_base64'] );
	}

	public function test_fetch_page_rejects_ssrf_url_before_making_a_request() {
		$engine = new HttpEngine();
		$result = $engine->fetch_page( 'http://127.0.0.1/admin' );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_fetch_page_returns_error_on_http_error_status() {
		$GLOBALS['uws_test_http_responses']['https://example.com/gone'] = array( 'body' => '', 'status' => 404 );

		$engine = new HttpEngine();
		$result = $engine->fetch_page( 'https://example.com/gone' );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_discover_product_urls_filters_out_utility_links() {
		$html = '<html><body>
			<a href="/product/pump-1">Pump 1</a>
			<a href="/product/pump-2">Pump 2</a>
			<a href="/cart">Cart</a>
			<a href="/login">Login</a>
			<a href="https://other-domain.com/product/pump-3">Off-domain</a>
			<a href="/category/pumps">Same as listing page</a>
		</body></html>';

		$GLOBALS['uws_test_http_responses']['https://example.com/category/pumps'] = array( 'body' => $html, 'status' => 200 );

		$engine = new HttpEngine();
		$urls   = $engine->discover_product_urls( 'https://example.com/category/pumps' );

		$this->assertContains( 'https://example.com/product/pump-1', $urls );
		$this->assertContains( 'https://example.com/product/pump-2', $urls );
		foreach ( $urls as $url ) {
			$this->assertStringNotContainsString( 'other-domain.com', $url );
			$this->assertStringNotContainsString( '/cart', $url );
			$this->assertStringNotContainsString( '/login', $url );
		}
	}

	public function test_discover_product_urls_rejects_js_only_pagination_strategies() {
		$engine = new HttpEngine();

		$result = $engine->discover_product_urls( 'https://example.com/category/pumps', array( 'pagination_strategy' => 'load_more' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_health_check_is_always_true_no_external_dependency() {
		$this->assertTrue( ( new HttpEngine() )->health_check() );
	}
}
