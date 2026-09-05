<?php
/**
 * Downloads scraped image URLs into the Media Library (spec §13). Each
 * attachment is tagged with its source URL in postmeta so re-importing
 * the same product (sync, spec §27) or a different product that shares an
 * image doesn't download it twice.
 *
 * @package Uws\Woocommerce
 */

namespace Uws\Woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImageImporter {

	const SOURCE_URL_META_KEY = '_uws_source_image_url';

	public function __construct() {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	/**
	 * @param string $url
	 * @param int    $post_id Post to attach the media item to (0 for unattached).
	 * @return int|null Attachment ID, or null if the download/import failed.
	 */
	public function import( $url, $post_id = 0 ) {
		$existing = $this->find_existing( $url );
		if ( $existing ) {
			return $existing;
		}

		$attachment_id = media_sideload_image( $url, $post_id, null, 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			return null;
		}

		update_post_meta( $attachment_id, self::SOURCE_URL_META_KEY, $url );

		return (int) $attachment_id;
	}

	/**
	 * @param string $url
	 * @return int|null
	 */
	private function find_existing( $url ) {
		global $wpdb;

		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				self::SOURCE_URL_META_KEY,
				$url
			)
		);

		return $attachment_id ? (int) $attachment_id : null;
	}
}
