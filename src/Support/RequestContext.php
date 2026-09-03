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

		return (string) apply_filters( 'rlt_client_ip', $ip );
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

		return substr( $referer, 0, self::MAX_REFERER_LENGTH );
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
