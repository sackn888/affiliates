<?php

declare(strict_types=1);

namespace RLT\Frontend;

use RLT\Data\LinkRepository;
use RLT\Settings;
use RLT\Support\ClickAttributeDecorator;
use RLT\Support\ContentRewriter;
use RLT\Support\DestinationHost;

/**
 * Swaps tracked affiliate URLs for their short `/go/{code}` links inside the
 * *rendered* content of a post, without touching what is stored in the
 * database, and adds the `data-ga4-*` click-tracking attributes from
 * docs/AFFILIATE_CLICK_SPEC.md to every short-URL anchor.
 *
 * The URL swap exists for links that only appear as a shortcode attribute
 * (a "blog card" that fetches its target URL server-side to build a
 * preview). PostSync deliberately never rewrites post_content for those:
 * doing so would make the card preview the /go/ redirect instead of the
 * real page, and every card render would itself be recorded as a click.
 * Rewriting the anchor the shortcode expands into, after do_shortcode() has
 * already run, avoids both problems while still making the link trackable.
 *
 * The data-ga4-* attributes are added in the same pass, at output time
 * rather than when the short URL is generated (spec §3.3 explicitly allows
 * this), so they land both on short URLs already sitting in stored content
 * and on the ones this class has just rewritten above.
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
	private ClickAttributeDecorator $decorator;

	/**
	 * Per-post map, built once and reused for the rest of the request: the_content
	 * can fire more than once per request (e.g. a theme rendering an excerpt and
	 * the full content), and rebuilding it from the database on every call would
	 * be wasted work.
	 *
	 * @var array<int, array{rewrite: array<string, string>, attrs: array<string, array{code: string, domain: string, label: string, target_url: string}>}>
	 */
	private array $mapsByPost = array();

	public function __construct(
		?LinkRepository $links = null,
		?ContentRewriter $rewriter = null,
		?ClickAttributeDecorator $decorator = null
	) {
		$this->links     = $links ?? new LinkRepository();
		$this->rewriter  = $rewriter ?? new ContentRewriter();
		$this->decorator = $decorator ?? new ClickAttributeDecorator();
	}

	public function register(): void {
		add_filter( 'the_content', array( $this, 'filter' ), self::PRIORITY );
	}

	public function filter( string $content ): string {
		$postId = get_the_ID();

		if ( ! is_int( $postId ) || $postId <= 0 ) {
			return $content;
		}

		$maps = $this->mapFor( $postId );

		if ( array() === $maps['rewrite'] && array() === $maps['attrs'] ) {
			return $content;
		}

		$content = $this->rewriter->rewrite( $content, $maps['rewrite'] );
		$content = $this->decorator->decorate( $content, $maps['attrs'] );

		return $content;
	}

	/**
	 * @return array{rewrite: array<string, string>, attrs: array<string, array{code: string, domain: string, label: string, target_url: string}>}
	 */
	private function mapFor( int $postId ): array {
		if ( isset( $this->mapsByPost[ $postId ] ) ) {
			return $this->mapsByPost[ $postId ];
		}

		$rewrite = array();
		$attrs   = array();

		// A short URL embedded in stored content may have been built with an
		// older prefix (Settings::allPrefixes() -- see its docblock): a site
		// that changed its prefix still has to decorate posts holding short
		// URLs from before the change.
		$prefixes = Settings::allPrefixes();

		foreach ( $this->links->findByPost( $postId, true ) as $link ) {
			$rewrite[ $link['target_url'] ] = Settings::shortUrl( $link['code'] );

			$data = array(
				'code'       => $link['code'],
				'domain'     => DestinationHost::resolve( $link['target_url'] ),
				'label'      => $link['label'],
				'target_url' => $link['target_url'],
			);

			foreach ( $prefixes as $prefix ) {
				$attrs[ Settings::shortUrlFor( $prefix, $link['code'] ) ] = $data;
			}
		}

		$map = array(
			'rewrite' => $rewrite,
			'attrs'   => $attrs,
		);

		$this->mapsByPost[ $postId ] = $map;

		return $map;
	}
}
