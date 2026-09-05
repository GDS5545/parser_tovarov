<?php

namespace Uws\Tests\Woocommerce;

use PHPUnit\Framework\TestCase;
use Uws\Woocommerce\ImportSnapshot;
use WC_Product;

final class ImportSnapshotTest extends TestCase {

	public function test_read_returns_empty_array_for_never_imported_product() {
		$snapshot = new ImportSnapshot();
		$this->assertSame( array(), $snapshot->read( 99999 ) );
	}

	public function test_write_then_read_round_trips_tracked_fields() {
		$snapshot = new ImportSnapshot();
		$product  = new WC_Product( 501, array( 'name' => 'Widget', 'regular_price' => '19.99' ) );

		$snapshot->write( $product );
		$read = $snapshot->read( 501 );

		$this->assertSame( 'Widget', $read['name'] );
		$this->assertSame( '19.99', $read['regular_price'] );
	}

	public function test_was_manually_edited_false_when_matching_snapshot() {
		$snapshot = new ImportSnapshot();
		$product  = new WC_Product( 1, array( 'name' => 'Widget' ) );
		$snapshot->write( $product );

		$stored = $snapshot->read( 1 );

		$this->assertFalse( $snapshot->was_manually_edited( $stored, $product, 'name' ) );
	}

	public function test_was_manually_edited_true_when_product_diverged_from_snapshot() {
		$snapshot = new ImportSnapshot();
		$original = new WC_Product( 2, array( 'name' => 'Original Name' ) );
		$snapshot->write( $original );
		$stored = $snapshot->read( 2 );

		// Simulates a merchant renaming the product in wp-admin after the import.
		$edited = new WC_Product( 2, array( 'name' => 'Merchant-Renamed Product' ) );

		$this->assertTrue( $snapshot->was_manually_edited( $stored, $edited, 'name' ) );
	}

	public function test_was_manually_edited_false_for_field_not_in_snapshot() {
		$snapshot = new ImportSnapshot();
		$product  = new WC_Product( 3, array( 'name' => 'Widget' ) );

		$this->assertFalse( $snapshot->was_manually_edited( array(), $product, 'name' ) );
	}
}
