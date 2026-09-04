<?php

declare(strict_types=1);

namespace RLT\Frontend;

use RLT\Data\LinkRepository;
use RLT\Settings;
use RLT\Support\ContentRewriter;

/**
 * Swaps tracked affiliate URLs for their short `/go/{code}` links inside the
 * *rendered* content of a post, without touching what is stored in the
 * database.
 *
 * This exists for links that only appear as a shortcode attribute (a "blog
 * card" that fetches its target URL server-side to build a preview).
 * PostSync deliberately never rewrites post_content for those: doing so
 * would make the card preview the /go/ redirect instead of the real page,
 * and every card render would itself be recorded as a click. Rewriting the
 * anchor the shortcode expands into, after do_shortcode() has already run,
 * avoids both problems while still making the link trackable.
 */
final class ContentFilter {

	/**
	 * do_shortcode() runs on the_content at priority 11 (core's default),
	 * so this must run afterwards to see the shortcode's expanded markup
	 * rather than the raw [tag ...] text.
	 */
	private const PRIORITY = 20;

	private LinkRepository $links;
	private ContentRewriter $rewriter;

	/**
	 * Per-post rewrite map, built once and reused for the rest of the
	 * request: the_content can fire more than once per request (e.g. a
	 * theme rendering an excerpt and the full content), and rebuilding the
	 * map from the database on every call would be wasted work.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $mapsByPost = array();

	public function __construct( ?LinkRepository $links = null, ?ContentRewriter $rewriter = null ) {
		$this->links    = $links ?? new LinkRepository();
		$this->rewriter = $rewriter ?? new ContentRewriter();
	}

	public function register(): void {
		add_filter( 'the_content', array( $this, 'filter' ), self::PRIORITY );
	}

	public function filter( string $content ): string {
		$postId = get_the_ID();

		if ( ! is_int( $postId ) || $postId <= 0 ) {
			return $content;
		}

		$map = $this->mapFor( $postId );

		if ( array() === $map ) {
			return $content;
		}

		return $this->rewriter->rewrite( $content, $map );
	}

	/**
	 * @return array<string, string> target_url => short URL
	 */
	private function mapFor( int $postId ): array {
		if ( isset( $this->mapsByPost[ $postId ] ) ) {
			return $this->mapsByPost[ $postId ];
		}

		$map = array();

		foreach ( $this->links->findByPost( $postId, true ) as $link ) {
			$map[ $link['target_url'] ] = Settings::shortUrl( $link['code'] );
		}

		$this->mapsByPost[ $postId ] = $map;

		return $map;
	}
}
