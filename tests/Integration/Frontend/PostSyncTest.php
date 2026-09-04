<?php

namespace RLT\Tests\Integration\Frontend;

use RLT\Data\LinkRepository;
use RLT\Frontend\PostSync;
use RLT\Settings;
use WP_UnitTestCase;

final class PostSyncTest extends WP_UnitTestCase {

	private const AFFILIATE = 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=x';

	private PostSync $sync;
	private LinkRepository $links;

	/**
	 * The plugin itself already constructs a PostSync and hooks it to
	 * save_post (via Plugin::boot() on muplugins_loaded in
	 * bootstrap-integration.php) for the whole test run. Registering a
	 * second, locally-constructed instance here would make every save run
	 * syncPost() twice through two separate objects -- which is what used to
	 * make this class look like it exercised a WordPress double-save quirk,
	 * when it was really just running its own logic twice. So this instance
	 * is built (to call syncPost()/restorePost()/syncedPostTypes() directly)
	 * but never registered; the save_post hook path is left to the plugin's
	 * own already-registered instance.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->links = new LinkRepository();
		$this->sync  = new PostSync( $this->links );
	}

	private function createPostWithLink( string $content ): int {
		return self::factory()->post->create(
			array(
				'post_content' => $content,
				'post_status'  => 'publish',
			)
		);
	}

	public function test_saving_a_post_replaces_the_affiliate_url(): void {
		$postId = $this->createPostWithLink( '<a href="' . self::AFFILIATE . '">ホテル</a>' );

		$content = get_post_field( 'post_content', $postId );

		$this->assertStringNotContainsString( self::AFFILIATE, $content );
		$this->assertStringContainsString( Settings::shortBase(), $content );
	}

	public function test_a_link_row_is_created_for_the_post(): void {
		$postId = $this->createPostWithLink( '<a href="' . self::AFFILIATE . '">ホテル</a>' );

		$links = $this->links->findByPost( $postId );

		$this->assertCount( 1, $links );
		$this->assertSame( self::AFFILIATE, $links[0]['target_url'] );
		$this->assertSame( 'ホテル', $links[0]['label'] );
	}

	public function test_the_short_url_in_the_content_matches_the_stored_code(): void {
		$postId = $this->createPostWithLink( '<a href="' . self::AFFILIATE . '">ホテル</a>' );

		$link    = $this->links->findByPost( $postId )[0];
		$content = get_post_field( 'post_content', $postId );

		$this->assertStringContainsString( 'href="' . Settings::shortUrl( $link['code'] ) . '"', $content );
	}

	public function test_original_content_is_backed_up(): void {
		$original = '<a href="' . self::AFFILIATE . '">ホテル</a>';
		$postId   = $this->createPostWithLink( $original );

		$this->assertSame( $original, get_post_meta( $postId, PostSync::META_ORIGINAL, true ) );
	}

	public function test_resaving_keeps_the_same_code(): void {
		$postId = $this->createPostWithLink( '<a href="' . self::AFFILIATE . '">ホテル</a>' );
		$first  = $this->links->findByPost( $postId )[0]['code'];

		wp_update_post(
			array(
				'ID'           => $postId,
				'post_content' => get_post_field( 'post_content', $postId ) . '<p>追記</p>',
			)
		);

		$links = $this->links->findByPost( $postId );

		$this->assertCount( 1, $links );
		$this->assertSame( $first, $links[0]['code'] );
	}

	public function test_saving_is_idempotent_on_already_converted_content(): void {
		$postId  = $this->createPostWithLink( '<a href="' . self::AFFILIATE . '">ホテル</a>' );
		$after1  = get_post_field( 'post_content', $postId );

		wp_update_post( array( 'ID' => $postId ) );
		$after2 = get_post_field( 'post_content', $postId );

		$this->assertSame( $after1, $after2 );
	}

	public function test_img_src_is_left_alone(): void {
		$pixel  = 'https://hb.afl.rakuten.co.jp/hsc/abc123/?me_id=1';
		$postId = $this->createPostWithLink(
			'<a href="' . self::AFFILIATE . '">ホテル</a><img src="' . $pixel . '" width="1" height="1">'
		);

		$content = get_post_field( 'post_content', $postId );

		$this->assertStringContainsString( 'src="' . $pixel . '"', $content );
	}

	public function test_removing_a_link_archives_its_row_but_keeps_it(): void {
		$postId = $this->createPostWithLink( '<a href="' . self::AFFILIATE . '">ホテル</a>' );
		$linkId = $this->links->findByPost( $postId )[0]['id'];

		wp_update_post(
			array(
				'ID'           => $postId,
				'post_content' => '<p>リンクを消しました</p>',
			)
		);

		$this->assertSame( array(), $this->links->findByPost( $postId ) );
		$this->assertSame( 0, $this->links->findById( $linkId )['status'] );
	}

	public function test_revisions_are_skipped(): void {
		$postId = $this->createPostWithLink( '<a href="' . self::AFFILIATE . '">ホテル</a>' );

		$before = count( $this->links->findByPost( $postId, false ) );
		wp_save_post_revision( $postId );
		$after  = count( $this->links->findByPost( $postId, false ) );

		$this->assertSame( $before, $after );
	}

	public function test_auto_draft_is_skipped(): void {
		$postId = self::factory()->post->create(
			array(
				'post_content' => '<a href="' . self::AFFILIATE . '">ホテル</a>',
				'post_status'  => 'auto-draft',
			)
		);

		$this->assertSame( array(), $this->links->findByPost( $postId, false ) );
	}

	public function test_posts_without_affiliate_links_are_untouched(): void {
		$content = '<p>ただの記事です。<a href="https://example.org/">よそ</a></p>';
		$postId  = $this->createPostWithLink( $content );

		$this->assertSame( $content, get_post_field( 'post_content', $postId ) );
		$this->assertSame( '', get_post_meta( $postId, PostSync::META_ORIGINAL, true ) );
	}

	public function test_restore_post_puts_the_original_urls_back(): void {
		$original = '<p><a rel="nofollow" href="' . self::AFFILIATE . '">ホテル</a></p>';
		$postId   = $this->createPostWithLink( $original );

		$this->assertTrue( $this->sync->restorePost( $postId ) );
		$this->assertSame( $original, get_post_field( 'post_content', $postId ) );
	}

	public function test_restore_post_returns_false_when_there_is_nothing_to_restore(): void {
		$postId = $this->createPostWithLink( '<p>リンクなし</p>' );

		$this->assertFalse( $this->sync->restorePost( $postId ) );
	}

	public function test_restore_works_for_a_link_published_under_an_old_prefix(): void {
		$postId = $this->createPostWithLink( '<a href="' . self::AFFILIATE . '">ホテル</a>' );

		// プレフィックスを変更しても、本文に残る旧URLは復元できなければならない。
		\RLT\Settings::update( array( 'prefix' => 'out' ) );

		$this->assertTrue( $this->sync->restorePost( $postId ) );
		$this->assertStringContainsString( self::AFFILIATE, get_post_field( 'post_content', $postId ) );
	}

	public function test_two_posts_sharing_a_url_get_different_codes(): void {
		$a = $this->createPostWithLink( '<a href="' . self::AFFILIATE . '">ホテル</a>' );
		$b = $this->createPostWithLink( '<a href="' . self::AFFILIATE . '">ホテル</a>' );

		$codeA = $this->links->findByPost( $a )[0]['code'];
		$codeB = $this->links->findByPost( $b )[0]['code'];

		$this->assertNotSame( $codeA, $codeB );
	}

	public function test_resaving_an_unchanged_post_does_not_archive_its_links(): void {
		$postId = $this->createPostWithLink( '<a href="' . self::AFFILIATE . '">ホテル</a>' );

		$this->assertCount( 1, $this->links->findByPost( $postId ) );

		// 編集者が「更新」をもう一度押しただけ。ここでリンクがアーカイブされると
		// PVビーコンが止まり、以降その記事の計測が無言で死ぬ。
		wp_update_post( array( 'ID' => $postId ) );

		$this->assertCount( 1, $this->links->findByPost( $postId ), 'Re-saving archived the post links.' );
	}

	public function test_repeated_saves_keep_the_same_code_and_content(): void {
		$postId = $this->createPostWithLink( '<a href="' . self::AFFILIATE . '">ホテル</a>' );

		$code    = $this->links->findByPost( $postId )[0]['code'];
		$content = get_post_field( 'post_content', $postId );

		for ( $i = 0; $i < 3; $i++ ) {
			wp_update_post( array( 'ID' => $postId ) );
		}

		$links = $this->links->findByPost( $postId );

		$this->assertCount( 1, $links );
		$this->assertSame( $code, $links[0]['code'] );
		$this->assertSame( $content, get_post_field( 'post_content', $postId ) );
	}

	public function test_a_short_url_outside_an_anchor_does_not_keep_a_removed_link_alive(): void {
		$postId = $this->createPostWithLink( '<a href="' . self::AFFILIATE . '">ホテル</a>' );
		$linkId = $this->links->findByPost( $postId )[0]['id'];
		$short  = \RLT\Settings::shortUrl( $this->links->findById( $linkId )['code'] );

		// アンカーは消したが、短縮URLの文字列だけが本文に残っている状況。
		wp_update_post(
			array(
				'ID'           => $postId,
				'post_content' => '<p>以前は ' . esc_html( $short ) . ' を紹介していました。</p>',
			)
		);

		$this->assertSame( array(), $this->links->findByPost( $postId ), 'A bare short URL kept a removed link active.' );
		$this->assertSame( 0, $this->links->findById( $linkId )['status'] );
	}

	public function test_a_shortcode_only_affiliate_url_issues_a_link_but_leaves_content_untouched(): void {
		$content = '[blogcard url="' . self::AFFILIATE . '"]';
		$postId  = $this->createPostWithLink( $content );

		$links = $this->links->findByPost( $postId );

		$this->assertCount( 1, $links );
		$this->assertSame( self::AFFILIATE, $links[0]['target_url'] );
		$this->assertSame( $content, get_post_field( 'post_content', $postId ), 'A shortcode-only post must be byte-identical after save: rewriting it would break the card preview.' );
	}

	public function test_writing_content_bumps_the_modified_time(): void {
		$postId = self::factory()->post->create(
			array(
				'post_content'      => '<p>まだリンクなし</p>',
				'post_status'       => 'publish',
				'post_modified'     => '2020-01-01 00:00:00',
				'post_modified_gmt' => '2020-01-01 00:00:00',
			)
		);

		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_content'      => '<a href="' . self::AFFILIATE . '">ホテル</a>',
				'post_modified'     => '2020-01-01 00:00:00',
				'post_modified_gmt' => '2020-01-01 00:00:00',
			),
			array( 'ID' => $postId )
		);
		clean_post_cache( $postId );

		$this->assertTrue( $this->sync->syncPost( $postId ) );

		// 更新時刻が古いままだと、キャッシュが書き換え前の本文を配り続ける。
		$this->assertNotSame( '2020-01-01 00:00:00', get_post_field( 'post_modified_gmt', $postId ) );
	}
}
