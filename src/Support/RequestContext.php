<?php

declare(strict_types=1);

namespace RLT\Support;

use RLT\Settings;

/**
 * The single place that touches $_SERVER.
 *
 * Callers get a visitor hash, a device class and a bot verdict, and never see
 * the IP address at all.
 */
final class RequestContext {

	private const MAX_REFERER_LENGTH = 255;

	private static ?string $visitorHash = null;
	private static ?int $device         = null;
	private static ?bool $isBot         = null;

	/**
	 * Clear the per-request memo. Only useful in tests.
	 */
	public static function reset(): void {
		self::$visitorHash = null;
		self::$device      = null;
		self::$isBot       = null;
	}

	/**
	 * Client IP, straight from REMOTE_ADDR.
	 *
	 * Sites behind Cloudflare or another proxy should override this via the
	 * `rlt_client_ip` filter. Trusting a forwarded header by default would let
	 * anyone spoof their identity and defeat deduplication.
	 */
	public static function ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

		$filtered = apply_filters( 'rlt_client_ip', $ip );

		// A misbehaving third-party filter (proxy detection gone wrong, e.g.
		// returning null/false/an array) must not silently degrade
		// deduplication: casting a non-string to '' would make the IP
		// contribute nothing to the visitor hash, merging every visitor that
		// shares a user-agent. Fall back to the real, unfiltered value instead.
		return is_string( $filtered ) && '' !== $filtered ? $filtered : $ip;
	}

	public static function userAgent(): string {
		return isset( $_SERVER['HTTP_USER_AGENT'] )
			? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] )
			: '';
	}

	public static function referer(): string {
		$referer = isset( $_SERVER['HTTP_REFERER'] )
			? esc_url_raw( (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) )
			: '';

		return self::truncateBytesSafely( $referer );
	}

	/**
	 * Keep the referer inside the utf8mb4 VARCHAR(255) column without
	 * splitting a multibyte character in half.
	 *
	 * esc_url_raw() passes raw \x80-\xff bytes through unescaped, so a
	 * Referer header carrying raw UTF-8 can reach here as multibyte text.
	 * substr() counts bytes, so a naive cut at 255 bytes can land mid
	 * character and leave an invalid UTF-8 tail. Under MySQL strict mode an
	 * invalid sequence fails the whole INSERT, which would silently drop the
	 * click/view event rather than merely store a mangled referer. Same
	 * technique as LinkExtractor::truncate(), kept in sync deliberately.
	 */
	private static function truncateBytesSafely( string $text ): string {
		if ( strlen( $text ) <= self::MAX_REFERER_LENGTH ) {
			return $text;
		}

		$cut = substr( $text, 0, self::MAX_REFERER_LENGTH );

		return (string) preg_replace(
			'/(?:[\xC0-\xDF]|[\xE0-\xEF][\x80-\xBF]?|[\xF0-\xF7][\x80-\xBF]{0,2})$/',
			'',
			$cut
		);
	}

	public static function visitorHash(): string {
		if ( null === self::$visitorHash ) {
			self::$visitorHash = VisitorHash::make( self::ip(), self::userAgent(), Settings::salt() );
		}

		return self::$visitorHash;
	}

	public static function device(): int {
		if ( null === self::$device ) {
			self::$device = DeviceDetector::detect( self::userAgent() );
		}

		return self::$device;
	}

	public static function isBot(): bool {
		if ( null === self::$isBot ) {
			self::$isBot = BotFilter::isBot( self::userAgent() );
		}

		return self::$isBot;
	}
}
