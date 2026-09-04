<?php

namespace RLT\Tests\Integration\Admin;

use RLT\Admin\BulkConverter;
use RLT\Data\LinkRepository;
use RLT\Frontend\PostSync;
use RLT\Settings;
use WP_UnitTestCase;

final class BulkConverterTest extends WP_UnitTestCase {

	private const AFFILIATE = 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=x';

	private BulkConverter $bulk;

	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->bulk = new BulkConverter();
	}

	/**
	 * Create a post whose content still holds the raw affiliate URL, i.e. one
	 * published before the plugin was installed.
	 */
	private function legacyPost(): int {
		$postId = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => 'placeholder' ) );

		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array( 'post_content' => '<a href="' . self::AFFILIATE . '">ホテル</a>' ),
			array( 'ID' => $postId )
		);
		clean_post_cache( $postId );

		return $postId;
	}

	public function test_convert_batch_reports_progress(): void {
		$this->legacyPost();
		$this->legacyPost();

		$result = $this->bulk->convertBatch( 0 );

		$this->assertSame( 2, $result['total'] );
		$this->assertSame( 2, $result['processed'] );
		$this->assertSame( 2, $result['changed'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_convert_batch_actually_rewrites_the_content(): void {
		$postId = $this->legacyPost();

		$this->bulk->convertBatch( 0 );

		$content = get_post_field( 'post_content', $postId );

		$this->assertStringNotContainsString( self::AFFILIATE, $content );
		$this->assertStringContainsString( Settings::shortBase(), $content );
	}

	public function test_convert_batch_is_capped_at_the_batch_size(): void {
		for ( $i = 0; $i < BulkConverter::BATCH_SIZE + 3; $i++ ) {
			$this->legacyPost();
		}

		$first = $this->bulk->convertBatch( 0 );

		$this->assertSame( BulkConverter::BATCH_SIZE, $first['processed'] );
		$this->assertFalse( $first['done'] );
		$this->assertSame( BulkConverter::BATCH_SIZE, $first['offset'] );

		$second = $this->bulk->convertBatch( $first['offset'] );

		$this->assertSame( 3, $second['processed'] );
		$this->assertTrue( $second['done'] );
	}

	public function test_convert_batch_is_idempotent(): void {
		$postId = $this->legacyPost();

		$this->bulk->convertBatch( 0 );
		$after = get_post_field( 'post_content', $postId );

		$second = $this->bulk->convertBatch( 0 );

		$this->assertSame( 0, $second['changed'] );
		$this->assertSame( $after, get_post_field( 'post_content', $postId ) );
	}

	public function test_restore_batch_puts_the_original_urls_back(): void {
		$postId = $this->legacyPost();
		$this->bulk->convertBatch( 0 );

		$result = $this->bulk->restoreBatch( 0 );

		$this->assertSame( 1, $result['changed'] );
		$this->assertTrue( $result['done'] );
		$this->assertStringContainsString( self::AFFILIATE, get_post_field( 'post_content', $postId ) );
	}

	public function test_restore_only_visits_posts_that_have_links(): void {
		$this->legacyPost();
		$this->bulk->convertBatch( 0 );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => 'リンクなし' ) );

		$this->assertSame( 1, $this->bulk->restoreBatch( 0 )['total'] );
	}

	public function test_ajax_actions_are_registered(): void {
		$this->bulk->register();

		$this->assertNotFalse( has_action( 'wp_ajax_' . BulkConverter::ACTION_CONVERT ) );
		$this->assertNotFalse( has_action( 'wp_ajax_' . BulkConverter::ACTION_RESTORE ) );
	}
}
