<?php

namespace RLT\Tests\Integration\Frontend;

use RLT\Frontend\PostSync;
use RLT\Settings;
use WP_UnitTestCase;

final class ContentFilterTest extends WP_UnitTestCase {

	private const AFFILIATE = 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=x';

	protected function setUp(): void {
		parent::setUp();

		// A minimal stand-in for a real blog-card shortcode: it expands to an
		// anchor pointing at whatever "url" attribute it was given, which is
		// exactly the shape ContentFilter needs to exercise the real
		// do_shortcode() -> the_content (priority 20) ordering.
		add_shortcode(
			'rlt_test_blogcard',
			static function ( array $atts ): string {
				$url = isset( $atts['url'] ) ? (string) $atts['url'] : '';

				return '<a href="' . esc_url( $url ) . '">card</a>';
			}
		);
	}

	protected function tearDown(): void {
		remove_shortcode( 'rlt_test_blogcard' );
		parent::tearDown();
	}

	private function createPostWithShortcode( string $content ): int {
		// The plugin's own already-registered PostSync (via Plugin::boot())
		// would otherwise try to sync this post on save; use a
		// locally-constructed, unregistered instance instead so post_content
		// is left exactly as given -- mirrors PostSyncTest's approach.
		return self::factory()->post->create(
			array(
				'post_content' => $content,
				'post_status'  => 'publish',
			)
		);
	}

	/**
	 * Runs the_content the same way a theme does: sets up the post global so
	 * get_the_ID() resolves, then applies the real filter chain (including
	 * do_shortcode() at priority 11 and RLT\Frontend\ContentFilter at 20,
	 * both registered once for the whole run via Plugin::boot()).
	 */
	private function renderTheContent( int $postId ): string {
		global $post;

		$post = get_post( $postId ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		$rendered = apply_filters( 'the_content', get_the_content( null, false, $post ) );

		wp_reset_postdata();

		return $rendered;
	}

	public function test_a_shortcode_link_is_tracked_at_render_time(): void {
		$postId = $this->createPostWithShortcode( '[rlt_test_blogcard url="' . self::AFFILIATE . '"]' );

		// Issue the code the way a real save would: run the sync directly
		// (not through save_post, so this stays independent of PostSync's
		// own hook wiring) against the unmodified content already saved.
		( new PostSync() )->syncPost( $postId );

		$html = $this->renderTheContent( $postId );

		$this->assertStringContainsString( Settings::shortBase(), $html );
		$this->assertStringNotContainsString( self::AFFILIATE, $html );
	}

	public function test_a_post_without_links_is_returned_unchanged(): void {
		$postId = $this->createPostWithShortcode( '<p>ただの記事です。</p>' );

		$html = $this->renderTheContent( $postId );

		$this->assertStringContainsString( 'ただの記事です', $html );
	}

	public function test_the_filter_is_idempotent(): void {
		$postId = $this->createPostWithShortcode( '[rlt_test_blogcard url="' . self::AFFILIATE . '"]' );
		( new PostSync() )->syncPost( $postId );

		$first  = $this->renderTheContent( $postId );
		$second = $this->renderTheContent( $postId );

		$this->assertSame( $first, $second );
	}

	public function test_img_src_on_the_affiliate_host_is_untouched(): void {
		// The same URL sits both as the shortcode's tracked target and as a
		// plain <img src> elsewhere in the content, so the rewrite map
		// genuinely contains it -- this is the case that actually exercises
		// ContentRewriter's anchor-only scoping, not just "an unrelated URL
		// was never in the map anyway".
		$postId = $this->createPostWithShortcode(
			'[rlt_test_blogcard url="' . self::AFFILIATE . '"]<img src="' . self::AFFILIATE . '" width="1" height="1">'
		);
		( new PostSync() )->syncPost( $postId );

		$html = $this->renderTheContent( $postId );

		$this->assertStringContainsString( 'src="' . self::AFFILIATE . '"', $html );
		$this->assertStringContainsString( Settings::shortBase(), $html );
	}
}
