<?php

namespace Uws\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Uws\Admin\Pages\SiteTemplatesPage;

final class SiteTemplatesPageTest extends TestCase {

	/**
	 * @param string $method
	 * @param array  $args
	 * @return mixed
	 */
	private function call_private_static( $method, array $args ) {
		$reflection = new \ReflectionMethod( SiteTemplatesPage::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( null, $args );
	}

	/**
	 * Regression test: a real user pasted the full product page URL
	 * (scheme, path and all) into the "Domain" field, which then never
	 * matched any page because lookups compare against the host alone.
	 */
	public function test_normalize_domain_strips_path_from_full_url() {
		$result = $this->call_private_static(
			'normalize_domain',
			array( 'https://habarovsk.vagner-ural.ru/katalog/magistralnye-filtry-dlya-vody/multi' )
		);
		$this->assertSame( 'habarovsk.vagner-ural.ru', $result );
	}

	public function test_normalize_domain_strips_path_without_scheme() {
		$result = $this->call_private_static(
			'normalize_domain',
			array( 'habarovsk.vagner-ural.ru/katalog/magistralnye-filtry-dlya-vody/multi' )
		);
		$this->assertSame( 'habarovsk.vagner-ural.ru', $result );
	}

	public function test_normalize_domain_accepts_bare_domain() {
		$result = $this->call_private_static( 'normalize_domain', array( 'example.com' ) );
		$this->assertSame( 'example.com', $result );
	}

	public function test_normalize_domain_lowercases_host() {
		$result = $this->call_private_static( 'normalize_domain', array( 'Example.COM' ) );
		$this->assertSame( 'example.com', $result );
	}

	public function test_looks_like_xpath_accepts_real_expressions() {
		$this->assertTrue( $this->call_private_static( 'looks_like_xpath', array( '//h1[@class="title"]' ) ) );
		$this->assertTrue( $this->call_private_static( 'looks_like_xpath', array( '//div/span' ) ) );
		$this->assertTrue( $this->call_private_static( 'looks_like_xpath', array( '(//table)[1]' ) ) );
	}

	/**
	 * Regression test: a real user pasted literal scraped values (a
	 * product name, a price, a description) into the selector fields
	 * instead of an XPath expression.
	 */
	public function test_looks_like_xpath_rejects_literal_values() {
		$this->assertFalse( $this->call_private_static( 'looks_like_xpath', array( '23 820' ) ) );
		$this->assertFalse( $this->call_private_static( 'looks_like_xpath', array( 'НФ-00009687' ) ) );
		$this->assertFalse( $this->call_private_static( 'looks_like_xpath', array( 'Мультипатронный фильтр Сила Айсберга' ) ) );
	}

	public function test_looks_like_xpath_treats_empty_string_as_ok() {
		$this->assertTrue( $this->call_private_static( 'looks_like_xpath', array( '' ) ) );
	}
}
