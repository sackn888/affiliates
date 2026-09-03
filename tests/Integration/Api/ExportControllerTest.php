<?php

namespace RLT\Tests\Integration\Api;

use RLT\Data\LinkRepository;
use RLT\Installer;
use WP_REST_Request;
use WP_UnitTestCase;

final class ExportControllerTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		update_option( 'timezone_string', 'Asia/Tokyo' );

		$links = new LinkRepository();
		$link  = $links->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 42, 'ホテル,カンマ入り' );

		global $wpdb;
		$wpdb->insert(
			Installer::clicksTable(),
			array(
				'link_id'      => $link['id'],
				'post_id'      => 42,
				'clicked_at'   => gmdate( 'Y-m-d H:i:s' ),
				'visitor_hash' => str_pad( 'v', 64, '0' ),
				'referer'      => 'https://www.google.com/',
				'device'       => 2,
				'is_bot'       => 0,
			)
		);

		// ExportController::registerRoutes() and its rest_pre_serve_request filter
		// are already hooked by Plugin::boot(), which ran once for the whole test
		// run. Registering a second instance here would call register_rest_route()
		// outside of doing_action( 'rest_api_init' ) -- once that action has
		// already fired at least once (e.g. during the WP test install), WP flags
		// that as incorrect usage and WP_UnitTestCase turns it into a test
		// failure. Firing the action is enough to invoke the already-registered
		// callback.
		do_action( 'rest_api_init' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function dispatch( string $route ): \WP_REST_Response {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', $route ) );
	}

	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/rlt/v1/export/clicks', $routes );
		$this->assertArrayHasKey( '/rlt/v1/export/views', $routes );
	}

	public function test_clicks_export_starts_with_a_header_row(): void {
		$csv = $this->dispatch( '/rlt/v1/export/clicks' )->get_data();

		$this->assertStringStartsWith( 'clicked_at,code,label,post_id,target_url,referer,device,is_bot', $csv );
	}

	public function test_clicks_export_contains_the_row(): void {
		$csv = $this->dispatch( '/rlt/v1/export/clicks' )->get_data();

		$this->assertStringContainsString( 'https://www.google.com/', $csv );
		$this->assertStringContainsString( 'mobile', $csv );
	}

	public function test_values_containing_commas_are_quoted(): void {
		$csv = $this->dispatch( '/rlt/v1/export/clicks' )->get_data();

		$this->assertStringContainsString( '"ホテル,カンマ入り"', $csv );
	}

	public function test_export_never_includes_the_visitor_hash(): void {
		$csv = $this->dispatch( '/rlt/v1/export/clicks' )->get_data();

		$this->assertStringNotContainsString( 'visitor_hash', $csv );
		$this->assertStringNotContainsString( str_pad( 'v', 64, '0' ), $csv );
	}

	public function test_response_declares_csv_content_type_and_a_filename(): void {
		$headers = $this->dispatch( '/rlt/v1/export/clicks' )->get_headers();

		$this->assertSame( 'text/csv; charset=utf-8', $headers['Content-Type'] );
		$this->assertStringContainsString( 'attachment;', $headers['Content-Disposition'] );
		$this->assertStringContainsString( '.csv', $headers['Content-Disposition'] );
	}

	public function test_views_export_has_its_own_header_row(): void {
		$csv = $this->dispatch( '/rlt/v1/export/views' )->get_data();

		$this->assertStringStartsWith( 'viewed_at,post_id,referer,device,is_bot', $csv );
	}

	public function test_anonymous_export_is_rejected(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->dispatch( '/rlt/v1/export/clicks' )->get_status() );
	}
}
