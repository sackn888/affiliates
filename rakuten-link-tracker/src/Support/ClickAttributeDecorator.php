<?php

declare(strict_types=1);

namespace RLT\Support;

/**
 * Adds the four `data-ga4-*` attributes from docs/AFFILIATE_CLICK_SPEC.md
 * §3.1 to anchors whose `href` is one of this site's short URLs.
 *
 * This only *emits* attributes; per §2 of the spec, sending a GA4 event is
 * deliberately left to a separate tracking tag, so nothing here calls
 * gtag() or knows about GA4 beyond the attribute names themselves.
 *
 * Follows the same anchor-scoped regex approach as ContentRewriter rather
 * than a DOM parser, so Gutenberg block comments are never touched and
 * `<img src>` is never mistaken for a link.
 *
 * Pure: no WordPress dependency.
 */
final class ClickAttributeDecorator {

	/**
	 * GA4 truncates event-parameter values at 100 characters (spec §3.2,
	 * §3.4); data-ga4-code and data-ga4-domain are capped here too as a
	 * defensive limit, even though in practice they are always far shorter.
	 */
	private const MAX_ATTR_CHARS = 100;

	/**
	 * @param array<string, array{code: string, domain: string, label: string}> $shortUrls
	 *        Short URL => the raw (untruncated, unescaped) values to render.
	 */
	public function decorate( string $html, array $shortUrls ): string {
		if ( array() === $shortUrls || '' === $html ) {
			return $html;
		}

		$result = preg_replace_callback(
			'/<a\b[^>]*>/i',
			function ( array $m ) use ( $shortUrls ): string {
				return $this->decorateTag( $m[0], $shortUrls );
			},
			$html
		);

		if ( null === $result ) {
			// preg_replace_callback() returns null on a PCRE engine error
			// (e.g. the backtrack/recursion limit). The caller writes this
			// straight into rendered output, so returning '' would blank the
			// whole article; returning $html undecorated only costs this
			// render's click attributes.
			error_log( '[rakuten-link-tracker] ClickAttributeDecorator: preg_replace_callback() failed; content left undecorated.' );

			return $html;
		}

		return $result;
	}

	/**
	 * @param array<string, array{code: string, domain: string, label: string}> $shortUrls
	 */
	private function decorateTag( string $tag, array $shortUrls ): string {
		// Idempotent: an anchor already carrying data-ga4-click (e.g. from a
		// previous pass over the same content within one request) is left
		// exactly as-is rather than gaining a duplicate set of attributes.
		if ( preg_match( '/\bdata-ga4-click\s*=/i', $tag ) ) {
			return $tag;
		}

		$href = $this->hrefFrom( $tag );

		if ( null === $href || ! isset( $shortUrls[ $href ] ) ) {
			return $tag;
		}

		$data = $shortUrls[ $href ];

		$attributes = sprintf(
			' data-ga4-click="affiliate" data-ga4-code="%s" data-ga4-domain="%s" data-ga4-label="%s"',
			$this->attr( $data['code'] ),
			$this->attr( $data['domain'] ),
			$this->attr( $data['label'] )
		);

		// Insert just before the closing ">" (or "/>") so every existing
		// attribute -- href, target, rel, class, whatever -- is left
		// byte-for-byte untouched (spec §3.4, completion criterion 6).
		if ( str_ends_with( $tag, '/>' ) ) {
			return substr( $tag, 0, -2 ) . $attributes . ' />';
		}

		return substr( $tag, 0, -1 ) . $attributes . '>';
	}

	/**
	 * Same href-extraction shape as ContentRewriter/LinkExtractor: decode
	 * entities and trim so this matches however Settings::shortUrl() built
	 * the map keys, regardless of incidental whitespace in the markup.
	 */
	private function hrefFrom( string $tag ): ?string {
		if ( ! preg_match( '/\bhref\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $m ) ) {
			return null;
		}

		$raw = '' !== $m[2] ? $m[2] : ( $m[3] ?? '' );
		$raw = html_entity_decode( trim( $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return '' === $raw ? null : $raw;
	}

	/**
	 * Truncate to MAX_ATTR_CHARS *characters*, not bytes (unlike
	 * LinkExtractor's storage-side truncation, which is byte-based to fit a
	 * VARCHAR column), then escape for safe inclusion in a double-quoted
	 * HTML attribute.
	 *
	 * Uses a PCRE with the `u` modifier rather than mb_substr() so this does
	 * not depend on the mbstring extension being installed.
	 *
	 * htmlspecialchars(ENT_QUOTES) is used instead of WordPress's esc_attr()
	 * because this class is pure (no WordPress dependency, see class
	 * docblock) -- ContentRewriter takes the same approach for the same
	 * reason. For the plain-text values built by ContentFilter (a short
	 * code, a hostname, a link label) the two produce the same escaped
	 * output.
	 */
	private function attr( string $value ): string {
		return htmlspecialchars( $this->truncate( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	private function truncate( string $value ): string {
		if ( '' === $value ) {
			return $value;
		}

		$pattern = '/^.{0,' . self::MAX_ATTR_CHARS . '}/su';

		if ( 1 === preg_match( $pattern, $value, $m ) ) {
			return $m[0];
		}

		// preg_match() with /u returns false when $value is not valid UTF-8.
		// Fall back to a byte-safe cut so a malformed label degrades instead
		// of breaking the render.
		if ( strlen( $value ) <= self::MAX_ATTR_CHARS ) {
			return $value;
		}

		$cut = substr( $value, 0, self::MAX_ATTR_CHARS );

		return (string) preg_replace(
			'/(?:[\xC0-\xDF]|[\xE0-\xEF][\x80-\xBF]?|[\xF0-\xF7][\x80-\xBF]{0,2})$/',
			'',
			$cut
		);
	}
}
