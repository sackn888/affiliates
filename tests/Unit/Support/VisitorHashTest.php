<?php

namespace RLT\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RLT\Support\VisitorHash;

final class VisitorHashTest extends TestCase {

	public function test_returns_64_character_hex(): void {
		$hash = VisitorHash::make( '203.0.113.5', 'Mozilla/5.0', 'salt' );

		$this->assertSame( 64, strlen( $hash ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash );
	}

	public function test_is_deterministic_for_same_inputs(): void {
		$a = VisitorHash::make( '203.0.113.5', 'Mozilla/5.0', 'salt' );
		$b = VisitorHash::make( '203.0.113.5', 'Mozilla/5.0', 'salt' );

		$this->assertSame( $a, $b );
	}

	public function test_differs_when_ip_differs(): void {
		$a = VisitorHash::make( '203.0.113.5', 'Mozilla/5.0', 'salt' );
		$b = VisitorHash::make( '203.0.113.6', 'Mozilla/5.0', 'salt' );

		$this->assertNotSame( $a, $b );
	}

	public function test_differs_when_user_agent_differs(): void {
		$a = VisitorHash::make( '203.0.113.5', 'Mozilla/5.0', 'salt' );
		$b = VisitorHash::make( '203.0.113.5', 'Mozilla/4.0', 'salt' );

		$this->assertNotSame( $a, $b );
	}

	public function test_differs_when_salt_rotates(): void {
		$today     = VisitorHash::make( '203.0.113.5', 'Mozilla/5.0', 'salt-day-1' );
		$tomorrow  = VisitorHash::make( '203.0.113.5', 'Mozilla/5.0', 'salt-day-2' );

		// ソルトが変わると同一訪問者を追跡できなくなる。これが設計上の狙い。
		$this->assertNotSame( $today, $tomorrow );
	}

	public function test_output_is_a_hex_digest_not_the_input(): void {
		$hash = VisitorHash::make( '203.0.113.5', 'Mozilla/5.0', 'salt' );

		// Irreversibility is a design property of hashing, not something a unit test can
		// establish. But we can verify that the output is not plaintext input.
		$this->assertStringNotContainsString( '203.0.113.5', $hash );
		$this->assertStringNotContainsString( 'Mozilla/5.0', $hash );
	}

	public function test_a_pipe_in_the_user_agent_cannot_forge_another_visitors_hash(): void {
		// ユーザーエージェントは訪問者が自由に決められる。区切り文字をまたいで
		// 別の訪問者のハッシュに一致させられてはならない。
		$crafted = VisitorHash::make( '203.0.113.5', 'Mozilla|salt-a', 'salt-b' );
		$honest  = VisitorHash::make( '203.0.113.5', 'Mozilla', 'salt-a|salt-b' );

		$this->assertNotSame( $crafted, $honest );
	}

	public function test_new_salt_is_random_hex(): void {
		$a = VisitorHash::newSalt();
		$b = VisitorHash::newSalt();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $a );
		$this->assertNotSame( $a, $b );
	}
}
