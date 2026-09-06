<?php

namespace RLT\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RLT\Support\DestinationHost;

final class DestinationHostTest extends TestCase {

	public function test_resolves_the_host_encoded_in_the_pc_parameter(): void {
		$url = 'https://hb.afl.rakuten.co.jp/hgc/3f340f4d.../_RTLink137659'
			. '?pc=' . rawurlencode( 'https://travel.rakuten.co.jp/HOTEL/9611/9611.html' );

		$this->assertSame( 'travel.rakuten.co.jp', DestinationHost::resolve( $url ) );
	}

	public function test_falls_back_to_the_target_host_when_pc_is_absent(): void {
		$url = 'https://hb.afl.rakuten.co.jp/hgc/abc123/';

		$this->assertSame( 'hb.afl.rakuten.co.jp', DestinationHost::resolve( $url ) );
	}

	public function test_falls_back_to_the_target_host_when_pc_is_blank(): void {
		$url = 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=';

		$this->assertSame( 'hb.afl.rakuten.co.jp', DestinationHost::resolve( $url ) );
	}

	public function test_falls_back_to_the_target_host_when_pc_is_not_an_absolute_url(): void {
		$url = 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=' . rawurlencode( '/HOTEL/9611/9611.html' );

		$this->assertSame( 'hb.afl.rakuten.co.jp', DestinationHost::resolve( $url ) );
	}

	public function test_falls_back_to_the_target_host_when_pc_uses_a_non_http_scheme(): void {
		$url = 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=' . rawurlencode( 'javascript://travel.rakuten.co.jp/' );

		$this->assertSame( 'hb.afl.rakuten.co.jp', DestinationHost::resolve( $url ) );
	}

	public function test_lowercases_the_resolved_host(): void {
		$url = 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=' . rawurlencode( 'https://Travel.Rakuten.co.jp/HOTEL/9611/' );

		$this->assertSame( 'travel.rakuten.co.jp', DestinationHost::resolve( $url ) );
	}

	public function test_handles_a_url_with_no_query_string_at_all(): void {
		$this->assertSame( 'af.rakuten.co.jp', DestinationHost::resolve( 'https://af.rakuten.co.jp/link/abc' ) );
	}

	public function test_handles_other_query_parameters_alongside_pc(): void {
		$url = 'https://hb.afl.rakuten.co.jp/hgc/abc123/_RTLink1?scid=af_pc_link'
			. '&pc=' . rawurlencode( 'https://travel.rakuten.co.jp/HOTEL/9611/9611.html' );

		$this->assertSame( 'travel.rakuten.co.jp', DestinationHost::resolve( $url ) );
	}
}
