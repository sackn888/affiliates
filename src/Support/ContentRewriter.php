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

		return (string) preg_replace_callback(
			'/(<a\b[^>]*?\bhref\s*=\s*)("([^"]*)"|\'([^\']*)\')/i',
			static function ( array $m ) use ( $map ): string {
				$quote = str_starts_with( $m[2], '"' ) ? '"' : "'";
				$value = '"' === $quote ? $m[3] : $m[4];

				// WordPress may store & as &amp;. Compare on the decoded form.
				$decoded = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

				if ( ! isset( $map[ $decoded ] ) ) {
					return $m[0];
				}

				return $m[1] . $quote . $map[ $decoded ] . $quote;
			},
			$html
		);
	}
}
