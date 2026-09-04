<?php

declare(strict_types=1);

namespace RLT\Support;

/**
 * Derives a visitor identifier from IP and user-agent.
 *
 * The raw IP address is never stored anywhere. Because the salt rotates daily,
 * the same visitor produces a different hash tomorrow, so "unique" counts are
 * per-day only. This is an intentional privacy trade-off. Note that with a known
 * salt, the digest is not resistant to brute-force attacks over the IP × user-agent space.
 *
 * Pure: no WordPress dependency.
 */
final class VisitorHash {

	public static function make( string $ip, string $userAgent, string $salt ): string {
		// Hash each field independently so an attacker cannot use a pipe character
		// in the user-agent to forge a hash with a different salt value.
		return hash(
			'sha256',
			hash( 'sha256', $ip ) . hash( 'sha256', $userAgent ) . $salt
		);
	}

	public static function newSalt(): string {
		return bin2hex( random_bytes( 32 ) );
	}
}
