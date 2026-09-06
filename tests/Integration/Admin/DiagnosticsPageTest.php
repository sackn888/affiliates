<?php

namespace RLT\Tests\Integration\Admin;

use RLT\Admin\AdminMenu;
use RLT\Admin\DiagnosticsPage;
use RLT\Data\LinkRepository;
use RLT\Installer;
use RLT\Settings;
use WP_UnitTestCase;

final class DiagnosticsPageTest extends WP_UnitTestCase {

	private DiagnosticsPage $page;

	protected function setUp(): void {
		parent::setUp();
		update_option( 'timezone_string', 'Asia/Tokyo' );
		update_option( 'permalink_structure', '/%postname%/' );
		delete_option( Settings::OPTION );

		$this->page = new DiagnosticsPage();
	}

	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();
		parent::tearDown();
	}

	private function render(): string {
		ob_start();
		$this->page->render();

		return (string) ob_get_clean();
	}

	private function insertClick( array $overrides = array() ): void {
		global $wpdb;

		$link = ( new LinkRepository() )->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'ホテルA' );

		$wpdb->insert(
			Installer::clicksTable(),
			array_merge(
				array(
					'link_id'      => $link['id'],
					'post_id'      => 42,
					'clicked_at'   => gmdate( 'Y-m-d H:i:s' ),
					'visitor_hash' => str_pad( 'v', 64, '0' ),
					'referer'      => '',
					'device'       => 1,
					'is_bot'       => 0,
				),
				$overrides
			)
		);
	}

	public function test_render_requires_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( \WPDieException::class );
		$this->page->render();
	}

	public function test_render_works_for_an_administrator(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$html = $this->render();

		$this->assertStringContainsString( '楽天リンク 診断', $html );
	}

	public function test_reports_would_not_be_recorded_when_excluded_and_logged_in(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Settings::update( array( 'exclude_logged_in' => 1 ) );

		$html = $this->render();

		$this->assertStringContainsString( '記録されません', $html );
	}

	public function test_reports_would_be_recorded_when_setting_is_off(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Settings::update( array( 'exclude_logged_in' => 0 ) );

		$html = $this->render();

		$this->assertStringContainsString( '記録されます', $html );
	}

	public function test_reports_a_missing_rewrite_rule(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( 'rewrite_rules' );

		$html = $this->render();

		$this->assertStringContainsString( '要確認：リライトルールが見つかりません', $html );
	}

	public function test_reports_ok_when_the_rewrite_rule_is_present(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		update_option(
			'rewrite_rules',
			array( '^go/([a-z0-9]{4,16})/?$' => 'index.php?rlt_code=$matches[1]' )
		);

		$html = $this->render();

		$this->assertStringNotContainsString( '要確認：リライトルールが見つかりません', $html );
	}

	public function test_lists_a_recorded_click_and_marks_a_bot_row(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->insertClick( array( 'is_bot' => 0 ) );
		$this->insertClick( array( 'is_bot' => 1 ) );

		$html = $this->render();

		$this->assertStringContainsString( 'ホテルA', $html );
		$this->assertStringContainsString( 'rlt-bot-row', $html );
	}

	public function test_states_plainly_when_there_are_no_clicks_at_all(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$html = $this->render();

		$this->assertStringContainsString( 'これまでに一件もクリックが記録されていません。', $html );
	}

	public function test_a_hostile_referer_is_escaped(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->insertClick( array( 'referer' => '"><script>alert(1)</script>' ) );

		$html = $this->render();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
	}

	public function test_visitor_hash_never_appears_in_the_rendered_html(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$hash = str_repeat( 'abcdef01', 8 );
		$this->insertClick( array( 'visitor_hash' => $hash ) );

		$html = $this->render();

		$this->assertStringNotContainsString( 'visitor_hash', $html );
		$this->assertStringNotContainsString( $hash, $html );
	}

	public function test_self_test_records_a_row_and_reports_success(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Settings::update( array( 'exclude_logged_in' => 0 ) );
		( new LinkRepository() )->findOrCreate( 'https://hb.afl.rakuten.co.jp/self-test', 7, 'テストリンク' );

		$_POST = array(
			'_wpnonce' => wp_create_nonce( DiagnosticsPage::NONCE ),
		);

		$this->page->handleSelfTest( false );

		global $wpdb;
		$row = $wpdb->get_row( 'SELECT referer FROM ' . Installer::clicksTable() . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
		// esc_url_raw() (used by RequestContext::referer()) normalises a bare
		// string like "rlt-selftest" by prepending a scheme, so the stored
		// value is not byte-identical to the constant -- but it still carries
		// the same recognisable marker.
		$this->assertStringContainsString( DiagnosticsPage::SELFTEST_REFERER, $row['referer'] );

		$html = $this->render();
		$this->assertStringContainsString( '記録されました', $html );
	}

	public function test_self_test_refuses_without_a_valid_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Settings::update( array( 'exclude_logged_in' => 0 ) );
		( new LinkRepository() )->findOrCreate( 'https://hb.afl.rakuten.co.jp/self-test', 7, 'テストリンク' );

		$_POST = array( '_wpnonce' => 'wrong' );

		global $wpdb;
		$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::clicksTable() );

		$this->page->handleSelfTest( false );

		$after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::clicksTable() );

		$this->assertSame( $before, $after );
	}

	public function test_self_test_reports_no_links_when_none_exist(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_POST = array(
			'_wpnonce' => wp_create_nonce( DiagnosticsPage::NONCE ),
		);

		$this->page->handleSelfTest( false );

		$html = $this->render();
		$this->assertStringContainsString( 'このサイトにリンクが1件も存在しない', $html );
	}

	public function test_menu_registers_the_diagnostics_page(): void {
		global $submenu;

		( new AdminMenu() )->addPages();

		$slugs = array_column( $submenu[ AdminMenu::SLUG ] ?? array(), 2 );
		$this->assertContains( AdminMenu::SLUG_DIAGNOSTICS, $slugs );
	}
}
