<?php

namespace RLT\Tests\Integration\Api;

use RLT\Data\ApiKeyManager;
use RLT\Data\LinkRepository;
use RLT\Installer;
use WP_REST_Request;
use WP_UnitTestCase;

final class StatsControllerTest extends WP_UnitTestCase {

	private LinkRepository $links;
	private array $link;

	protected function setUp(): void {
		parent::setUp();
		update_option( 'timezone_string', 'Asia/Tokyo' );
		ApiKeyManager::deleteAll();

		$this->links = new LinkRepository();
		$this->link  = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 42, 'ホテルA' );

		// StatsController::registerRoutes() is already hooked to rest_api_init by
		// Plugin::boot(), which ran once for the whole test run. Registering a
		// second instance here would call register_rest_route() outside of
		// doing_action( 'rest_api_init' ) -- once that action has already fired
		// at least once (e.g. during the WP test install), WP flags that as
		// incorrect usage and WP_UnitTestCase turns it into a test failure. Firing
		// the action is enough to invoke the already-registered callback.
		do_action( 'rest_api_init' );
	}

	private function asAdmin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function get( string $route, array $params = array() ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	public function test_all_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();

		foreach ( array( '/rlt/v1/stats/summary', '/rlt/v1/stats/daily', '/rlt/v1/stats/posts', '/rlt/v1/stats/links', '/rlt/v1/stats/report', '/rlt/v1/links' ) as $route ) {
			$this->assertArrayHasKey( $route, $routes, "Route {$route} is missing." );
		}
	}

	public function test_anonymous_requests_are_rejected(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->get( '/rlt/v1/stats/summary' )->get_status() );
	}

	public function test_a_subscriber_is_rejected(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( 403, $this->get( '/rlt/v1/stats/summary' )->get_status() );
	}

	public function test_an_administrator_is_allowed(): void {
		$this->asAdmin();

		$this->assertSame( 200, $this->get( '/rlt/v1/stats/summary' )->get_status() );
	}

	public function test_a_read_only_api_key_is_allowed(): void {
		wp_set_current_user( 0 );
		$key = ApiKeyManager::create( 'BI' )['key'];

		$request = new WP_REST_Request( 'GET', '/rlt/v1/stats/summary' );
		$request->set_header( ApiKeyManager::HEADER, $key );

		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_summary_response_declares_its_range_and_timezone(): void {
		$this->asAdmin();

		$data = $this->get( '/rlt/v1/stats/summary', array( 'from' => '2026-09-01', 'to' => '2026-09-07' ) )->get_data();

		$this->assertSame( '2026-09-01', $data['range']['from'] );
		$this->assertSame( '2026-09-07', $data['range']['to'] );
		$this->assertSame( 'Asia/Tokyo', $data['timezone'] );
		$this->assertArrayHasKey( 'clicks', $data['totals'] );
		$this->assertArrayHasKey( 'ctr', $data['totals'] );
	}

	public function test_summary_includes_the_previous_period_for_comparison(): void {
		$this->asAdmin();

		$data = $this->get( '/rlt/v1/stats/summary' )->get_data();

		$this->assertArrayHasKey( 'previous', $data );
		$this->assertArrayHasKey( 'clicks', $data['previous'] );
	}

	public function test_daily_returns_one_entry_per_day(): void {
		$this->asAdmin();

		$data = $this->get( '/rlt/v1/stats/daily', array( 'from' => '2026-09-01', 'to' => '2026-09-03' ) )->get_data();

		$this->assertCount( 3, $data['days'] );
	}

	public function test_links_endpoint_returns_the_short_url(): void {
		$this->asAdmin();

		$data = $this->get( '/rlt/v1/stats/links' )->get_data();

		$this->assertSame( $this->link['code'], $data['links'][0]['code'] );
		$this->assertStringContainsString( '/go/' . $this->link['code'], $data['links'][0]['short_url'] );
	}

	public function test_single_link_endpoint_returns_breakdowns(): void {
		$this->asAdmin();

		$data = $this->get( '/rlt/v1/stats/links/' . $this->link['code'] )->get_data();

		$this->assertSame( $this->link['code'], $data['link']['code'] );
		$this->assertArrayHasKey( 'daily', $data );
		$this->assertArrayHasKey( 'referers', $data );
		$this->assertArrayHasKey( 'devices', $data );
	}

	public function test_single_link_endpoint_404s_for_an_unknown_code(): void {
		$this->asAdmin();

		$this->assertSame( 404, $this->get( '/rlt/v1/stats/links/zzzzzz' )->get_status() );
	}

	public function test_posts_endpoint_resolves_post_titles(): void {
		$this->asAdmin();

		$postId = self::factory()->post->create( array( 'post_title' => 'テスト記事', 'post_status' => 'publish' ) );
		$this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/b', $postId, 'B' );

		global $wpdb;
		$wpdb->insert(
			Installer::viewsTable(),
			array(
				'post_id'      => $postId,
				'viewed_at'    => gmdate( 'Y-m-d H:i:s' ),
				'visitor_hash' => str_pad( 'v', 64, '0' ),
				'referer'      => '',
				'device'       => 1,
				'is_bot'       => 0,
			)
		);

		$data = $this->get( '/rlt/v1/stats/posts' )->get_data();
		$row  = array_column( $data['posts'], null, 'post_id' )[ $postId ];

		$this->assertSame( 'テスト記事', $row['title'] );
		$this->assertArrayHasKey( 'permalink', $row );
	}

	public function test_report_endpoint_returns_notes(): void {
		$this->asAdmin();

		$data = $this->get( '/rlt/v1/stats/report' )->get_data();

		$this->assertArrayHasKey( 'totals', $data );
		$this->assertArrayHasKey( 'top_links', $data );
		$this->assertArrayHasKey( 'top_posts', $data );
		$this->assertIsArray( $data['notes'] );
	}

	public function test_patch_updates_the_target_url(): void {
		$this->asAdmin();

		$request = new WP_REST_Request( 'PATCH', '/rlt/v1/links/' . $this->link['code'] );
		$request->set_param( 'target_url', 'https://hb.afl.rakuten.co.jp/hgc/new' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'https://hb.afl.rakuten.co.jp/hgc/new', $this->links->findById( $this->link['id'] )['target_url'] );
	}

	public function test_patch_is_refused_for_a_read_only_api_key(): void {
		wp_set_current_user( 0 );
		$key = ApiKeyManager::create( 'BI' )['key'];

		$request = new WP_REST_Request( 'PATCH', '/rlt/v1/links/' . $this->link['code'] );
		$request->set_header( ApiKeyManager::HEADER, $key );
		$request->set_param( 'label', '乗っ取り' );

		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertSame( 'ホテルA', $this->links->findById( $this->link['id'] )['label'] );
	}

	public function test_patch_rejects_a_non_http_target_url(): void {
		$this->asAdmin();

		$request = new WP_REST_Request( 'PATCH', '/rlt/v1/links/' . $this->link['code'] );
		$request->set_param( 'target_url', 'javascript:alert(1)' );

		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_limit_is_clamped(): void {
		$this->asAdmin();

		$response = $this->get( '/rlt/v1/stats/links', array( 'limit' => 99999 ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_bad_dates_fall_back_to_the_default_window(): void {
		$this->asAdmin();

		$data = $this->get( '/rlt/v1/stats/daily', array( 'from' => 'garbage', 'to' => 'worse' ) )->get_data();

		$this->assertCount( 28, $data['days'] );
	}
}
