<?php

declare(strict_types=1);

namespace RLT\Support;

/**
 * Derives a distinguishing id (e.g. a Rakuten hotel id) from the destination
 * URL carried in an affiliate URL's `pc` query parameter, for use appended to
 * `data-ga4-label` (see docs/AFFILIATE_CLICK_SPEC.md §3.1).
 *
 * Background: site owners frequently use the same anchor text (e.g.
 * 「楽天トラベルで見る」) on every affiliate link in an article, which makes the
 * anchor-text-derived label useless for telling links apart in a GA4 report.
 * The one thing that *does* differ between such links is the destination
 * URL, and Rakuten travel URLs carry the hotel id as a numeric path segment,
 * e.g. https://travel.rakuten.co.jp/HOTEL/9611/9611.html -> "9611".
 *
 * Reuses DestinationHost::destinationUrl() for the "is `pc` actually a
 * usable absolute http(s) URL" logic, rather than parsing the affiliate URL
 * a second, differently-behaving way.
 *
 * Pure: no WordPress dependency.
 */
final class DestinationId {

	/**
	 * @param string $targetUrl The affiliate URL as stored in target_url.
	 *
	 * @return string|null The first entirely-digit path segment of the
	 *                      destination URL, or null when the destination
	 *                      cannot be resolved or has no such segment.
	 */
	public static function resolve( string $targetUrl ): ?string {
		$destination = DestinationHost::destinationUrl( $targetUrl );

		if ( '' === $destination ) {
			return null;
		}

		$path = (string) parse_url( $destination, PHP_URL_PATH );

		if ( '' === $path ) {
			return null;
		}

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' !== $segment && ctype_digit( $segment ) ) {
				return $segment;
			}
		}

		return null;
	}
}
