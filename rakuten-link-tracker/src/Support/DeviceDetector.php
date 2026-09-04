<?php

declare(strict_types=1);

namespace RLT\Support;

/**
 * Coarse device classification from a user agent string.
 *
 * Deliberately simple: the goal is a three-way split for reporting, not
 * accurate device identification.
 *
 * Pure: no WordPress dependency.
 */
final class DeviceDetector {

	public const UNKNOWN = 0;
	public const DESKTOP = 1;
	public const MOBILE  = 2;
	public const TABLET  = 3;

	public static function detect( string $userAgent ): int {
		$ua = strtolower( trim( $userAgent ) );

		if ( '' === $ua ) {
			return self::UNKNOWN;
		}

		if ( str_contains( $ua, 'ipad' ) || str_contains( $ua, 'tablet' ) || str_contains( $ua, 'kindle' ) || str_contains( $ua, 'playbook' ) ) {
			return self::TABLET;
		}

		// Android without the "mobile" token is conventionally a tablet.
		if ( str_contains( $ua, 'android' ) ) {
			return str_contains( $ua, 'mobile' ) ? self::MOBILE : self::TABLET;
		}

		if ( str_contains( $ua, 'iphone' ) || str_contains( $ua, 'ipod' ) || str_contains( $ua, 'mobile' ) || str_contains( $ua, 'windows phone' ) ) {
			return self::MOBILE;
		}

		return self::DESKTOP;
	}

	public static function label( int $device ): string {
		return match ( $device ) {
			self::DESKTOP => 'desktop',
			self::MOBILE  => 'mobile',
			self::TABLET  => 'tablet',
			default       => 'unknown',
		};
	}
}
