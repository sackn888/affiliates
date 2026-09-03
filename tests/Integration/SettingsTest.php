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

	/**
	 * @dataProvider hostInputs
	 */
	public function test_hosts_are_normalised_to_bare_hostnames( string $input, string $expected ): void {
		Settings::update( array( 'hosts' => array( $input ) ) );

		$this->assertSame( array( $expected ), Settings::hosts() );
	}

	public static function hostInputs(): array {
		return array(
			'bare host'        => array( 'hb.afl.rakuten.co.jp', 'hb.afl.rakuten.co.jp' ),
			'https url'        => array( 'https://hb.afl.rakuten.co.jp/', 'hb.afl.rakuten.co.jp' ),
			'http url'         => array( 'http://hb.afl.rakuten.co.jp', 'hb.afl.rakuten.co.jp' ),
			'url with path'    => array( 'https://hb.afl.rakuten.co.jp/hgc/abc/', 'hb.afl.rakuten.co.jp' ),
			'scheme relative'  => array( '//hb.afl.rakuten.co.jp/x', 'hb.afl.rakuten.co.jp' ),
			'trailing slash'   => array( 'hb.afl.rakuten.co.jp/', 'hb.afl.rakuten.co.jp' ),
			'with port'        => array( 'hb.afl.rakuten.co.jp:443', 'hb.afl.rakuten.co.jp' ),
			'uppercase'        => array( 'HB.AFL.Rakuten.CO.JP', 'hb.afl.rakuten.co.jp' ),
			'padded'           => array( '  hb.afl.rakuten.co.jp  ', 'hb.afl.rakuten.co.jp' ),
		);
	}

	public function test_a_host_entered_as_a_url_still_matches_the_extractor(): void {
		Settings::update( array( 'hosts' => array( 'https://hb.afl.rakuten.co.jp/' ) ) );

		$extractor = new \RLT\Support\LinkExtractor( Settings::hosts(), Settings::shortBase() );
		$links     = $extractor->extract( '<a href="https://hb.afl.rakuten.co.jp/hgc/abc/?pc=x">ホテル</a>' );

		// 設定とエクストラクタの突き合わせが崩れていると、ここで 0 件になる。
		$this->assertCount( 1, $links );
	}

	/**
	 * @dataProvider reservedPrefixes
	 */
	public function test_reserved_prefixes_fall_back_to_the_default( string $prefix ): void {
		Settings::update( array( 'prefix' => $prefix ) );

		$this->assertSame( 'go', Settings::prefix() );
	}

	public static function reservedPrefixes(): array {
		return array(
			'wp-admin' => array( 'wp-admin' ),
			'wp-json'  => array( 'wp-json' ),
			'feed'     => array( 'feed' ),
			'category' => array( 'category' ),
			'uppercase reserved' => array( 'Feed' ),
		);
	}

	public function test_an_ordinary_prefix_is_still_accepted(): void {
		Settings::update( array( 'prefix' => 'out' ) );

		$this->assertSame( 'out', Settings::prefix() );
	}

	public function test_salt_survives_a_concurrent_creation(): void {
		delete_option( Settings::SALT_OPTION );

		// 先に別リクエストがソルトを作った状況を再現する。
		$winner = str_repeat( 'a', 64 );
		add_option( Settings::SALT_OPTION, $winner, '', false );

		// 後発のリクエストは自分で生成した値ではなく、既存の値を使わなければならない。
		$this->assertSame( $winner, Settings::salt() );
	}
}
