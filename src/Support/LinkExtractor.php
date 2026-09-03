<?php

declare(strict_types=1);

namespace RLT\Support;

/**
 * Finds affiliate URLs inside post content.
 *
 * Only anchor `href` attributes are considered. `<img src>` is deliberately
 * out of scope: Rakuten banner markup embeds a 1x1 impression pixel on the same
 * host, and rewriting it would break Rakuten's own tracking.
 *
 * Pure: no WordPress dependency.
 */
final class LinkExtractor {

	private const MAX_LABEL_BYTES = 255;

	/** @var string[] */
	private array $hosts;

	private string $shortBase;

	/**
	 * @param string[] $hosts     Hostnames to treat as affiliate links.
	 * @param string   $shortBase Absolute base of already-shortened URLs, e.g. https://example.com/go/
	 */
	public function __construct( array $hosts, string $shortBase ) {
		$this->hosts     = array_values( array_filter( array_map( 'strtolower', array_map( 'trim', $hosts ) ) ) );
		$this->shortBase = $shortBase;
	}

	/**
	 * @return array<int, array{url: string, label: string}> Unique, in first-appearance order.
	 */
	public function extract( string $html ): array {
		if ( '' === trim( $html ) ) {
			return array();
		}

		// Capture the whole anchor so the inner markup is available for labelling.
		$pattern = '/<a\b([^>]*?)>(.*?)<\/a\s*>/is';

		$matchCount = preg_match_all( $pattern, $html, $matches, PREG_SET_ORDER );

		// preg_match_all() returns false (PCRE backtrack/recursion limit hit,
		// or another engine error) as well as 0 (genuinely no matches). Those
		// are not the same situation: false means extraction was skipped, not
		// that the content has no affiliate links, so it must be logged
		// rather than silently treated like a normal "nothing found" result.
		if ( false === $matchCount ) {
			error_log( '[rakuten-link-tracker] LinkExtractor: preg_match_all() failed; link extraction skipped for this content.' );

			return array();
		}

		if ( 0 === $matchCount ) {
			return array();
		}

		$found = array();

		foreach ( $matches as $match ) {
			$url = $this->hrefFrom( $match[1] );

			if ( null === $url || ! $this->isTrackable( $url ) ) {
				continue;
			}

			if ( isset( $found[ $url ] ) ) {
				continue; // Keep the first label we saw.
			}

			$found[ $url ] = array(
				'url'   => $url,
				'label' => $this->labelFrom( $match[2], $url ),
			);
		}

		return array_values( $found );
	}

	/**
	 * Pull the href value out of an anchor's attribute string.
	 */
	private function hrefFrom( string $attributes ): ?string {
		if ( ! preg_match( '/\bhref\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $attributes, $m ) ) {
			return null;
		}

		$raw = '' !== $m[2] ? $m[2] : ( $m[3] ?? '' );
		$raw = html_entity_decode( trim( $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return '' === $raw ? null : $raw;
	}

	/**
	 * True when the URL points at a configured affiliate host and has not already
	 * been shortened.
	 */
	private function isTrackable( string $url ): bool {
		if ( str_starts_with( $url, $this->shortBase ) ) {
			return false;
		}

		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );

		if ( '' === $host ) {
			return false;
		}

		// Exact host match only. A substring match would accept
		// hb.afl.rakuten.co.jp.evil.example as legitimate.
		return in_array( $host, $this->hosts, true );
	}

	/**
	 * Best available human-readable name for the link.
	 *
	 * Anchor text, then the inner image's alt, then the URL itself.
	 */
	private function labelFrom( string $inner, string $url ): string {
		$text = $this->normalise( strip_tags( $inner ) );

		if ( '' === $text && preg_match( '/<img\b[^>]*\balt\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $inner, $m ) ) {
			$alt  = '' !== $m[2] ? $m[2] : ( $m[3] ?? '' );
			$text = $this->normalise( $alt );
		}

		if ( '' === $text ) {
			$text = (string) parse_url( $url, PHP_URL_HOST ) . (string) parse_url( $url, PHP_URL_PATH );
		}

		return $this->truncate( $text );
	}

	private function normalise( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );

		return trim( $text );
	}

	/**
	 * Keep the label inside the VARCHAR(255) column without splitting a
	 * multibyte character in half.
	 */
	private function truncate( string $text ): string {
		if ( strlen( $text ) <= self::MAX_LABEL_BYTES ) {
			return $text;
		}

		$cut = substr( $text, 0, self::MAX_LABEL_BYTES );

		// A hard byte cut can land inside a multibyte sequence, leaving a lead
		// byte with too few (or zero) continuation bytes trailing it. That
		// trailing fragment is not valid UTF-8 on its own, so the whole
		// incomplete sequence — lead byte and whatever continuation bytes it
		// kept — must be dropped, not just the continuation bytes.
		return (string) preg_replace(
			'/(?:[\xC0-\xDF]|[\xE0-\xEF][\x80-\xBF]?|[\xF0-\xF7][\x80-\xBF]{0,2})$/',
			'',
			$cut
		);
	}
}
