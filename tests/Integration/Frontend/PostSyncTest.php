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

	protected function setUp(): void {
		parent::setUp();
		$this->links = new LinkRepository();
		$this->sync  = new PostSync( $this->links );
		$this->sync->register();
	}

	/**
	 * PostSync::register() adds a process-wide `save_post` action. Left in
	 * place it would keep firing for every post any later test class saves,
	 * so it must be removed again once this test is done with it.
	 */
	protected function tearDown(): void {
		remove_action( 'save_post', array( $this->sync, 'onSavePost' ), 20 );
		parent::tearDown();
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

	public function test_two_posts_sharing_a_url_get_different_codes(): void {
		$a = $this->createPostWithLink( '<a href="' . self::AFFILIATE . '">ホテル</a>' );
		$b = $this->createPostWithLink( '<a href="' . self::AFFILIATE . '">ホテル</a>' );

		$codeA = $this->links->findByPost( $a )[0]['code'];
		$codeB = $this->links->findByPost( $b )[0]['code'];

		$this->assertNotSame( $codeA, $codeB );
	}
}
