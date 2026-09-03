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
	 * Rewrite prefixes that must never be accepted, because they would shadow
	 * a core WordPress route once registered as `^{prefix}/([a-z0-9]{4,16})/?$`
	 * with `top` priority. This does not attempt to detect a collision with an
	 * arbitrary existing page slug (e.g. a page literally titled "about-us")
	 * — that is out of scope; it only guards against WordPress's own routes.
	 */
	private const RESERVED_PREFIXES = array(
		'wp-admin',
		'wp-content',
		'wp-includes',
		'wp-json',
		'wp-login',
		'feed',
		'rss',
		'rss2',
		'atom',
		'rdf',
		'comments',
		'embed',
		'trackback',
		'page',
		'author',
		'category',
		'tag',
		'date',
		'search',
		'attachment',
		'robots',
		'sitemap',
		'favicon',
	);

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

			// add_option() is atomic against the options table's unique key on
			// option_name, unlike get-then-update_option(). Two simultaneous
			// first requests can otherwise both see no salt, generate different
			// ones, and both write; the loser's already-hashed visitor record
			// becomes permanently unreproducible once its salt is discarded.
			// If add_option() fails, another request won the race, so re-read
			// and use the value that actually made it into the database.
			if ( ! add_option( self::SALT_OPTION, $salt, '', false ) ) {
				$existing = get_option( self::SALT_OPTION, '' );
				if ( is_string( $existing ) && 64 === strlen( $existing ) ) {
					$salt = $existing;
				}
			}
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
		// sanitize_title() strips slashes and regex metacharacters but happily
		// returns things like "feed" or "wp-admin". The prefix is registered as
		// a `top`-priority rewrite rule, so a reserved value would shadow a
		// core WordPress route; fall back to the default instead.
		if ( '' === $prefix || in_array( $prefix, self::RESERVED_PREFIXES, true ) ) {
			$prefix = $defaults['prefix'];
		}

		$hosts = array();
		foreach ( (array) ( $values['hosts'] ?? array() ) as $host ) {
			$host = self::normaliseHost( (string) $host );
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

	/**
	 * Reduce a user-entered host to a bare hostname.
	 *
	 * LinkExtractor::isTrackable() compares stored hosts against
	 * parse_url($url, PHP_URL_HOST), which always yields a bare hostname with
	 * no scheme, trailing slash, or port. Storing anything else (a pasted full
	 * URL, a scheme-relative "//host/path", a trailing slash, a port) would
	 * make that comparison never match, so the host would silently stop being
	 * tracked. Prepending a scheme when one is missing lets parse_url() do the
	 * real work; if parsing still yields nothing, fall back to the trimmed,
	 * lowercased input so an unparseable-but-plausible entry is not lost.
	 */
	private static function normaliseHost( string $host ): string {
		$host = strtolower( trim( $host ) );

		if ( '' === $host ) {
			return '';
		}

		$candidate = $host;
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#', $candidate ) ) {
			// parse_url() cannot find a host in a schemeless string; a
			// scheme-relative "//host/path" needs only "https:" prepended,
			// anything else needs a full "https://" prefix.
			$candidate = str_starts_with( $candidate, '//' ) ? 'https:' . $candidate : 'https://' . $candidate;
		}

		$parsed = parse_url( $candidate, PHP_URL_HOST );
		$host   = is_string( $parsed ) && '' !== $parsed ? $parsed : $host;

		// A hostname may only contain letters, digits, dots and hyphens.
		// Anything else means the entry was not really a hostname at all
		// (e.g. stray text or an unparseable URL); discard it rather than
		// store a value that can never match.
		if ( '' === $host || ! preg_match( '/^[a-z0-9.-]+$/', $host ) ) {
			return '';
		}

		return $host;
	}
}
