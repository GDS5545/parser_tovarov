<?php

namespace Uws\Tests\Extractors;

use PHPUnit\Framework\TestCase;
use Uws\Extractors\VariationExtractor;

final class VariationExtractorTest extends TestCase {

	public function test_detects_select_based_variation_attribute() {
		$html = '<html><body>
			<label for="color-select">Color</label>
			<select id="color-select" name="attribute_pa_color">
				<option value="">Choose an option</option>
				<option value="red">Red</option>
				<option value="blue">Blue</option>
			</select>
		</body></html>';

		$data = ( new VariationExtractor() )->extract( array( 'html' => $html ) );

		$this->assertSame( 'variable', $data->product_type );
		$this->assertCount( 2, $data->attributes );
		$this->assertSame( 'Color', $data->attributes[0]->attribute_key );
		$this->assertTrue( $data->attributes[0]->is_variation );
		$values = array_map(
			function ( $attribute ) {
				return $attribute->value_raw;
			},
			$data->attributes
		);
		$this->assertSame( array( 'Red', 'Blue' ), $values );
	}

	public function test_detects_radio_based_variation_attribute_with_labels() {
		$html = '<html><body>
			<input type="radio" name="size" id="size-s" value="s" />
			<label for="size-s">Small</label>
			<input type="radio" name="size" id="size-m" value="m" />
			<label for="size-m">Medium</label>
		</body></html>';

		$data = ( new VariationExtractor() )->extract( array( 'html' => $html ) );

		$this->assertSame( 'variable', $data->product_type );
		$labels = array_map(
			function ( $attribute ) {
				return $attribute->value_raw;
			},
			$data->attributes
		);
		$this->assertSame( array( 'Small', 'Medium' ), $labels );
		$this->assertSame( 'Size', $data->attributes[0]->attribute_key );
	}

	public function test_single_option_select_is_not_treated_as_variation() {
		$html = '<html><body>
			<select name="attribute_pa_color">
				<option value="red">Red</option>
			</select>
		</body></html>';

		$data = ( new VariationExtractor() )->extract( array( 'html' => $html ) );

		$this->assertSame( 'simple', $data->product_type );
		$this->assertCount( 0, $data->attributes );
	}

	public function test_placeholder_options_are_ignored() {
		$html = '<html><body>
			<select name="size">
				<option value="">Select size</option>
				<option value="s">S</option>
				<option value="m">M</option>
			</select>
		</body></html>';

		$data = ( new VariationExtractor() )->extract( array( 'html' => $html ) );

		$this->assertCount( 2, $data->attributes );
	}
}
