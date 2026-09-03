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
			$extractor = new LinkExtractor( Settings::hosts(), Settings::shortBase() );
			$found     = $extractor->extract( $content );

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

			// An already-active link whose short URL is still sitting in the
			// content must also be kept, even though LinkExtractor (which only
			// looks for un-shortened affiliate hrefs) will not report it.
			//
			// This matters for more than a plain resave: WordPress fires
			// save_post twice for a post created directly with post_status =
			// 'publish' -- once from wp_insert_post() itself and again from
			// wp_publish_post(), which runs on the new-to-publish transition.
			// The first firing here already rewrites the content and clears
			// the post cache, so by the second firing get_post() returns the
			// already-shortened content. Without this check that second call
			// would see no affiliate hrefs, compute an empty keep list, and
			// have archiveOthers() immediately archive the link this same
			// save just created.
			foreach ( $this->links->findByPost( $postId, true ) as $active ) {
				if ( in_array( $active['id'], $keepIds, true ) ) {
					continue;
				}

				if ( str_contains( $content, Settings::shortUrl( $active['code'] ) ) ) {
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

		$map = array();
		foreach ( $this->links->findByPost( $postId, false ) as $link ) {
			$map[ Settings::shortUrl( $link['code'] ) ] = $link['target_url'];
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

		$wpdb->update(
			$wpdb->posts,
			array( 'post_content' => $content ),
			array( 'ID' => $postId ),
			array( '%s' ),
			array( '%d' )
		);

		clean_post_cache( $postId );
	}
}
