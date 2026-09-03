<?php

declare(strict_types=1);

namespace RLT\Support;

/**
 * Swaps URLs inside anchor `href` attributes.
 *
 * A plain str_replace over the whole document would also hit `<img src>`, which
 * must stay untouched, so every replacement goes through an anchor-scoped
 * regex instead.
 *
 * Pure: no WordPress dependency.
 */
final class ContentRewriter {

	/**
	 * @param array<string, string> $map Original URL => short URL.
	 */
	public function rewrite( string $html, array $map ): string {
		return $this->swap( $html, $map );
	}

	/**
	 * @param array<string, string> $map Short URL => original URL.
	 */
	public function restore( string $html, array $map ): string {
		return $this->swap( $html, $map );
	}

	/**
	 * @param array<string, string> $map
	 */
	private function swap( string $html, array $map ): string {
		if ( array() === $map || '' === $html ) {
			return $html;
		}

		$result = preg_replace_callback(
			'/(<a\b[^>]*?\bhref\s*=\s*)("([^"]*)"|\'([^\']*)\')/i',
			static function ( array $m ) use ( $map ): string {
				$quote = str_starts_with( $m[2], '"' ) ? '"' : "'";
				$value = '"' === $quote ? $m[3] : $m[4];

				// WordPress may store & as &amp;, and a value may carry
				// incidental surrounding whitespace (a common paste
				// artifact). Trim before decoding so the lookup key here
				// matches exactly how LinkExtractor::hrefFrom() built the
				// map's keys; only the lookup is trimmed, the rebuilt
				// attribute below still uses the untouched $value.
				$decoded = html_entity_decode( trim( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

				if ( ! isset( $map[ $decoded ] ) ) {
					return $m[0];
				}

				// The replacement can itself contain characters such as &
				// that must be re-encoded for the attribute to stay valid
				// HTML: restore() writes back decoded Rakuten URLs, which
				// routinely carry several query parameters joined by &, and
				// WordPress expects those stored as &amp;. Encoding is a
				// no-op for the short URLs used in the rewrite direction,
				// so this is safe in both directions.
				$replacement = htmlspecialchars( $map[ $decoded ], ENT_QUOTES | ENT_HTML5, 'UTF-8' );

				return $m[1] . $quote . $replacement . $quote;
			},
			$html
		);

		if ( null === $result ) {
			// preg_replace_callback() returns null when PCRE hits its
			// backtrack/recursion limit (or another engine error). The
			// caller writes this return value straight into post_content,
			// so returning '' here would replace the user's entire
			// published article with an empty string. Returning $html
			// unrewritten only costs some tracking data on this save,
			// which is by far the safer failure mode.
			error_log( '[rakuten-link-tracker] ContentRewriter: preg_replace_callback() failed; content left unrewritten.' );

			return $html;
		}

		return $result;
	}
}
