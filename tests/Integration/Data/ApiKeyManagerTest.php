<?php

namespace RLT\Tests\Integration\Data;

use RLT\Data\ApiKeyManager;
use WP_UnitTestCase;

final class ApiKeyManagerTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		ApiKeyManager::deleteAll();
	}

	public function test_create_returns_a_plaintext_key_once(): void {
		$created = ApiKeyManager::create( 'Looker Studio' );

		$this->assertStringStartsWith( 'rlt_', $created['key'] );
		$this->assertSame( 'Looker Studio', $created['label'] );

		// 一覧には平文キーもハッシュも出さない。
		$listed = ApiKeyManager::all()[0];
		$this->assertArrayNotHasKey( 'key', $listed );
		$this->assertArrayNotHasKey( 'hash', $listed );
	}

	public function test_verify_accepts_a_created_key(): void {
		$created = ApiKeyManager::create( 'BI' );

		$record = ApiKeyManager::verify( $created['key'] );

		$this->assertIsArray( $record );
		$this->assertSame( $created['id'], $record['id'] );
	}

	public function test_verify_rejects_an_unknown_key(): void {
		ApiKeyManager::create( 'BI' );

		$this->assertNull( ApiKeyManager::verify( 'rlt_deadbeef' ) );
	}

	public function test_verify_rejects_an_empty_key(): void {
		$this->assertNull( ApiKeyManager::verify( '' ) );
	}

	public function test_plaintext_key_is_never_stored(): void {
		$created = ApiKeyManager::create( 'BI' );

		$raw = wp_json_encode( get_option( ApiKeyManager::OPTION ) );

		$this->assertStringNotContainsString( $created['key'], (string) $raw );
	}

	public function test_verify_records_last_used(): void {
		$created = ApiKeyManager::create( 'BI' );

		$this->assertNull( ApiKeyManager::all()[0]['last_used_at'] );

		ApiKeyManager::verify( $created['key'] );

		$this->assertNotNull( ApiKeyManager::all()[0]['last_used_at'] );
	}

	public function test_revoke_removes_the_key(): void {
		$created = ApiKeyManager::create( 'BI' );

		$this->assertTrue( ApiKeyManager::revoke( $created['id'] ) );
		$this->assertSame( array(), ApiKeyManager::all() );
		$this->assertNull( ApiKeyManager::verify( $created['key'] ) );
	}

	public function test_revoke_returns_false_for_an_unknown_id(): void {
		$this->assertFalse( ApiKeyManager::revoke( 'nope' ) );
	}

	public function test_keys_are_unique(): void {
		$a = ApiKeyManager::create( 'A' );
		$b = ApiKeyManager::create( 'B' );

		$this->assertNotSame( $a['key'], $b['key'] );
		$this->assertNotSame( $a['id'], $b['id'] );
		$this->assertCount( 2, ApiKeyManager::all() );
	}

	public function test_label_is_sanitised(): void {
		$created = ApiKeyManager::create( '<script>alert(1)</script>BI' );

		$this->assertStringNotContainsString( '<script>', $created['label'] );
	}

	public function test_verify_does_not_resurrect_a_key_revoked_during_the_call(): void {
		$created = ApiKeyManager::create( 'BI' );

		// verify() が last_used_at を書き戻す直前に、別リクエストが失効させた状況を作る。
		// 古い配列をそのまま書き戻すと、失効が取り消されてキーが復活してしまう。
		add_filter(
			'pre_update_option_' . ApiKeyManager::OPTION,
			static function ( $value ) use ( $created ) {
				static $done = false;

				if ( ! $done ) {
					$done = true;
					ApiKeyManager::revoke( $created['id'] );
				}

				return $value;
			}
		);

		ApiKeyManager::verify( $created['key'] );

		$this->assertSame( array(), ApiKeyManager::all(), 'A concurrent revoke was undone.' );
		$this->assertNull( ApiKeyManager::verify( $created['key'] ), 'A revoked key still verifies.' );
	}

	public function test_ids_are_wide_enough_to_not_collide(): void {
		$created = ApiKeyManager::create( 'BI' );

		// 32ビットでは衝突時に既存レコードを黙って上書きしてしまう。
		$this->assertSame( 16, strlen( $created['id'] ) );
	}

	public function test_creating_many_keys_never_loses_one(): void {
		$made = array();

		for ( $i = 0; $i < 25; $i++ ) {
			$made[] = ApiKeyManager::create( 'key-' . $i );
		}

		$this->assertCount( 25, ApiKeyManager::all() );

		foreach ( $made as $key ) {
			$this->assertNotNull( ApiKeyManager::verify( $key['key'] ), 'A created key stopped verifying.' );
		}
	}
}
