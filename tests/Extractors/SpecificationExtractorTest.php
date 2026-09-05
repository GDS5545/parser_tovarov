<?php

namespace Uws\Tests\Extractors;

use PHPUnit\Framework\TestCase;
use Uws\Extractors\SpecificationExtractor;

final class SpecificationExtractorTest extends TestCase {

	private function extract( $html ) {
		return ( new SpecificationExtractor() )->extract( array( 'html' => $html ) );
	}

	public function test_prefers_recognized_specification_container_over_unrelated_table() {
		// Regression case: a real site's "why choose us" marketing bullets
		// were marked up as a two-column table elsewhere on the page and
		// picked up as if they were product specifications.
		$html = '<table class="why-us">
				<tr><th>Плюс 1</th><td>3D-моделирование проектируемых объектов</td></tr>
				<tr><th>Плюс 2</th><td>Собственное производство реагентов</td></tr>
			</table>
			<div class="specifications">
				<table>
					<tr><th>Жёсткость</th><td>до 15 °Ж</td></tr>
				</table>
			</div>';

		$data = $this->extract( $html );

		$this->assertCount( 1, $data->attributes );
		$this->assertSame( 'Жёсткость', $data->attributes[0]->attribute_key );
	}

	public function test_recognizes_bitrix_sku_props_container() {
		$html = '<table class="unrelated"><tr><th>A</th><td>B</td></tr></table>
			<div class="sku_props"><table><tr><th>Ресурс</th><td>350 л</td></tr></table></div>';

		$data = $this->extract( $html );

		$this->assertCount( 1, $data->attributes );
		$this->assertSame( 'Ресурс', $data->attributes[0]->attribute_key );
	}

	public function test_falls_back_to_whole_page_when_no_container_matches() {
		$html = '<table><tr><th>Key</th><td>Value</td></tr></table>';

		$data = $this->extract( $html );

		$this->assertCount( 1, $data->attributes );
	}

	public function test_falls_back_when_matched_container_yields_nothing() {
		$html = '<div class="specifications"><p>No table here.</p></div>
			<table><tr><th>Key</th><td>Value</td></tr></table>';

		$data = $this->extract( $html );

		$this->assertCount( 1, $data->attributes );
		$this->assertSame( 'Key', $data->attributes[0]->attribute_key );
	}
}
