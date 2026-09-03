<?php

namespace RLT\Tests\Integration\Support;

use RLT\Support\DeviceDetector;
use RLT\Support\RequestContext;
use WP_UnitTestCase;

final class RequestContextTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		$_SERVER['REMOTE_ADDR']     = '203.0.113.5';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36';
		$_SERVER['HTTP_REFERER']    = 'https://example.com/hotel-article/';
	}

	public function test_reads_ip_from_remote_addr(): void {
		$this->assertSame( '203.0.113.5', RequestContext::ip() );
	}

	public function test_ip_can_be_overridden_by_filter_for_proxied_sites(): void {
		add_filter( 'rlt_client_ip', static fn (): string => '198.51.100.9' );

		$this->assertSame( '198.51.100.9', RequestContext::ip() );
	}

	public function test_missing_ip_is_empty_string(): void {
		unset( $_SERVER['REMOTE_ADDR'] );

		$this->assertSame( '', RequestContext::ip() );
	}

	public function test_referer_is_truncated_to_255_characters(): void {
		$_SERVER['HTTP_REFERER'] = 'https://example.com/' . str_repeat( 'a', 400 );

		$this->assertLessThanOrEqual( 255, strlen( RequestContext::referer() ) );
	}

	public function test_visitor_hash_is_hex_and_stable_within_a_request(): void {
		$hash = RequestContext::visitorHash();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash );
		$this->assertSame( $hash, RequestContext::visitorHash() );
	}

	public function test_visitor_hash_does_not_leak_the_ip(): void {
		$this->assertStringNotContainsString( '203.0.113.5', RequestContext::visitorHash() );
	}

	public function test_device_and_bot_come_from_the_user_agent(): void {
		$this->assertSame( DeviceDetector::DESKTOP, RequestContext::device() );
		$this->assertFalse( RequestContext::isBot() );

		$_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1';
		RequestContext::reset();

		$this->assertTrue( RequestContext::isBot() );
	}

	public function test_referer_is_truncated_without_breaking_a_multibyte_character(): void {
		// esc_url_raw は \x80-\xff の生バイトをそのまま通すため、
		// 生の UTF-8 を含むリファラがここまで届きうる。
		$_SERVER['HTTP_REFERER'] = 'https://example.com/' . str_repeat( 'あ', 300 );

		$referer = RequestContext::referer();

		$this->assertLessThanOrEqual( 255, strlen( $referer ) );
		$this->assertSame(
			$referer,
			mb_convert_encoding( $referer, 'UTF-8', 'UTF-8' ),
			'Truncated referer is not valid UTF-8.'
		);
	}

	public function test_a_truncated_referer_is_still_stored(): void {
		$_SERVER['HTTP_REFERER'] = 'https://example.com/' . str_repeat( 'あ', 300 );
		RequestContext::reset();

		// 壊れたUTF-8だと INSERT ごと失敗し、クリック自体が記録されない。
		$this->assertTrue( ( new \RLT\Data\EventRepository() )->recordClick( 1, 1 ) );
	}

	public function test_a_filter_returning_nothing_falls_back_to_remote_addr(): void {
		add_filter( 'rlt_client_ip', static fn () => null );

		$this->assertSame( '203.0.113.5', RequestContext::ip() );
	}

	public function test_a_filter_returning_a_non_string_falls_back_to_remote_addr(): void {
		add_filter( 'rlt_client_ip', static fn () => array( 'nope' ) );

		$this->assertSame( '203.0.113.5', RequestContext::ip() );
	}

	public function tear_down(): void {
		RequestContext::reset();
		parent::tear_down();
	}
}
