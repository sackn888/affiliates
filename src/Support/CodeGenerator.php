<?php

declare(strict_types=1);

namespace RLT\Support;

/**
 * Generates short codes for tracked links.
 *
 * Pure: no WordPress dependency.
 */
final class CodeGenerator {

	/**
	 * Lowercase alphanumerics minus the characters people misread: 0/o, 1/l/i.
	 */
	public const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

	public const LENGTH = 6;

	/**
	 * Codes accepted by the rewrite rule. Kept wider than LENGTH so codes issued
	 * by a future version with a different length still resolve.
	 *
	 * Use \A and \z instead of ^ and $ to avoid matching trailing newlines,
	 * since $ in PHP regex matches before a trailing newline even without the 'm' modifier.
	 */
	private const VALID_PATTERN = '/\A[' . self::ALPHABET . ']{4,16}\z/';

	public static function generate(): string {
		$max  = strlen( self::ALPHABET ) - 1;
		$code = '';

		for ( $i = 0; $i < self::LENGTH; $i++ ) {
			$code .= self::ALPHABET[ random_int( 0, $max ) ];
		}

		return $code;
	}

	public static function isValid( string $code ): bool {
		return 1 === preg_match( self::VALID_PATTERN, $code );
	}
}
