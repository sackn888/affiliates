<?php

declare(strict_types=1);

namespace RLT\Support;

/**
 * A closed range of site-local days, with the UTC bounds needed to query the
 * event tables.
 *
 * Every timestamp in the database is UTC while every report is read in site
 * time, so the conversion lives here and nowhere else.
 */
final class DateRange {

	private const FORMAT = 'Y-m-d';

	/**
	 * Two years plus a leap day. The read-only API key this endpoint accepts is
	 * explicitly meant to be handed to a lower-trust holder (a BI tool, an AI
	 * tool), and clicksForExport()/viewsForExport() have no LIMIT, so an
	 * unbounded "from" (e.g. 1970-01-01) is a cheap way for that bearer to
	 * force an unbounded query and response body. Capping the span here closes
	 * that off without rejecting the request outright.
	 */
	public const MAX_DAYS = 731;

	private \DateTimeImmutable $from;
	private \DateTimeImmutable $to;

	public function __construct( string $from, string $to ) {
		$tz = wp_timezone();

		$start = new \DateTimeImmutable( $from . ' 00:00:00', $tz );
		$end   = new \DateTimeImmutable( $to . ' 00:00:00', $tz );

		if ( $end < $start ) {
			[ $start, $end ] = array( $end, $start );
		}

		$this->from = $start;
		$this->to   = $end;
	}

	public static function lastDays( int $days ): self {
		$days  = max( 1, $days );
		$today = current_time( self::FORMAT );
		$start = ( new \DateTimeImmutable( $today, wp_timezone() ) )->modify( '-' . ( $days - 1 ) . ' days' );

		return new self( $start->format( self::FORMAT ), $today );
	}

	/**
	 * Build a range from untrusted input, falling back to a default window.
	 *
	 * Both dates must be well-formed `Y-m-d` strings that round-trip exactly;
	 * anything else (an empty string, null, garbage, or a date PHP would
	 * otherwise silently roll over such as 2026-13-45) falls back rather than
	 * being trusted.
	 */
	public static function fromRequest( ?string $from, ?string $to, int $defaultDays = 28 ): self {
		if ( ! self::isValidDate( $from ) || ! self::isValidDate( $to ) ) {
			return self::lastDays( $defaultDays );
		}

		$range = new self( (string) $from, (string) $to );

		if ( $range->dayCount() > self::MAX_DAYS ) {
			// Clamp rather than reject: keep the end the caller asked for and pull
			// the start forward so the span is at most MAX_DAYS days.
			$clampedStart = $range->to->modify( '-' . ( self::MAX_DAYS - 1 ) . ' days' );
			$range        = new self( $clampedStart->format( self::FORMAT ), $range->toDate() );
		}

		return $range;
	}

	public function fromDate(): string {
		return $this->from->format( self::FORMAT );
	}

	public function toDate(): string {
		return $this->to->format( self::FORMAT );
	}

	public function startUtc(): string {
		return $this->from->setTime( 0, 0, 0 )
			->setTimezone( new \DateTimeZone( 'UTC' ) )
			->format( 'Y-m-d H:i:s' );
	}

	public function endUtc(): string {
		return $this->to->setTime( 23, 59, 59 )
			->setTimezone( new \DateTimeZone( 'UTC' ) )
			->format( 'Y-m-d H:i:s' );
	}

	/**
	 * @return string[]
	 */
	public function days(): array {
		$days   = array();
		$cursor = $this->from;

		while ( $cursor <= $this->to ) {
			$days[] = $cursor->format( self::FORMAT );
			$cursor = $cursor->modify( '+1 day' );
		}

		return $days;
	}

	public function dayCount(): int {
		return count( $this->days() );
	}

	/**
	 * The equally long window immediately before this one, for period-over-period
	 * comparisons.
	 */
	public function previous(): self {
		$length = $this->dayCount();
		$end    = $this->from->modify( '-1 day' );
		$start  = $end->modify( '-' . ( $length - 1 ) . ' days' );

		return new self( $start->format( self::FORMAT ), $end->format( self::FORMAT ) );
	}

	/**
	 * Seconds to add to a UTC timestamp to get site-local time.
	 */
	public function offsetSeconds(): int {
		return wp_timezone()->getOffset( $this->from );
	}

	/**
	 * PHP's date parsing is lenient by default (DateTime happily rolls
	 * 2026-13-45 forward into a later, valid date), which would let malformed
	 * request input silently produce a wrong-but-plausible range. Parsing
	 * with a fixed format and requiring an exact round-trip back to the
	 * original string catches that instead of trusting it.
	 */
	private static function isValidDate( ?string $value ): bool {
		if ( null === $value || '' === $value ) {
			return false;
		}

		$parsed = \DateTimeImmutable::createFromFormat( '!' . self::FORMAT, $value, new \DateTimeZone( 'UTC' ) );

		return $parsed instanceof \DateTimeImmutable && $parsed->format( self::FORMAT ) === $value;
	}
}
