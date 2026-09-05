<?php
/**
 * Tests for the SSRF guard every scraped/user-supplied URL must pass
 * before it is sent to the worker.
 *
 * @package Uws\Tests
 */

namespace Uws\Tests\Security;

use PHPUnit\Framework\TestCase;
use Uws\Security\UrlValidator;

final class UrlValidatorTest extends TestCase {

	public function test_rejects_empty_url() {
		$result = UrlValidator::validate( '' );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_rejects_non_http_scheme() {
		$result = UrlValidator::validate( 'file://localhost/etc/passwd' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'uws_invalid_scheme', $result->get_error_code() );
	}

	public function test_rejects_ftp_scheme() {
		$result = UrlValidator::validate( 'ftp://example.com/file' );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_rejects_credentials_in_url() {
		$result = UrlValidator::validate( 'https://user:pass@example.com/product' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'uws_credentials_in_url', $result->get_error_code() );
	}

	public function test_rejects_loopback_ip() {
		$result = UrlValidator::validate( 'http://127.0.0.1/admin' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'uws_ssrf_blocked', $result->get_error_code() );
	}

	public function test_rejects_localhost_loopback_variants() {
		$result = UrlValidator::validate( 'http://127.1.2.3/' );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_rejects_cloud_metadata_ip() {
		$result = UrlValidator::validate( 'http://169.254.169.254/latest/meta-data/' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'uws_ssrf_blocked', $result->get_error_code() );
	}

	public function test_rejects_private_ipv4_range() {
		$result = UrlValidator::validate( 'http://10.0.0.5/internal' );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_rejects_ipv6_loopback() {
		$result = UrlValidator::validate( 'http://[::1]/' );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_accepts_public_ip_literal() {
		$result = UrlValidator::validate( 'https://93.184.216.34/product' );
		$this->assertTrue( $result );
	}

	public function test_invalid_url_string_is_rejected() {
		$result = UrlValidator::validate( 'not a url' );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
