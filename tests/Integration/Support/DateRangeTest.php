<?php

namespace RLT\Tests\Integration\Support;

use RLT\Support\DateRange;
use WP_UnitTestCase;

final class DateRangeTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		update_option( 'timezone_string', 'Asia/Tokyo' );
	}

	public function test_utc_bounds_cover_the_whole_local_days(): void {
		$range = new DateRange( '2026-09-01', '2026-09-02' );

		// JST は UTC+9 なので、9/1 00:00 JST は 8/31 15:00 UTC。
		$this->assertSame( '2026-08-31 15:00:00', $range->startUtc() );
		$this->assertSame( '2026-09-02 14:59:59', $range->endUtc() );
	}

	public function test_offset_seconds_matches_the_site_timezone(): void {
		$this->assertSame( 32400, ( new DateRange( '2026-09-01', '2026-09-02' ) )->offsetSeconds() );
	}

	public function test_days_lists_every_local_day_inclusive(): void {
		$range = new DateRange( '2026-09-01', '2026-09-03' );

		$this->assertSame( array( '2026-09-01', '2026-09-02', '2026-09-03' ), $range->days() );
		$this->assertSame( 3, $range->dayCount() );
	}

	public function test_reversed_dates_are_swapped(): void {
		$range = new DateRange( '2026-09-05', '2026-09-01' );

		$this->assertSame( '2026-09-01', $range->fromDate() );
		$this->assertSame( '2026-09-05', $range->toDate() );
	}

	public function test_previous_returns_an_equally_long_earlier_window(): void {
		$previous = ( new DateRange( '2026-09-08', '2026-09-14' ) )->previous();

		$this->assertSame( '2026-09-01', $previous->fromDate() );
		$this->assertSame( '2026-09-07', $previous->toDate() );
		$this->assertSame( 7, $previous->dayCount() );
	}

	public function test_last_days_ends_today(): void {
		$range = DateRange::lastDays( 7 );

		$this->assertSame( 7, $range->dayCount() );
		$this->assertSame( current_time( 'Y-m-d' ), $range->toDate() );
	}

	public function test_from_request_falls_back_to_the_default_window(): void {
		$range = DateRange::fromRequest( null, null, 28 );

		$this->assertSame( 28, $range->dayCount() );
	}

	public function test_from_request_rejects_malformed_dates(): void {
		$range = DateRange::fromRequest( 'not-a-date', '2026-13-45', 7 );

		$this->assertSame( 7, $range->dayCount() );
	}

	public function test_from_request_accepts_valid_dates(): void {
		$range = DateRange::fromRequest( '2026-09-01', '2026-09-03', 28 );

		$this->assertSame( '2026-09-01', $range->fromDate() );
		$this->assertSame( '2026-09-03', $range->toDate() );
	}
}
