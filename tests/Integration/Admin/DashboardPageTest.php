<?php

namespace RLT\Tests\Integration\Admin;

use RLT\Admin\AdminMenu;
use RLT\Admin\DashboardPage;
use RLT\Data\LinkRepository;
use RLT\Installer;
use WP_UnitTestCase;

final class DashboardPageTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		update_option( 'timezone_string', 'Asia/Tokyo' );
		set_current_screen( 'dashboard' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	protected function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	private function render(): string {
		ob_start();
		( new DashboardPage() )->render();

		return (string) ob_get_clean();
	}

	public function test_menu_pages_are_registered(): void {
		global $admin_page_hooks, $submenu;

		( new AdminMenu() )->addPages();

		$this->assertArrayHasKey( AdminMenu::SLUG, $admin_page_hooks );

		$slugs = array_column( $submenu[ AdminMenu::SLUG ] ?? array(), 2 );
		$this->assertContains( AdminMenu::SLUG, $slugs );
		$this->assertContains( AdminMenu::SLUG_LINKS, $slugs );
		$this->assertContains( AdminMenu::SLUG_POSTS, $slugs );
		$this->assertContains( AdminMenu::SLUG_SETTINGS, $slugs );
	}

	public function test_the_toplevel_hook_has_exactly_one_callback(): void {
		global $wp_filter;

		( new AdminMenu() )->addPages();

		$hookName = 'toplevel_page_' . AdminMenu::SLUG;

		$this->assertArrayHasKey( $hookName, $wp_filter );

		$callbackCount = 0;
		foreach ( $wp_filter[ $hookName ]->callbacks as $priority => $callbacks ) {
			$callbackCount += count( $callbacks );
		}

		$this->assertSame( 1, $callbackCount, 'The toplevel dashboard hook must have exactly one callback, or the page renders twice.' );
	}

	public function test_firing_the_toplevel_hook_renders_the_dashboard_exactly_once(): void {
		( new AdminMenu() )->addPages();

		ob_start();
		do_action( 'toplevel_page_' . AdminMenu::SLUG );
		$html = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $html, 'rlt-dashboard' ), 'Firing the toplevel hook must render the dashboard exactly once.' );
	}

	public function test_render_outputs_the_summary_cards(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'rlt-dashboard', $html );
		$this->assertStringContainsString( 'PV', $html );
		$this->assertStringContainsString( 'CTR', $html );
	}

	public function test_render_includes_both_charts(): void {
		$html = $this->render();

		$this->assertSame( 2, substr_count( $html, '<svg' ) );
	}

	public function test_render_lists_top_links(): void {
		global $wpdb;

		$link = ( new LinkRepository() )->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'ホテルA' );
		$wpdb->insert(
			Installer::clicksTable(),
			array(
				'link_id'      => $link['id'],
				'post_id'      => 42,
				'clicked_at'   => gmdate( 'Y-m-d H:i:s' ),
				'visitor_hash' => str_pad( 'v', 64, '0' ),
				'referer'      => '',
				'device'       => 1,
				'is_bot'       => 0,
			)
		);

		$this->assertStringContainsString( 'ホテルA', $this->render() );
	}

	public function test_period_selector_offers_the_documented_windows(): void {
		$html = $this->render();

		foreach ( DashboardPage::PERIODS as $days ) {
			$this->assertStringContainsString( 'days=' . $days, $html );
		}
	}

	public function test_range_defaults_to_28_days(): void {
		$this->assertSame( 28, ( new DashboardPage() )->rangeFromQuery()->dayCount() );
	}

	public function test_range_honours_the_days_query_parameter(): void {
		$_GET['days'] = '7';

		$this->assertSame( 7, ( new DashboardPage() )->rangeFromQuery()->dayCount() );
	}

	public function test_range_honours_explicit_dates(): void {
		$_GET['from'] = '2026-09-01';
		$_GET['to']   = '2026-09-05';

		$range = ( new DashboardPage() )->rangeFromQuery();

		$this->assertSame( '2026-09-01', $range->fromDate() );
		$this->assertSame( '2026-09-05', $range->toDate() );
	}

	public function test_a_hostile_days_parameter_falls_back_to_the_default(): void {
		$_GET['days'] = '<script>';

		$this->assertSame( 28, ( new DashboardPage() )->rangeFromQuery()->dayCount() );
	}

	public function test_render_escapes_link_labels(): void {
		global $wpdb;

		$link = ( new LinkRepository() )->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, '<script>alert(1)</script>' );
		$wpdb->insert(
			Installer::clicksTable(),
			array(
				'link_id'      => $link['id'],
				'post_id'      => 42,
				'clicked_at'   => gmdate( 'Y-m-d H:i:s' ),
				'visitor_hash' => str_pad( 'v', 64, '0' ),
				'referer'      => '',
				'device'       => 1,
				'is_bot'       => 0,
			)
		);

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $this->render() );
	}
}
