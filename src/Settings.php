<?php

declare(strict_types=1);

namespace RLT;

use RLT\Support\VisitorHash;

/**
 * Reads and writes plugin options, applying defaults and sanitisation in one place.
 */
final class Settings {

	public const OPTION      = 'rlt_settings';
	public const SALT_OPTION = 'rlt_visitor_salt';

	public const UNKNOWN_CODE_HOME = 'home';
	public const UNKNOWN_CODE_404  = '404';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'prefix'              => 'go',
			'hosts'               => array( 'hb.afl.rakuten.co.jp', 'af.rakuten.co.jp' ),
			'exclude_logged_in'   => 1,
			'unknown_code'        => self::UNKNOWN_CODE_HOME,
			'retention_days'      => 365,
			'click_dedup_seconds' => 5,
			'view_dedup_seconds'  => 1800,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return self::sanitise( array_merge( self::defaults(), $stored ) );
	}

	/**
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();

		return $all[ $key ] ?? null;
	}

	/**
	 * Merge the given values over what is stored. Keys not passed keep their
	 * stored (or default) value; sanitise() runs on the merged result so a
	 * partial update can never leave an invalid value behind.
	 *
	 * @param array<string, mixed> $values
	 */
	public static function update( array $values ): void {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		update_option( self::OPTION, self::sanitise( array_merge( self::defaults(), $stored, $values ) ) );
	}

	public static function prefix(): string {
		return (string) self::get( 'prefix' );
	}

	/**
	 * Absolute base for short URLs, always with a trailing slash. Built from
	 * the sanitised prefix so this stays consistent with the "already
	 * shortened" check LinkExtractor makes against the same string.
	 */
	public static function shortBase(): string {
		return home_url( '/' . self::prefix() . '/' );
	}

	public static function shortUrl( string $code ): string {
		return self::shortBase() . $code;
	}

	/**
	 * @return string[]
	 */
	public static function hosts(): array {
		return (array) self::get( 'hosts' );
	}

	/**
	 * Current visitor-hash salt, created on first use.
	 */
	public static function salt(): string {
		$salt = get_option( self::SALT_OPTION, '' );

		if ( ! is_string( $salt ) || 64 !== strlen( $salt ) ) {
			$salt = VisitorHash::newSalt();
			update_option( self::SALT_OPTION, $salt, false );
		}

		return $salt;
	}

	/**
	 * Replace the salt. Past visitor hashes become unlinkable to new ones,
	 * which is the point: it caps how long a visitor stays identifiable.
	 */
	public static function rotateSalt(): void {
		update_option( self::SALT_OPTION, VisitorHash::newSalt(), false );
	}

	/**
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private static function sanitise( array $values ): array {
		$defaults = self::defaults();

		$prefix = sanitize_title( (string) ( $values['prefix'] ?? '' ) );
		if ( '' === $prefix ) {
			$prefix = $defaults['prefix'];
		}

		$hosts = array();
		foreach ( (array) ( $values['hosts'] ?? array() ) as $host ) {
			$host = strtolower( trim( (string) $host ) );
			if ( '' !== $host && ! in_array( $host, $hosts, true ) ) {
				$hosts[] = $host;
			}
		}
		if ( array() === $hosts ) {
			$hosts = $defaults['hosts'];
		}

		$unknown = (string) ( $values['unknown_code'] ?? '' );
		if ( ! in_array( $unknown, array( self::UNKNOWN_CODE_HOME, self::UNKNOWN_CODE_404 ), true ) ) {
			$unknown = $defaults['unknown_code'];
		}

		return array(
			'prefix'              => $prefix,
			'hosts'               => $hosts,
			'exclude_logged_in'   => empty( $values['exclude_logged_in'] ) ? 0 : 1,
			'unknown_code'        => $unknown,
			'retention_days'      => max( 0, (int) ( $values['retention_days'] ?? $defaults['retention_days'] ) ),
			'click_dedup_seconds' => max( 0, (int) ( $values['click_dedup_seconds'] ?? $defaults['click_dedup_seconds'] ) ),
			'view_dedup_seconds'  => max( 0, (int) ( $values['view_dedup_seconds'] ?? $defaults['view_dedup_seconds'] ) ),
		);
	}
}
