<?php
/**
 * AI fallback extraction (spec §22–23, §56). Deliberately NOT a
 * ProductExtractorInterface member of the uniform extractor list: it
 * needs to see what the conventional extractors (JSON-LD, meta,
 * specification tables, DOM heuristics) already resolved, so it can fill
 * only the gaps — never call the API when nothing is missing, and never
 * overwrite a field another extractor was already confident about.
 * ExtractionPipeline invokes it as a distinct second pass after the
 * normal merge.
 *
 * @package Uws\Ai
 */

namespace Uws\Ai;

use Uws\Dto\ProductAttribute;
use Uws\Dto\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AiExtractor {

	const AI_CONFIDENCE = 0.55;
	const MIN_CONFIDENCE = 0.5;
	const MAX_CONTENT_CHARS = 12000;

	/** Fields worth an API call if missing/low-confidence; anything else is a "nice to have", not worth the cost. */
	const IMPORTANT_FIELDS = array( 'name', 'sku', 'brand', 'regular_price', 'description' );

	/** @var AiClientInterface */
	private $client;

	public function __construct( AiClientInterface $client ) {
		$this->client = $client;
	}

	/**
	 * @param array<string,mixed> $settings The 'uws_settings' option.
	 * @return self|null Null if AI is disabled or has no API key configured.
	 */
	public static function from_settings( array $settings ) {
		$provider = $settings['ai_provider'] ?? 'none';
		$api_key  = $settings['ai_api_key'] ?? '';

		if ( empty( $api_key ) || ! in_array( $provider, array( 'anthropic', 'openai' ), true ) ) {
			return null;
		}

		$client = 'anthropic' === $provider ? new AnthropicClient( $api_key ) : new OpenAiClient( $api_key );

		return new self( $client );
	}

	/**
	 * @param array<string,mixed> $page Raw payload from ScraperEngineInterface::fetch_page().
	 * @param ProductData         $data Already merged from the conventional extractors.
	 * @return array{data: ProductData, used_ai: bool, error: string|null}
	 */
	public function fill_gaps( array $page, ProductData $data ) {
		$missing_scalars   = $this->missing_important_fields( $data );
		$missing_attributes = empty( $data->attributes );
		$missing_images      = empty( $data->images );

		if ( empty( $missing_scalars ) && ! $missing_attributes && ! $missing_images ) {
			return array( 'data' => $data, 'used_ai' => false, 'error' => null );
		}

		$content  = ContentCleaner::clean( (string) ( $page['html'] ?? '' ), self::MAX_CONTENT_CHARS );
		$response = $this->client->complete( $this->system_prompt(), $content );

		if ( is_wp_error( $response ) ) {
			return array( 'data' => $data, 'used_ai' => true, 'error' => $response->get_error_message() );
		}

		$decoded = $this->decode_json( $response );
		if ( null === $decoded ) {
			return array( 'data' => $data, 'used_ai' => true, 'error' => __( 'AI response was not valid JSON.', 'universal-woo-scraper' ) );
		}

		$this->apply_scalars( $data, $decoded, $missing_scalars );

		if ( $missing_attributes && ! empty( $decoded['attributes'] ) && is_array( $decoded['attributes'] ) ) {
			$this->apply_attributes( $data, $decoded['attributes'] );
		}

		if ( $missing_images && ! empty( $decoded['images'] ) && is_array( $decoded['images'] ) ) {
			$this->apply_images( $data, $decoded['images'] );
		}

		return array( 'data' => $data, 'used_ai' => true, 'error' => null );
	}

	/**
	 * @param ProductData $data
	 * @return string[] Field names from self::IMPORTANT_FIELDS that are empty or below MIN_CONFIDENCE.
	 */
	private function missing_important_fields( ProductData $data ) {
		$missing = array();
		foreach ( self::IMPORTANT_FIELDS as $field ) {
			$confidence = $data->confidence[ $field ] ?? 0.0;
			if ( empty( $data->$field ) || $confidence < self::MIN_CONFIDENCE ) {
				$missing[] = $field;
			}
		}
		return $missing;
	}

	/**
	 * @param ProductData          $data
	 * @param array<string,mixed>  $decoded
	 * @param string[]             $missing_scalars
	 */
	private function apply_scalars( ProductData $data, array $decoded, array $missing_scalars ) {
		$map = array(
			'name'          => 'name',
			'sku'           => 'sku',
			'brand'         => 'brand',
			'price'         => 'regular_price',
			'description'   => 'description',
			'short_description' => 'short_description',
			'currency'      => 'currency',
		);

		foreach ( $map as $ai_key => $field ) {
			if ( ! in_array( $field, $missing_scalars, true ) && 'currency' !== $ai_key ) {
				continue; // Only fill fields we actually identified as gaps (currency rides along with price).
			}
			if ( empty( $decoded[ $ai_key ] ) ) {
				continue;
			}
			$data->set_field( $field, is_scalar( $decoded[ $ai_key ] ) ? (string) $decoded[ $ai_key ] : '', self::AI_CONFIDENCE );
		}

		if ( ! empty( $decoded['categories'] ) && is_array( $decoded['categories'] ) && empty( $data->categories ) ) {
			$data->categories               = array_values( array_map( 'strval', $decoded['categories'] ) );
			$data->confidence['categories'] = self::AI_CONFIDENCE;
		}
	}

	/**
	 * @param ProductData $data
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function apply_attributes( ProductData $data, array $rows ) {
		foreach ( $rows as $row ) {
			$key   = is_array( $row ) ? ( $row['key'] ?? $row['name'] ?? '' ) : '';
			$value = is_array( $row ) ? ( $row['value'] ?? '' ) : '';
			if ( '' === $key || '' === $value ) {
				continue;
			}
			$attribute             = new ProductAttribute( (string) $key, (string) $value, 'ai' );
			$attribute->confidence = self::AI_CONFIDENCE;
			$data->attributes[]    = $attribute;
		}
	}

	/**
	 * @param ProductData $data
	 * @param array<int,mixed> $urls
	 */
	private function apply_images( ProductData $data, array $urls ) {
		foreach ( $urls as $index => $url ) {
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}
			$data->images[] = array( 'url' => $url, 'is_main' => 0 === $index, 'variation_key' => null );
		}
	}

	/**
	 * @return string
	 */
	private function system_prompt() {
		return "You extract structured product data from raw text scraped from an e-commerce product page. " .
			"Respond with ONLY a single JSON object, no markdown fences, no commentary, matching exactly this shape " .
			"(use empty string/empty array for anything not found — never invent data that isn't in the text):\n" .
			'{"name":"","sku":"","brand":"","description":"","short_description":"","price":"","currency":"",' .
			'"categories":[],"attributes":[{"key":"","value":""}],"variations":[],"images":[]}';
	}

	/**
	 * Defensively parses the model's response as JSON, tolerating a
	 * ```json fenced block even though the prompt asks it not to.
	 *
	 * @param string $response
	 * @return array<string,mixed>|null
	 */
	private function decode_json( $response ) {
		$response = trim( (string) $response );
		if ( preg_match( '/\{.*\}/s', $response, $matches ) ) {
			$response = $matches[0];
		}
		$decoded = json_decode( $response, true );
		return is_array( $decoded ) ? $decoded : null;
	}
}
