<?php

namespace Uws\Tests\Normalizer;

use PHPUnit\Framework\TestCase;
use Uws\Normalizer\PriceParser;

final class PriceParserTest extends TestCase {

	public function test_dollar_sign() {
		$result = PriceParser::parse( '$100' );
		$this->assertSame( 100.0, $result['amount'] );
		$this->assertSame( 'USD', $result['currency'] );
	}

	public function test_amount_then_code() {
		$result = PriceParser::parse( '100 USD' );
		$this->assertSame( 100.0, $result['amount'] );
		$this->assertSame( 'USD', $result['currency'] );
	}

	public function test_euro_symbol() {
		$result = PriceParser::parse( '€99' );
		$this->assertSame( 99.0, $result['amount'] );
		$this->assertSame( 'EUR', $result['currency'] );
	}

	public function test_tenge_symbol_with_thousands_space() {
		$result = PriceParser::parse( '₸ 50 000' );
		$this->assertSame( 50000.0, $result['amount'] );
		$this->assertSame( 'KZT', $result['currency'] );
	}

	public function test_tenge_word_cyrillic() {
		$result = PriceParser::parse( '50 000 тг' );
		$this->assertSame( 50000.0, $result['amount'] );
		$this->assertSame( 'KZT', $result['currency'] );
	}

	public function test_thousands_comma_decimal_dot() {
		$result = PriceParser::parse( '1,234.56' );
		$this->assertSame( 1234.56, $result['amount'] );
	}

	public function test_thousands_dot_decimal_comma() {
		$result = PriceParser::parse( '1.234,56' );
		$this->assertSame( 1234.56, $result['amount'] );
	}

	public function test_empty_string() {
		$result = PriceParser::parse( '' );
		$this->assertNull( $result['amount'] );
		$this->assertNull( $result['currency'] );
	}

	public function test_no_numeric_content() {
		$result = PriceParser::parse( 'Call for price' );
		$this->assertNull( $result['amount'] );
	}
}
