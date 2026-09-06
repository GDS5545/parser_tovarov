<?php

namespace Uws\Tests\Database;

use PHPUnit\Framework\TestCase;
use Uws\Database\SourceRepository;

final class SourceRepositoryTest extends TestCase {

	/**
	 * @param array<int,array{domain:string,selectors:array}> $rows
	 * @return SourceRepository
	 */
	private function fake_repository( array $rows ) {
		return new class( $rows ) extends SourceRepository {
			private $rows;
			public function __construct( array $rows ) {
				$this->rows = array_map(
					function ( $row ) {
						return (object) $row;
					},
					$rows
				);
			}
			public function find( $domain ) {
				foreach ( $this->rows as $row ) {
					if ( $row->domain === $domain ) {
						return $row;
					}
				}
				return null;
			}
			public function all() {
				return $this->rows;
			}
		};
	}

	public function test_exact_domain_match_is_used_first() {
		$repo = $this->fake_repository( array( array( 'domain' => 'example.com', 'selectors' => array( 'name' => '//h1' ) ) ) );

		$found = $repo->find_for_host( 'example.com' );

		$this->assertNotNull( $found );
		$this->assertSame( 'example.com', $found->domain );
	}

	public function test_www_prefix_matches_a_template_saved_without_it() {
		$repo = $this->fake_repository( array( array( 'domain' => 'example.com', 'selectors' => array( 'name' => '//h1' ) ) ) );

		$found = $repo->find_for_host( 'www.example.com' );

		$this->assertNotNull( $found );
		$this->assertSame( 'example.com', $found->domain );
	}

	public function test_subdomain_matches_a_template_saved_on_the_registrable_domain() {
		$repo = $this->fake_repository( array( array( 'domain' => 'vagner-ural.ru', 'selectors' => array( 'name' => '//h1' ) ) ) );

		$found = $repo->find_for_host( 'habarovsk.vagner-ural.ru' );

		$this->assertNotNull( $found );
		$this->assertSame( 'vagner-ural.ru', $found->domain );
	}

	public function test_unrelated_domain_finds_nothing() {
		$repo = $this->fake_repository( array( array( 'domain' => 'example.com', 'selectors' => array( 'name' => '//h1' ) ) ) );

		$this->assertNull( $repo->find_for_host( 'unrelated.org' ) );
	}

	public function test_empty_host_finds_nothing() {
		$repo = $this->fake_repository( array( array( 'domain' => 'example.com', 'selectors' => array( 'name' => '//h1' ) ) ) );

		$this->assertNull( $repo->find_for_host( '' ) );
	}
}
