<?php

namespace Uws\Tests\Ai;

use PHPUnit\Framework\TestCase;
use Uws\Ai\ContentCleaner;

final class ContentCleanerTest extends TestCase {

	public function test_strips_script_and_style_blocks() {
		$html = '<html><head><style>.a{color:red}</style></head><body>
			<script>var x = 1;</script>
			<h1>Real Product Name</h1>
		</body></html>';

		$text = ContentCleaner::clean( $html, 1000 );

		$this->assertStringNotContainsString( 'color:red', $text );
		$this->assertStringNotContainsString( 'var x', $text );
		$this->assertStringContainsString( 'Real Product Name', $text );
	}

	public function test_collapses_whitespace() {
		$html = '<p>Line one</p>   <p>Line   two</p>';
		$text = ContentCleaner::clean( $html, 1000 );

		$this->assertSame( 'Line one Line two', $text );
	}

	public function test_truncates_to_requested_limit() {
		// Requests below the 500-char floor are clamped up to 500 (see
		// self::clean()'s max(500, ...)), so assert against that floor.
		$html = str_repeat( 'word ', 5000 );
		$text = ContentCleaner::clean( $html, 100 );

		$this->assertLessThanOrEqual( 520, mb_strlen( $text ) );
		$this->assertStringContainsString( 'truncated', $text );
	}

	public function test_never_exceeds_absolute_max_even_if_requested() {
		$html = str_repeat( 'word ', 20000 );
		$text = ContentCleaner::clean( $html, 999999 );

		$this->assertLessThanOrEqual( ContentCleaner::ABSOLUTE_MAX_CHARS + 20, mb_strlen( $text ) );
	}
}
