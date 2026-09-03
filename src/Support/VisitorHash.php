<?php

declare(strict_types=1);

namespace RLT\Support;

/**
 * Derives a non-reversible visitor identifier.
 *
 * The raw IP address is never stored anywhere. Because the salt rotates daily,
 * the same visitor produces a different hash tomorrow, so "unique" counts are
 * per-day only. That is an intentional privacy trade-off, not a bug.
 *
 * Pure: no WordPress dependency.
 */
final class VisitorHash {

	public static function make( string $ip, string $userAgent, string $salt ): string {
		return hash( 'sha256', $ip . '|' . $userAgent . '|' . $salt );
	}

	public static function newSalt(): string {
		return bin2hex( random_bytes( 32 ) );
	}
}
