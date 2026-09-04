<?php

declare(strict_types=1);

namespace RLT\Support;

/**
 * Classifies a user agent as bot or human.
 *
 * Callers record the verdict rather than dropping the row, so the rule set can
 * be revised later without losing past data.
 *
 * Pure: no WordPress dependency.
 */
final class BotFilter {

	private const PATTERNS = array(
		'bot',
		'crawl',
		'spider',
		'slurp',
		'facebookexternalhit',
		'headlesschrome',
		'phantomjs',
		'preview',
		'fetcher',
		'monitor',
		'scraper',
		'curl/',
		'wget',
		'python-requests',
		'go-http-client',
		'java/',
		'okhttp',
		'axios',
		'libwww',
		'httpclient',
		'feedly',
		'pingdom',
		'uptimerobot',
		'lighthouse',
		'gtmetrix',
		// WordPress's own HTTP API (WP_Http) sends "WordPress/<version>;
		// <url>" as its default user agent. Any WordPress-driven fetch of a
		// /go/ URL -- a link checker, a card-preview fetcher, pingback
		// verification, another WP site -- would otherwise be recorded as a
		// genuine human click. No real browser's user agent contains this
		// substring.
		'wordpress/',
	);

	public static function isBot( string $userAgent ): bool {
		$userAgent = trim( $userAgent );

		// No user agent at all is never a real browser.
		if ( '' === $userAgent ) {
			return true;
		}

		$haystack = strtolower( $userAgent );

		foreach ( self::PATTERNS as $needle ) {
			if ( str_contains( $haystack, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
