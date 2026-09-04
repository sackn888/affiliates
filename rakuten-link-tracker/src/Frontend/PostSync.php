<?php

declare(strict_types=1);

namespace RLT\Frontend;

use RLT\Data\LinkRepository;
use RLT\Settings;
use RLT\Support\ContentRewriter;
use RLT\Support\LinkExtractor;

/**
 * Detects affiliate links when a post is saved, issues short codes for them and
 * rewrites the stored content.
 */
final class PostSync {

	public const META_ORIGINAL = '_rlt_original_content';

	private LinkRepository $links;
	private ContentRewriter $rewriter;

	/**
	 * Guards against re-entering the sync for a post already being processed.
	 */
	private bool $running = false;

	public function __construct( ?LinkRepository $links = null ) {
		$this->links    = $links ?? new LinkRepository();
		$this->rewriter = new ContentRewriter();
	}

	public function register(): void {
		add_action( 'save_post', array( $this, 'onSavePost' ), 20, 2 );
	}

	/**
	 * @param \WP_Post $post
	 */
	public function onSavePost( int $postId, $post ): void {
		if ( ! $post instanceof \WP_Post || ! $this->shouldSync( $post ) ) {
			return;
		}

		$this->syncPost( $postId );
	}

	/**
	 * @return string[]
	 */
	public function syncedPostTypes(): array {
		return (array) apply_filters( 'rlt_synced_post_types', array( 'post', 'page' ) );
	}

	/**
	 * Extract, issue codes, rewrite. Returns true when the content changed.
	 */
	public function syncPost( int $postId ): bool {
		if ( $this->running ) {
			return false;
		}

		$post = get_post( $postId );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		$this->running = true;

		try {
			$content   = (string) $post->post_content;
			// shortBases(), not shortBase(): if the prefix was changed after this
			// post was last saved, its content can still hold a short URL built
			// with the old prefix. Only checking the current prefix here would
			// make extract() treat that already-shortened URL as a fresh
			// affiliate link and double-shorten it.
			$extractor      = new LinkExtractor( Settings::hosts(), Settings::shortBases() );
			$found          = $extractor->extract( $content );
			$hrefs          = $extractor->extractHrefs( $content );
			$shortcodeFound = $extractor->extractShortcodeUrls( $content );

			$map     = array();
			$keepIds = array();

			foreach ( $found as $item ) {
				$link = $this->links->findOrCreate( $item['url'], $postId, $item['label'] );

				if ( null === $link ) {
					continue;
				}

				$keepIds[]           = $link['id'];
				$map[ $item['url'] ] = Settings::shortUrl( $link['code'] );
			}

			// Blog-card shortcode URLs are issued a code and kept alive here so
			// archiveOthers() below does not sweep them away, but they are
			// deliberately never added to $map: a blog card fetches its target
			// URL server-side to build its preview, so rewriting the stored
			// shortcode attribute would make the card preview the /go/
			// redirect instead of the hotel page, and every card render would
			// itself be recorded as a click. The stored content therefore
			// stays byte-identical for shortcode-only posts; the rendered
			// anchor is swapped later, at render time, by
			// Frontend\ContentFilter.
			foreach ( $shortcodeFound as $item ) {
				$link = $this->links->findOrCreate( $item['url'], $postId, $item['label'] );

				if ( null === $link ) {
					continue;
				}

				$keepIds[] = $link['id'];
			}

			// An already-active link whose short URL is still sitting in the
			// content must also be kept, even though LinkExtractor::extract()
			// (which only looks for un-shortened affiliate hrefs) will not
			// report it. This is the ordinary case on every re-save of a post
			// that was already converted: the content already holds short
			// URLs, extract() deliberately ignores them, so $found -- and
			// therefore $keepIds -- would otherwise be built as if every link
			// on the post had been removed, and archiveOthers() below would
			// archive all of them on a plain "Update" click.
			//
			// The check is scoped to actual <a href> values (via
			// extractHrefs()), not a raw substring search over the whole
			// document: a short URL sitting in plain text, an HTML comment,
			// an <img src>, or a code sample is not a live link and must not
			// keep an actually-removed link active.
			foreach ( $this->links->findByPost( $postId, true ) as $active ) {
				if ( in_array( $active['id'], $keepIds, true ) ) {
					continue;
				}

				if ( in_array( Settings::shortUrl( $active['code'] ), $hrefs, true ) ) {
					$keepIds[] = $active['id'];
				}
			}

			// Links no longer present in the content are archived, never deleted:
			// their click history must survive and the short URL may be shared elsewhere.
			$this->links->archiveOthers( $postId, $keepIds );

			if ( array() === $map ) {
				return false;
			}

			$rewritten = $this->rewriter->rewrite( $content, $map );

			if ( $rewritten === $content ) {
				return false;
			}

			update_post_meta( $postId, self::META_ORIGINAL, $content );
			$this->writeContent( $postId, $rewritten );

			return true;
		} finally {
			$this->running = false;
		}
	}

	/**
	 * Put the original affiliate URLs back into the post content.
	 *
	 * The map comes from the links table rather than the post meta backup: the
	 * meta only holds the most recent save, so it is unreliable for posts that
	 * have been saved more than once.
	 */
	public function restorePost( int $postId ): bool {
		$post = get_post( $postId );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		// A key is built for every prefix the site has ever used (current plus
		// past, see Settings::allPrefixes()), not just the current one:
		// content published while an older prefix was active still holds a
		// short URL built with that prefix, and it must still be restorable
		// after the prefix changes. Mirrors LinkRepository::restoreMap().
		$prefixes = Settings::allPrefixes();

		$map = array();
		foreach ( $this->links->findByPost( $postId, false ) as $link ) {
			foreach ( $prefixes as $prefix ) {
				$map[ Settings::shortUrlFor( $prefix, $link['code'] ) ] = $link['target_url'];
			}
		}

		if ( array() === $map ) {
			return false;
		}

		$content  = (string) $post->post_content;
		$restored = $this->rewriter->restore( $content, $map );

		if ( $restored === $content ) {
			return false;
		}

		$this->writeContent( $postId, $restored );
		delete_post_meta( $postId, self::META_ORIGINAL );

		return true;
	}

	private function shouldSync( \WP_Post $post ): bool {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return false;
		}

		if ( in_array( $post->post_status, array( 'auto-draft', 'trash', 'inherit' ), true ) ) {
			return false;
		}

		return in_array( $post->post_type, $this->syncedPostTypes(), true );
	}

	/**
	 * Write post_content directly.
	 *
	 * wp_update_post() would re-fire save_post (recursion) and pile up revisions.
	 * A direct UPDATE plus a cache flush avoids both.
	 */
	private function writeContent( int $postId, string $content ): void {
		global $wpdb;

		// post_modified / post_modified_gmt must be bumped here even though
		// wp_update_post() is deliberately avoided: a page cache or CDN keyed
		// on the modified time would otherwise keep serving the pre-rewrite
		// content, so the links visitors actually click stay the untracked
		// originals until something else touches the post.
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_content'      => $content,
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', true ),
			),
			array( 'ID' => $postId ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		clean_post_cache( $postId );
	}
}
