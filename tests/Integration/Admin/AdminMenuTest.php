<?php

namespace RLT\Tests\Integration\Admin;

use RLT\Admin\AdminMenu;
use WP_UnitTestCase;

final class AdminMenuTest extends WP_UnitTestCase {

	private AdminMenu $menu;
	private string $originalPermalinkStructure;

	protected function setUp(): void {
		parent::setUp();

		$this->menu = new AdminMenu();
		$this->originalPermalinkStructure = (string) get_option( 'permalink_structure' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	protected function tearDown(): void {
		$this->set_permalink_structure( $this->originalPermalinkStructure );
		set_current_screen( 'front' );

		parent::tearDown();
	}

	private function render(): string {
		ob_start();
		$this->menu->maybePermalinkNotice();

		return (string) ob_get_clean();
	}

	public function test_notice_appears_on_a_plugin_screen_with_plain_permalinks(): void {
		$this->set_permalink_structure( '' );
		set_current_screen( 'toplevel_page_' . AdminMenu::SLUG );

		$this->assertStringContainsString( 'notice-error', $this->render() );
	}

	public function test_notice_is_absent_with_pretty_permalinks(): void {
		$this->set_permalink_structure( '/%postname%/' );
		set_current_screen( 'toplevel_page_' . AdminMenu::SLUG );

		$this->assertSame( '', $this->render() );
	}

	public function test_notice_is_absent_on_an_unrelated_admin_screen(): void {
		$this->set_permalink_structure( '' );
		set_current_screen( 'edit-post' );

		$this->assertSame( '', $this->render() );
	}
}
