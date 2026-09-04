<?php

namespace RLT\Tests\Integration\Frontend;

use RLT\Data\EventRepository;
use RLT\Data\LinkRepository;
use RLT\Frontend\BeaconController;
use RLT\Installer;
use RLT\Settings;
use RLT\Support\RequestContext;
use WP_REST_Request;
use WP_UnitTestCase;

final class BeaconControllerTest extends WP_UnitTestCase {

	private BeaconController $beacon;
	private int $postId;

	protected function setUp(): void {
		parent::setUp();

		$_SERVER['REMOTE_ADDR']     = '203.0.113.5';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36';
		unset( $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_ORIGIN'] );
		RequestContext::reset();

		$this->beacon = new BeaconController();
		$this->postId = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<a href="https://hb.afl.rakuten.co.jp/hgc/abc/?pc=x">ホテル</a>',
			)
		);

		// The plugin's own save_post hook is not registered in this test, so create
		// the link row explicitly.
		( new LinkRepository() )->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/abc/?pc=x', $this->postId, 'ホテル' );

		do_action( 'rest_api_init' );
	}

	private function request( int $postId ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/rlt/v1/view' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'post_id' => $postId ) ) );

		return $request;
	}

	public function test_view_endpoint_is_registered(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/rlt/v1/view', $routes );
	}

	public function test_posting_a_view_records_a_row(): void {
		global $wpdb;

		$response = rest_get_server()->dispatch( $this->request( $this->postId ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['recorded'] );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Installer::viewsTable() . ' WHERE post_id = %d', $this->postId )
		);
		$this->assertSame( 1, $count );
	}

	public function test_unknown_post_id_is_rejected(): void {
		$response = rest_get_server()->dispatch( $this->request( 999999 ) );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_repeat_views_within_the_window_are_deduplicated(): void {
		global $wpdb;

		rest_get_server()->dispatch( $this->request( $this->postId ) );
		$second = rest_get_server()->dispatch( $this->request( $this->postId ) );

		$this->assertFalse( $second->get_data()['recorded'] );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Installer::viewsTable() . ' WHERE post_id = %d', $this->postId )
		);
		$this->assertSame( 1, $count );
	}

	public function test_a_referer_from_another_site_is_rejected(): void {
		$_SERVER['HTTP_REFERER'] = 'https://evil.example/attack';

		$response = rest_get_server()->dispatch( $this->request( $this->postId ) );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_a_referer_from_this_site_is_accepted(): void {
		$_SERVER['HTTP_REFERER'] = home_url( '/some-article/' );

		$response = rest_get_server()->dispatch( $this->request( $this->postId ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_logged_in_users_are_excluded_when_configured(): void {
		Settings::update( array( 'exclude_logged_in' => 1 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertFalse( $this->beacon->trackView( $this->postId ) );
	}

	public function test_should_enqueue_is_true_on_a_singular_post_with_links(): void {
		$this->go_to( get_permalink( $this->postId ) );

		$this->assertTrue( $this->beacon->shouldEnqueue() );
	}

	public function test_should_enqueue_is_false_on_a_post_without_links(): void {
		$plain = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( get_permalink( $plain ) );

		$this->assertFalse( $this->beacon->shouldEnqueue() );
	}

	public function test_should_enqueue_is_false_on_an_archive(): void {
		$this->go_to( home_url( '/' ) );

		$this->assertFalse( $this->beacon->shouldEnqueue() );
	}
}
