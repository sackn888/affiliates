<?php

namespace RLT\Tests\Integration;

use RLT\Settings;
use WP_UnitTestCase;

final class SettingsTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		delete_option( Settings::OPTION );
		delete_option( Settings::SALT_OPTION );
	}

	public function test_defaults_are_returned_when_nothing_is_saved(): void {
		$this->assertSame( 'go', Settings::get( 'prefix' ) );
		$this->assertSame( 365, Settings::get( 'retention_days' ) );
		$this->assertSame( 1, Settings::get( 'exclude_logged_in' ) );
		$this->assertSame( 'home', Settings::get( 'unknown_code' ) );
		$this->assertContains( 'hb.afl.rakuten.co.jp', Settings::get( 'hosts' ) );
	}

	public function test_update_persists_and_merges_with_defaults(): void {
		Settings::update( array( 'prefix' => 'out' ) );

		$this->assertSame( 'out', Settings::get( 'prefix' ) );
		// 触っていないキーは既定値のまま残る。
		$this->assertSame( 365, Settings::get( 'retention_days' ) );
	}

	public function test_prefix_is_sanitised(): void {
		Settings::update( array( 'prefix' => ' /Go Link/ ' ) );

		$this->assertSame( 'go-link', Settings::prefix() );
	}

	public function test_empty_prefix_falls_back_to_default(): void {
		Settings::update( array( 'prefix' => '' ) );

		$this->assertSame( 'go', Settings::prefix() );
	}

	public function test_retention_days_is_coerced_to_non_negative_int(): void {
		Settings::update( array( 'retention_days' => '-5' ) );

		$this->assertSame( 0, Settings::get( 'retention_days' ) );
	}

	public function test_hosts_are_lowercased_and_deduplicated(): void {
		Settings::update( array( 'hosts' => array( 'HB.AFL.Rakuten.co.JP', 'hb.afl.rakuten.co.jp', ' af.rakuten.co.jp ' ) ) );

		$this->assertSame( array( 'hb.afl.rakuten.co.jp', 'af.rakuten.co.jp' ), Settings::hosts() );
	}

	public function test_unknown_code_only_accepts_known_values(): void {
		Settings::update( array( 'unknown_code' => 'explode' ) );

		$this->assertSame( 'home', Settings::get( 'unknown_code' ) );
	}

	public function test_short_base_ends_with_a_slash(): void {
		$base = Settings::shortBase();

		$this->assertStringEndsWith( '/go/', $base );
		$this->assertStringStartsWith( home_url(), $base );
	}

	public function test_short_url_appends_the_code(): void {
		$this->assertSame( Settings::shortBase() . 'abc123', Settings::shortUrl( 'abc123' ) );
	}

	public function test_salt_is_created_on_first_access_and_then_stable(): void {
		$first  = Settings::salt();
		$second = Settings::salt();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $first );
		$this->assertSame( $first, $second );
	}

	public function test_rotate_salt_changes_it(): void {
		$before = Settings::salt();
		Settings::rotateSalt();

		$this->assertNotSame( $before, Settings::salt() );
	}
}
