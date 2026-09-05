<?php

namespace Uws\Tests\Normalizer;

use PHPUnit\Framework\TestCase;
use Uws\Dto\ProductAttribute;
use Uws\Normalizer\AttributeNormalizer;

final class AttributeNormalizerTest extends TestCase {

	public function test_collapses_english_and_russian_voltage_synonyms_to_same_slug() {
		$normalizer = new AttributeNormalizer( AttributeNormalizer::MODE_SMART );

		$a = new ProductAttribute( 'Voltage', '220 V' );
		$b = new ProductAttribute( 'Напряжение питания', '220 В' );

		$normalizer->normalize( $a );
		$normalizer->normalize( $b );

		$this->assertSame( 'voltage', $a->attribute_name );
		$this->assertSame( 'voltage', $b->attribute_name );
	}

	public function test_extracts_numeric_value_and_unit() {
		$normalizer = new AttributeNormalizer( AttributeNormalizer::MODE_SMART );
		$attribute  = new ProductAttribute( 'Power', '1500 W' );

		$normalizer->normalize( $attribute );

		$this->assertSame( 1500.0, $attribute->numeric_value );
		$this->assertSame( 'W', $attribute->unit );
	}

	public function test_unrecognized_key_falls_back_to_sanitized_slug() {
		$normalizer = new AttributeNormalizer( AttributeNormalizer::MODE_SMART );
		$attribute  = new ProductAttribute( 'Special Coating', 'Yes' );

		$normalizer->normalize( $attribute );

		$this->assertSame( 'special-coating', $attribute->attribute_name );
	}

	public function test_strict_mode_does_not_collapse_synonyms() {
		$normalizer = new AttributeNormalizer( AttributeNormalizer::MODE_STRICT );
		$attribute  = new ProductAttribute( 'Напряжение питания', '220 В' );

		$normalizer->normalize( $attribute );

		$this->assertSame( 'напряжение-питания', $attribute->attribute_name );
		$this->assertSame( '220 В', $attribute->value_normalized );
	}
}
