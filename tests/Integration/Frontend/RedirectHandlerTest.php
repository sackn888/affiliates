<?php

namespace RLT\Tests\Integration\Frontend;

use RLT\Data\EventRepository;
use RLT\Data\LinkRepository;
use RLT\Frontend\RedirectHandler;
use RLT\Installer;
use RLT\Settings;
use RLT\Support\RequestContext;
use WP_UnitTestCase;

/**
 * Thrown from a wp_redirect filter so tests can observe the redirect without
 * the process exiting.
 */
final class RedirectCaught extends \Exception {

	public function __construct( public readonly string $location, public readonly int $status ) {
		parent::__construct( $location );
	}
}

final class RedirectHandlerTest extends WP_UnitTestCase {

	private const AFFILIATE = 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=x';

	private RedirectHandler $handler;
	private LinkRepository $links;

	protected function setUp(): void {
		parent::setUp();

		$_SERVER['REMOTE_ADDR']        = '203.0.113.5';
		$_SERVER['HTTP_USER_AGENT']    = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36';
		$_SERVER['HTTP_REFERER']       = 'https://example.com/hotel-article/';
		$_SERVER['REQUEST_METHOD']     = 'GET';
		RequestContext::reset();

		$this->links   = new LinkRepository();
		$this->handler = new RedirectHandler( $this->links, new EventRepository() );

		add_filter(
			'wp_redirect',
			static function ( $location, $status ) {
				throw new RedirectCaught( (string) $location, (int) $status );
			},
			10,
			2
		);
	}

	private function makeLink(): array {
		return $this->links->findOrCreate( self::AFFILIATE, 42, 'ホテル' );
	}

	public function test_resolve_finds_a_link_by_code(): void {
		$link = $this->makeLink();

		$this->assertSame( $link['id'], $this->handler->resolve( $link['code'] )['id'] );
	}

	public function test_resolve_returns_null_for_an_unknown_code(): void {
		$this->assertNull( $this->handler->resolve( 'zzzzzz' ) );
	}

	public function test_resolve_rejects_a_malformed_code_without_querying(): void {
		$this->assertNull( $this->handler->resolve( '../../etc/passwd' ) );
	}

	public function test_resolve_still_finds_archived_links(): void {
		$link = $this->makeLink();
		$this->links->update( $link['id'], array( 'status' => 0 ) );

		// アーカイブ済みの短縮URLが外部に共有されている可能性があるため、
		// リダイレクト自体は生かしておく。
		$this->assertNotNull( $this->handler->resolve( $link['code'] ) );
	}

	public function test_track_click_records_a_row(): void {
		global $wpdb;

		$link = $this->makeLink();

		$this->assertTrue( $this->handler->trackClick( $link ) );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Installer::clicksTable() . ' WHERE link_id = %d', $link['id'] )
		);
		$this->assertSame( 1, $count );
	}

	public function test_track_click_deduplicates_rapid_repeats(): void {
		global $wpdb;

		$link = $this->makeLink();

		$this->handler->trackClick( $link );
		$this->assertFalse( $this->handler->trackClick( $link ) );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Installer::clicksTable() . ' WHERE link_id = %d', $link['id'] )
		);
		$this->assertSame( 1, $count );
	}

	public function test_track_click_skips_logged_in_users_when_configured(): void {
		$link = $this->makeLink();

		Settings::update( array( 'exclude_logged_in' => 1 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertFalse( $this->handler->trackClick( $link ) );
	}

	public function test_handle_redirects_to_the_target_with_302(): void {
		$link = $this->makeLink();
		set_query_var( RedirectHandler::QUERY_VAR, $link['code'] );

		try {
			$this->handler->handle();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCaught $caught ) {
			$this->assertSame( self::AFFILIATE, $caught->location );
			// 301 だとブラウザにキャッシュされ2回目以降が計測できない。
			$this->assertSame( 302, $caught->status );
		}
	}

	public function test_handle_redirects_even_when_recording_throws(): void {
		$link = $this->makeLink();
		set_query_var( RedirectHandler::QUERY_VAR, $link['code'] );

		// 計測が壊れてもアフィリエイト収益を落としてはならない。
		add_action(
			'rlt_before_track_click',
			static function (): void {
				throw new \RuntimeException( 'database is on fire' );
			}
		);

		$this->expectException( RedirectCaught::class );
		$this->handler->handle();
	}

	public function test_a_target_url_with_a_dangerous_scheme_is_not_redirected_to(): void {
		$link = $this->makeLink();
		$this->links->update( $link['id'], array( 'target_url' => 'javascript://hb.afl.rakuten.co.jp/%0aalert(1)' ) );

		set_query_var( RedirectHandler::QUERY_VAR, $link['code'] );

		try {
			$this->handler->handle();
			$this->fail( 'Expected a redirect to the fallback.' );
		} catch ( RedirectCaught $caught ) {
			// 公開URLからのオープンリダイレクトになるため、遷移先にしてはならない。
			$this->assertSame( home_url( '/' ), $caught->location );
		}
	}

	public function test_an_ordinary_https_target_still_redirects(): void {
		$link = $this->makeLink();
		set_query_var( RedirectHandler::QUERY_VAR, $link['code'] );

		$this->expectException( RedirectCaught::class );
		$this->handler->handle();
	}

	public function test_handle_sends_the_visitor_home_for_an_unknown_code(): void {
		Settings::update( array( 'unknown_code' => 'home' ) );
		set_query_var( RedirectHandler::QUERY_VAR, 'zzzzzz' );

		try {
			$this->handler->handle();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCaught $caught ) {
			$this->assertSame( home_url( '/' ), $caught->location );
		}
	}

	public function test_handle_does_nothing_without_a_code(): void {
		global $wpdb;

		set_query_var( RedirectHandler::QUERY_VAR, '' );

		// リダイレクトしていれば wp_redirect フィルタが RedirectCaught を投げ、
		// このテストは例外で落ちる。落ちないこと自体が「素通りした」証拠。
		$this->handler->handle();

		$this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::clicksTable() ) );
	}

	public function test_head_requests_are_not_recorded(): void {
		global $wpdb;

		$link                      = $this->makeLink();
		$_SERVER['REQUEST_METHOD'] = 'HEAD';
		set_query_var( RedirectHandler::QUERY_VAR, $link['code'] );

		try {
			$this->handler->handle();
		} catch ( RedirectCaught $caught ) {
			$this->assertSame( self::AFFILIATE, $caught->location );
		}

		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::clicksTable() );
		$this->assertSame( 0, $count );
	}

	public function test_rewrite_rule_is_registered_for_the_configured_prefix(): void {
		Settings::update( array( 'prefix' => 'out' ) );

		$this->handler->addRewriteRule();

		global $wp_rewrite;
		$rules = $wp_rewrite->extra_rules_top;

		$this->assertArrayHasKey( '^out/([a-z0-9]{4,16})/?$', $rules );
	}

	public function test_query_var_is_registered(): void {
		$this->assertContains( RedirectHandler::QUERY_VAR, $this->handler->addQueryVar( array() ) );
	}

	private function withPrettyPermalinks(): void {
		$this->set_permalink_structure( '/%postname%/' );
	}

	public function test_repair_guard_adds_the_rule_when_it_is_missing(): void {
		$this->withPrettyPermalinks();
		delete_transient( RedirectHandler::REPAIR_TRANSIENT );
		update_option( 'rewrite_rules', array( 'unrelated/?$' => 'index.php' ) );

		$this->handler->maybeRepairRewriteRules();

		$rules = get_option( 'rewrite_rules' );
		$this->assertIsArray( $rules );
		$this->assertArrayHasKey( '^go/([a-z0-9]{4,16})/?$', $rules );
	}

	public function test_repair_guard_does_nothing_when_the_rule_is_already_present(): void {
		$this->withPrettyPermalinks();
		delete_transient( RedirectHandler::REPAIR_TRANSIENT );
		update_option(
			'rewrite_rules',
			array( '^go/([a-z0-9]{4,16})/?$' => 'index.php?rlt_code=$matches[1]' )
		);

		$before = get_option( 'rewrite_rules' );
		$this->handler->maybeRepairRewriteRules();
		$after = get_option( 'rewrite_rules' );

		$this->assertSame( $before, $after );
	}

	public function test_repair_guard_does_not_run_twice_while_its_transient_is_set(): void {
		$this->withPrettyPermalinks();
		update_option( 'rewrite_rules', array( 'unrelated/?$' => 'index.php' ) );
		set_transient( RedirectHandler::REPAIR_TRANSIENT, 1, HOUR_IN_SECONDS );

		$this->handler->maybeRepairRewriteRules();

		$rules = get_option( 'rewrite_rules' );
		$this->assertIsArray( $rules );
		$this->assertArrayNotHasKey( '^go/([a-z0-9]{4,16})/?$', $rules, 'The throttle should have skipped the repair entirely.' );

		delete_transient( RedirectHandler::REPAIR_TRANSIENT );
	}

	public function test_repair_guard_does_nothing_with_plain_permalinks(): void {
		$this->set_permalink_structure( '' );
		delete_transient( RedirectHandler::REPAIR_TRANSIENT );
		update_option( 'rewrite_rules', array( 'unrelated/?$' => 'index.php' ) );

		$this->handler->maybeRepairRewriteRules();

		$rules = get_option( 'rewrite_rules' );
		$this->assertIsArray( $rules );
		$this->assertArrayNotHasKey( '^go/([a-z0-9]{4,16})/?$', $rules );
		$this->assertFalse( get_transient( RedirectHandler::REPAIR_TRANSIENT ), 'Plain permalinks should skip the check entirely, including the throttle.' );
	}

	public function test_repair_guard_notices_a_changed_prefix_and_repairs_it(): void {
		$this->withPrettyPermalinks();
		delete_transient( RedirectHandler::REPAIR_TRANSIENT );

		// The old prefix's rule is present, but the prefix has since changed --
		// the cached rule set now lacks the *current* prefix's rule.
		update_option(
			'rewrite_rules',
			array( '^go/([a-z0-9]{4,16})/?$' => 'index.php?rlt_code=$matches[1]' )
		);
		Settings::update( array( 'prefix' => 'out' ) );
		delete_transient( RedirectHandler::REPAIR_TRANSIENT );

		$this->handler->maybeRepairRewriteRules();

		$rules = get_option( 'rewrite_rules' );
		$this->assertIsArray( $rules );
		$this->assertArrayHasKey( '^out/([a-z0-9]{4,16})/?$', $rules );
	}
}
