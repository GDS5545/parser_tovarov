<?php

namespace Uws\Tests\Extractors;

use PHPUnit\Framework\TestCase;
use Uws\Extractors\ImageExtractor;

final class ImageExtractorTest extends TestCase {

	private function extract( $html, $final_url = 'https://habarovsk.vagner-ural.ru/katalog/product/' ) {
		return ( new ImageExtractor() )->extract( array( 'html' => $html, 'final_url' => $final_url ) );
	}

	public function test_keeps_same_domain_product_photo() {
		$html = '<img src="/uploadedFiles/images/7.jpg" alt="Product photo" />';
		$data = $this->extract( $html );

		$this->assertCount( 1, $data->images );
		$this->assertStringContainsString( '7.jpg', $data->images[0]['url'] );
	}

	public function test_drops_image_on_unrelated_third_party_domain() {
		// Regression case: a real site embedded a Telegram sticker image
		// and a Mail.ru counter pixel; neither is a product photo.
		$html = '<img src="https://web.telegram.org/a/img-apple-64/1f389.png" />'
			. '<img src="http://de.c0.bf.a1.top.mail.ru/counter?id=123" />';

		$data = $this->extract( $html );

		$this->assertCount( 0, $data->images );
	}

	public function test_keeps_image_on_sibling_subdomain_of_same_site() {
		// Real case: habarovsk.vagner-ural.ru product page linking to an
		// image hosted on the bare vagner-ural.ru domain — same site.
		$html = '<img src="https://vagner-ural.ru/uploadedFiles/images/7.jpg" />';
		$data = $this->extract( $html );

		$this->assertCount( 1, $data->images );
	}

	public function test_drops_known_site_chrome_keywords_even_on_same_domain() {
		$html = '<img src="/uploadedFiles/images/whatsapp.png" />'
			. '<img src="/img/rus.png" />'
			. '<img src="/uploadedFiles/newsimages/big/partner-01.gif" />'
			. '<img src="/uploadedFiles/images/instagramm.jpg" />';

		$data = $this->extract( $html );

		$this->assertCount( 0, $data->images );
	}

	public function test_drops_icon_sized_images_by_declared_dimensions() {
		$html = '<img src="/uploadedFiles/images/tiny.png" width="16" height="16" />'
			. '<img src="/uploadedFiles/images/real-photo.jpg" width="800" height="600" />';

		$data = $this->extract( $html );

		$this->assertCount( 1, $data->images );
		$this->assertStringContainsString( 'real-photo.jpg', $data->images[0]['url'] );
	}

	public function test_prefers_recognized_gallery_container_over_unrelated_same_domain_images() {
		// A same-domain "our work" carousel elsewhere on the page, plus a
		// recognizable product-image container — the container should win
		// even though every image here passes the domain/keyword/size filters.
		$html = '<div class="site-gallery">
				<img src="/uploadedFiles/images/unrelated-1.jpg" />
				<img src="/uploadedFiles/images/unrelated-2.jpg" />
			</div>
			<div class="product-gallery">
				<img src="/uploadedFiles/images/real-product.jpg" alt="Product" />
			</div>';

		$data = $this->extract( $html );

		$this->assertCount( 1, $data->images );
		$this->assertStringContainsString( 'real-product.jpg', $data->images[0]['url'] );
	}

	public function test_bitrix_detail_picture_container_is_recognized() {
		$html = '<div class="catalog-list"><img src="/img/list-thumb.jpg" /></div>
			<div class="detail_picture"><img src="/upload/iblock/real.jpg" /></div>';

		$data = $this->extract( $html );

		$this->assertCount( 1, $data->images );
		$this->assertStringContainsString( 'real.jpg', $data->images[0]['url'] );
	}

	public function test_falls_back_to_whole_page_when_no_gallery_container_matches() {
		$html = '<div class="whatever"><img src="/uploadedFiles/images/only-photo.jpg" /></div>';

		$data = $this->extract( $html );

		$this->assertCount( 1, $data->images );
	}

	public function test_falls_back_to_whole_page_when_container_matches_but_has_no_surviving_images() {
		// The "gallery" container exists but only holds chrome (e.g. a
		// loading spinner); the real photo lives outside it.
		$html = '<div class="product-gallery"><img src="/img/spinner.gif" /></div>
			<img src="/uploadedFiles/images/real-photo.jpg" />';

		$data = $this->extract( $html );

		$this->assertCount( 1, $data->images );
		$this->assertStringContainsString( 'real-photo.jpg', $data->images[0]['url'] );
	}

	public function test_real_world_regression_only_product_photos_survive() {
		// Condensed from the actual habarovsk.vagner-ural.ru page that
		// exposed this bug: a WhatsApp badge appeared before the real
		// product photos in DOM order and was wrongly chosen as "main".
		$html = '<img src="/uploadedFiles/images/whatsapp.png" />'
			. '<img src="/img/rus.png" />'
			. '<img src="/img/gb.png" />'
			. '<img src="https://vagner-ural.ru/uploadedFiles/images/7.jpg" alt="Кассета фильтрующая" />'
			. '<img src="https://vagner-ural.ru/uploadedFiles/images/6.jpg" alt="Кассета фильтрующая" />'
			. '<img src="https://web.telegram.org/a/img-apple-64/1f31f.png" />'
			. '<img src="/uploadedFiles/newsimages/big/partner-01.gif" />'
			. '<img src="http://de.c0.bf.a1.top.mail.ru/counter?js=na;id=1;t=1" />';

		$data = $this->extract( $html );

		$this->assertCount( 2, $data->images );
		$this->assertStringContainsString( '7.jpg', $data->images[0]['url'] );
		$this->assertTrue( $data->images[0]['is_main'] );
	}
}
