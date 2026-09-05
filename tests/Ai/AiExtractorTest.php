<?php

namespace Uws\Tests\Ai;

use PHPUnit\Framework\TestCase;
use Uws\Ai\AiClientInterface;
use Uws\Ai\AiExtractor;
use Uws\Dto\ProductData;

final class FakeAiClient implements AiClientInterface {
	public $last_call_count = 0;
	private $response;

	public function __construct( $response ) {
		$this->response = $response;
	}

	public function complete( $system_prompt, $user_content ) {
		$this->last_call_count++;
		return $this->response;
	}
}

final class AiExtractorTest extends TestCase {

	public function test_skips_api_call_when_nothing_is_missing() {
		$client = new FakeAiClient( '{}' );
		$extractor = new AiExtractor( $client );

		$data = new ProductData();
		$data->set_field( 'name', 'Confident Name', 0.9 );
		$data->set_field( 'sku', 'SKU-1', 0.9 );
		$data->set_field( 'brand', 'Acme', 0.9 );
		$data->set_field( 'regular_price', '10', 0.9 );
		$data->set_field( 'description', 'A long description', 0.9 );
		$data->attributes = array( new \Uws\Dto\ProductAttribute( 'k', 'v' ) );
		$data->images     = array( array( 'url' => 'https://example.com/a.jpg', 'is_main' => true, 'variation_key' => null ) );

		$result = $extractor->fill_gaps( array( 'html' => '<html></html>' ), $data );

		$this->assertFalse( $result['used_ai'] );
		$this->assertSame( 0, $client->last_call_count );
	}

	public function test_fills_missing_name_from_ai_without_touching_confident_price() {
		$client = new FakeAiClient(
			wp_json_encode(
				array(
					'name'  => 'AI-Discovered Name',
					'price' => '999',
					'sku'   => '',
				)
			)
		);
		$extractor = new AiExtractor( $client );

		$data = new ProductData();
		// name is missing entirely; price is already confidently known.
		$data->set_field( 'regular_price', '49.99', 0.95 );

		$result = $extractor->fill_gaps( array( 'html' => '<html><body>Some page text</body></html>' ), $data );

		$this->assertTrue( $result['used_ai'] );
		$this->assertSame( 1, $client->last_call_count );
		$this->assertSame( 'AI-Discovered Name', $result['data']->name );
		// AI's "999" must NOT overwrite the already-confident price.
		$this->assertSame( '49.99', $result['data']->regular_price );
	}

	public function test_handles_ai_error_gracefully_without_crashing() {
		$client = new class implements AiClientInterface {
			public function complete( $system_prompt, $user_content ) {
				return new \WP_Error( 'uws_ai_error', 'boom' );
			}
		};
		$extractor = new AiExtractor( $client );

		$data = new ProductData();
		$result = $extractor->fill_gaps( array( 'html' => '<html></html>' ), $data );

		$this->assertTrue( $result['used_ai'] );
		$this->assertSame( 'boom', $result['error'] );
		$this->assertSame( '', $result['data']->name );
	}

	public function test_from_settings_returns_null_when_provider_disabled() {
		$this->assertNull( AiExtractor::from_settings( array( 'ai_provider' => 'none', 'ai_api_key' => 'x' ) ) );
		$this->assertNull( AiExtractor::from_settings( array( 'ai_provider' => 'anthropic', 'ai_api_key' => '' ) ) );
	}

	public function test_from_settings_returns_instance_when_configured() {
		$extractor = AiExtractor::from_settings( array( 'ai_provider' => 'anthropic', 'ai_api_key' => 'sk-test' ) );
		$this->assertInstanceOf( AiExtractor::class, $extractor );
	}
}
