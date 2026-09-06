<?php

namespace RLT\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RLT\Support\DestinationId;

final class DestinationIdTest extends TestCase {

	public function test_resolves_the_hotel_id_from_the_reported_url(): void {
		$url = 'https://hb.afl.rakuten.co.jp/hgc/2452369c.9c4d0e37.2452369d.f06761a5/_RTLink137659'
			. '?pc=' . rawurlencode( 'https://travel.rakuten.co.jp/HOTEL/9611/9611.html' );

		$this->assertSame( '9611', DestinationId::resolve( $url ) );
	}

	public function test_returns_null_when_pc_is_absent(): void {
		$url = 'https://hb.afl.rakuten.co.jp/hgc/abc123/';

		$this->assertNull( DestinationId::resolve( $url ) );
	}

	public function test_returns_null_when_pc_is_blank(): void {
		$url = 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=';

		$this->assertNull( DestinationId::resolve( $url ) );
	}

	public function test_returns_null_when_no_path_segment_is_entirely_digits(): void {
		$url = 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=' . rawurlencode( 'https://travel.rakuten.co.jp/HOTEL/abc/index.html' );

		$this->assertNull( DestinationId::resolve( $url ) );
	}

	public function test_returns_null_when_pc_uses_a_non_http_scheme(): void {
		$url = 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=' . rawurlencode( 'javascript://travel.rakuten.co.jp/9611/' );

		$this->assertNull( DestinationId::resolve( $url ) );
	}

	public function test_returns_null_when_pc_has_no_path(): void {
		$url = 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=' . rawurlencode( 'https://travel.rakuten.co.jp' );

		$this->assertNull( DestinationId::resolve( $url ) );
	}

	public function test_picks_the_first_all_digit_segment_when_several_are_present(): void {
		$url = 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=' . rawurlencode( 'https://travel.rakuten.co.jp/HOTEL/9611/9611.html?plan=2222' );

		$this->assertSame( '9611', DestinationId::resolve( $url ) );
	}

	public function test_a_partially_numeric_segment_does_not_count(): void {
		$url = 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=' . rawurlencode( 'https://travel.rakuten.co.jp/HOTEL/9611a/index.html' );

		$this->assertNull( DestinationId::resolve( $url ) );
	}
}
