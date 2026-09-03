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
}
