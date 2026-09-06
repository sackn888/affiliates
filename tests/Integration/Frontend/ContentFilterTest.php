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

	public function test_a_shortcode_produced_link_is_decorated_with_ga4_attributes(): void {
		$postId = $this->createPostWithShortcode( '[rlt_test_blogcard url="' . self::AFFILIATE . '"]' );
		( new PostSync() )->syncPost( $postId );

		$html = $this->renderTheContent( $postId );

		$this->assertStringContainsString( 'data-ga4-click="affiliate"', $html );
		$this->assertMatchesRegularExpression( '/data-ga4-code="[a-z0-9]+"/', $html );
		$this->assertStringContainsString( 'data-ga4-domain="hb.afl.rakuten.co.jp"', $html );
	}

	public function test_a_link_stored_directly_in_post_content_is_decorated(): void {
		// Simulate what PostSync itself already does on a normal save: the
		// affiliate URL is rewritten to a short URL and persisted, with no
		// shortcode involved at all.
		$postId = self::factory()->post->create(
			array(
				'post_content' => '<p><a href="' . self::AFFILIATE . '">ホテル</a></p>',
				'post_status'  => 'publish',
			)
		);
		( new PostSync() )->syncPost( $postId );

		$html = $this->renderTheContent( $postId );

		$this->assertStringContainsString( 'data-ga4-click="affiliate"', $html );
		$this->assertStringNotContainsString( self::AFFILIATE, $html );
	}

	public function test_ga4_decoration_is_idempotent_across_repeated_renders(): void {
		$postId = $this->createPostWithShortcode( '[rlt_test_blogcard url="' . self::AFFILIATE . '"]' );
		( new PostSync() )->syncPost( $postId );

		$first  = $this->renderTheContent( $postId );
		$second = $this->renderTheContent( $postId );

		$this->assertSame( $first, $second );
		$this->assertSame( 1, substr_count( $first, 'data-ga4-click="affiliate"' ) );
	}

	public function test_two_links_with_identical_anchor_text_get_the_same_ga4_label_but_distinct_codes(): void {
		// The label is now derived from the anchor's own rendered text
		// (docs/AFFILIATE_CLICK_SPEC.md §3.1), so two buttons that both read
		// 「楽天トラベルで見る」 legitimately get the same label -- the hotel id
		// is no longer appended once real anchor text is available.
		// `data-ga4-code` remains the unique key per §3.2, so the two links
		// are still distinguishable in a GA4 report.
		$urlA = 'https://hb.afl.rakuten.co.jp/hgc/aaa/_RTLink1?pc=' . rawurlencode( 'https://travel.rakuten.co.jp/HOTEL/1111/1111.html' );
		$urlB = 'https://hb.afl.rakuten.co.jp/hgc/bbb/_RTLink2?pc=' . rawurlencode( 'https://travel.rakuten.co.jp/HOTEL/2222/2222.html' );

		$postId = self::factory()->post->create(
			array(
				'post_content' => '<p><a href="' . $urlA . '">楽天トラベルで見る</a></p>'
					. '<p><a href="' . $urlB . '">楽天トラベルで見る</a></p>',
				'post_status'  => 'publish',
			)
		);
		( new PostSync() )->syncPost( $postId );

		$html = $this->renderTheContent( $postId );

		preg_match_all( '/data-ga4-label="([^"]*)"/', $html, $labels );
		$this->assertCount( 2, $labels[1] );
		$this->assertSame( '楽天トラベルで見る', $labels[1][0] );
		$this->assertSame( '楽天トラベルで見る', $labels[1][1] );
		$this->assertStringNotContainsString( '(1111)', $html );
		$this->assertStringNotContainsString( '(2222)', $html );

		preg_match_all( '/data-ga4-code="([^"]*)"/', $html, $codes );
		$this->assertCount( 2, $codes[1] );
		$this->assertNotSame( $codes[1][0], $codes[1][1] );
	}

	public function test_a_link_under_an_older_prefix_is_still_decorated(): void {
		$postId = self::factory()->post->create(
			array(
				'post_content' => '<p><a href="' . self::AFFILIATE . '">ホテル</a></p>',
				'post_status'  => 'publish',
			)
		);
		( new PostSync() )->syncPost( $postId );

		$post   = get_post( $postId );
		$oldUrl = Settings::shortBase();

		// Switch the prefix, mirroring an admin changing the setting after
		// this post was already published: the post's stored content still
		// holds a short URL built with the old prefix.
		Settings::update( array( 'prefix' => 'r' ) );

		$this->assertNotSame( $oldUrl, Settings::shortBase() );
		$this->assertStringContainsString( '/go/', (string) $post->post_content );

		$html = $this->renderTheContent( $postId );

		$this->assertStringContainsString( 'data-ga4-click="affiliate"', $html );
	}
}
