<?php

namespace RLT\Tests\Integration\Admin;

use RLT\Admin\SettingsPage;
use RLT\Data\ApiKeyManager;
use RLT\Settings;
use WP_UnitTestCase;

final class SettingsPageTest extends WP_UnitTestCase {

	private SettingsPage $page;

	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( Settings::OPTION );
		ApiKeyManager::deleteAll();

		$this->page = new SettingsPage();
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

	public function test_render_shows_every_setting_field(): void {
		$html = $this->render();

		foreach ( array( 'prefix', 'hosts', 'exclude_logged_in', 'unknown_code', 'retention_days' ) as $field ) {
			$this->assertStringContainsString( 'name="' . $field, $html, "Field {$field} is missing." );
		}
	}

	public function test_render_shows_the_bulk_buttons(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'rlt-bulk-convert', $html );
		$this->assertStringContainsString( 'rlt-bulk-restore', $html );
	}

	public function test_save_persists_the_settings(): void {
		$_POST = array(
			'_wpnonce'          => wp_create_nonce( SettingsPage::NONCE ),
			'prefix'            => 'out',
			'hosts'             => "hb.afl.rakuten.co.jp\naf.rakuten.co.jp",
			'exclude_logged_in' => '1',
			'unknown_code'      => '404',
			'retention_days'    => '90',
		);

		$this->page->handleSave( false );

		$this->assertSame( 'out', Settings::get( 'prefix' ) );
		$this->assertSame( '404', Settings::get( 'unknown_code' ) );
		$this->assertSame( 90, Settings::get( 'retention_days' ) );
		$this->assertSame( array( 'hb.afl.rakuten.co.jp', 'af.rakuten.co.jp' ), Settings::hosts() );
	}

	public function test_save_without_a_valid_nonce_changes_nothing(): void {
		$_POST = array(
			'_wpnonce' => 'wrong',
			'prefix'   => 'hacked',
		);

		$this->page->handleSave( false );

		$this->assertSame( 'go', Settings::get( 'prefix' ) );
	}

	public function test_unchecked_checkbox_turns_the_setting_off(): void {
		Settings::update( array( 'exclude_logged_in' => 1 ) );

		$_POST = array(
			'_wpnonce'       => wp_create_nonce( SettingsPage::NONCE ),
			'prefix'         => 'go',
			'hosts'          => 'hb.afl.rakuten.co.jp',
			'retention_days' => '365',
			'unknown_code'   => 'home',
		);

		$this->page->handleSave( false );

		$this->assertSame( 0, Settings::get( 'exclude_logged_in' ) );
	}

	public function test_changing_the_prefix_flushes_rewrite_rules(): void {
		$flushed = false;
		add_action( 'rlt_rewrite_flushed', static function () use ( &$flushed ): void {
			$flushed = true;
		} );

		$_POST = array(
			'_wpnonce'       => wp_create_nonce( SettingsPage::NONCE ),
			'prefix'         => 'out',
			'hosts'          => 'hb.afl.rakuten.co.jp',
			'retention_days' => '365',
			'unknown_code'   => 'home',
		);

		$this->page->handleSave( false );

		// プレフィックスを変えたのに rewrite を流さないと /out/ が 404 になる。
		$this->assertTrue( $flushed );
	}

	public function test_saving_the_same_prefix_does_not_flush(): void {
		$flushed = false;
		add_action( 'rlt_rewrite_flushed', static function () use ( &$flushed ): void {
			$flushed = true;
		} );

		$_POST = array(
			'_wpnonce'       => wp_create_nonce( SettingsPage::NONCE ),
			'prefix'         => 'go',
			'hosts'          => 'hb.afl.rakuten.co.jp',
			'retention_days' => '365',
			'unknown_code'   => 'home',
		);

		$this->page->handleSave( false );

		$this->assertFalse( $flushed );
	}

	public function test_create_key_stores_a_key_and_shows_it_once(): void {
		$_POST = array(
			'_wpnonce' => wp_create_nonce( SettingsPage::NONCE ),
			'label'    => 'Looker Studio',
		);

		$this->page->handleCreateKey( false );

		$keys = ApiKeyManager::all();

		$this->assertCount( 1, $keys );
		$this->assertSame( 'Looker Studio', $keys[0]['label'] );
		$this->assertNotSame( '', get_transient( 'rlt_new_api_key' ) );
	}

	public function test_revoke_key_removes_it(): void {
		$created = ApiKeyManager::create( 'BI' );

		$_POST = array(
			'_wpnonce' => wp_create_nonce( SettingsPage::NONCE ),
			'key_id'   => $created['id'],
		);

		$this->page->handleRevokeKey( false );

		$this->assertSame( array(), ApiKeyManager::all() );
	}

	public function test_render_lists_existing_keys_without_the_secret(): void {
		$created = ApiKeyManager::create( 'BI' );

		$html = $this->render();

		$this->assertStringContainsString( 'BI', $html );
		$this->assertStringNotContainsString( $created['key'], $html );
	}

	public function test_render_shows_the_short_url_example(): void {
		$this->assertStringContainsString( Settings::shortBase(), $this->render() );
	}
}
