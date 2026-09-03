<?php

namespace RLT\Tests\Integration\Data;

use RLT\Data\EventRepository;
use RLT\Installer;
use RLT\Support\RequestContext;
use WP_UnitTestCase;

final class EventRepositoryRecordTest extends WP_UnitTestCase {

	private EventRepository $events;

	protected function setUp(): void {
		parent::setUp();

		$_SERVER['REMOTE_ADDR']     = '203.0.113.5';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36';
		$_SERVER['HTTP_REFERER']    = 'https://example.com/hotel-article/';
		RequestContext::reset();

		$this->events = new EventRepository();
	}

	public function test_record_click_inserts_a_row(): void {
		global $wpdb;

		$this->assertTrue( $this->events->recordClick( 7, 42 ) );

		$row = $wpdb->get_row( 'SELECT * FROM ' . Installer::clicksTable() . ' ORDER BY id DESC LIMIT 1', ARRAY_A );

		$this->assertSame( '7', $row['link_id'] );
		$this->assertSame( '42', $row['post_id'] );
		$this->assertSame( 'https://example.com/hotel-article/', $row['referer'] );
		$this->assertSame( '0', $row['is_bot'] );
	}

	public function test_click_row_never_stores_the_raw_ip(): void {
		global $wpdb;

		$this->events->recordClick( 7, 42 );
		$row = $wpdb->get_row( 'SELECT * FROM ' . Installer::clicksTable() . ' ORDER BY id DESC LIMIT 1', ARRAY_A );

		foreach ( $row as $value ) {
			$this->assertStringNotContainsString( '203.0.113.5', (string) $value );
		}
	}

	public function test_bot_clicks_are_recorded_and_flagged(): void {
		global $wpdb;

		$_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1';
		RequestContext::reset();

		$this->assertTrue( $this->events->recordClick( 7, 42 ) );

		$row = $wpdb->get_row( 'SELECT * FROM ' . Installer::clicksTable() . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
		$this->assertSame( '1', $row['is_bot'] );
	}

	public function test_record_view_inserts_a_row(): void {
		global $wpdb;

		$this->assertTrue( $this->events->recordView( 42 ) );

		$row = $wpdb->get_row( 'SELECT * FROM ' . Installer::viewsTable() . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
		$this->assertSame( '42', $row['post_id'] );
	}

	public function test_has_recent_click_is_true_right_after_recording(): void {
		$this->events->recordClick( 7, 42 );

		$this->assertTrue( $this->events->hasRecentClick( 7, RequestContext::visitorHash(), 5 ) );
	}

	public function test_has_recent_click_is_false_for_a_different_link(): void {
		$this->events->recordClick( 7, 42 );

		$this->assertFalse( $this->events->hasRecentClick( 8, RequestContext::visitorHash(), 5 ) );
	}

	public function test_has_recent_click_is_false_for_a_different_visitor(): void {
		$this->events->recordClick( 7, 42 );

		$this->assertFalse( $this->events->hasRecentClick( 7, str_repeat( 'f', 64 ), 5 ) );
	}

	public function test_has_recent_click_is_false_outside_the_window(): void {
		global $wpdb;

		$this->events->recordClick( 7, 42 );
		$wpdb->query( 'UPDATE ' . Installer::clicksTable() . ' SET clicked_at = DATE_SUB(clicked_at, INTERVAL 60 SECOND)' );

		$this->assertFalse( $this->events->hasRecentClick( 7, RequestContext::visitorHash(), 5 ) );
	}

	public function test_has_recent_click_is_false_when_window_is_zero(): void {
		$this->events->recordClick( 7, 42 );

		$this->assertFalse( $this->events->hasRecentClick( 7, RequestContext::visitorHash(), 0 ) );
	}

	public function test_has_recent_view_behaves_the_same(): void {
		$this->events->recordView( 42 );

		$this->assertTrue( $this->events->hasRecentView( 42, RequestContext::visitorHash(), 1800 ) );
		$this->assertFalse( $this->events->hasRecentView( 43, RequestContext::visitorHash(), 1800 ) );
	}
}
