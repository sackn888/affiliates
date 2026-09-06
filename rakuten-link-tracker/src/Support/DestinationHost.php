<?php

declare(strict_types=1);

namespace RLT\Support;

/**
 * Works out the "real" destination host of a stored affiliate URL, for use
 * as the `data-ga4-domain` attribute (see docs/AFFILIATE_CLICK_SPEC.md §3.1).
 *
 * A Rakuten affiliate URL always points at Rakuten's own click-tracking
 * host, e.g.:
 *
 *   https://hb.afl.rakuten.co.jp/hgc/.../_RTLink137659?pc=https%3A%2F%2Ftravel.rakuten.co.jp%2FHOTEL%2F9611%2F9611.html
 *
 * Taking the host of that URL directly would yield `hb.afl.rakuten.co.jp`
 * for every single tracked link, which is useless for analytics: every row
 * in the report would be identical, and the spec's own example
 * (`data-ga4-domain="travel.rakuten.co.jp"`, §3.1) would never be produced.
 * The real destination is instead URL-encoded inside the `pc` query
 * parameter, so it must be decoded and parsed separately.
 *
 * Pure: no WordPress dependency.
 */
final class DestinationHost {

	/**
	 * @param string $targetUrl The affiliate URL as stored in target_url.
	 */
	public static function resolve( string $targetUrl ): string {
		$ownHost = self::hostOf( $targetUrl );

		$query = (string) parse_url( $targetUrl, PHP_URL_QUERY );

		if ( '' === $query ) {
			return $ownHost;
		}

		// parse_str() url-decodes values for us, so $params['pc'] is already
		// the plain destination URL (e.g. "https://travel.rakuten.co.jp/...").
		parse_str( $query, $params );

		$pc = isset( $params['pc'] ) && is_string( $params['pc'] ) ? trim( $params['pc'] ) : '';

		if ( '' === $pc ) {
			return $ownHost;
		}

		// Only trust `pc` when it is itself an absolute http(s) URL. A
		// missing, blank, relative, or otherwise malformed value must fall
		// back to the affiliate host rather than error out -- this runs on
		// every content render, so it can never throw.
		$scheme = strtolower( (string) parse_url( $pc, PHP_URL_SCHEME ) );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return $ownHost;
		}

		$pcHost = self::hostOf( $pc );

		return '' !== $pcHost ? $pcHost : $ownHost;
	}

	private static function hostOf( string $url ): string {
		return strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
	}
}
