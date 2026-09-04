<?php

namespace RLT\Tests\Integration\Admin;

use RLT\Admin\PostsReportPage;
use RLT\Data\LinkRepository;
use RLT\Installer;
use WP_UnitTestCase;

final class PostsReportPageTest extends WP_UnitTestCase {

	private int $postId;

	protected function setUp(): void {
		parent::setUp();
		update_option( 'timezone_string', 'Asia/Tokyo' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->postId = self::factory()->post->create( array( 'post_title' => '京都の宿10選', 'post_status' => 'publish' ) );
		( new LinkRepository() )->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', $this->postId, 'A' );

		global $wpdb;
		$wpdb->insert(
			Installer::viewsTable(),
			array(
				'post_id'      => $this->postId,
				'viewed_at'    => gmdate( 'Y-m-d H:i:s' ),
				'visitor_hash' => str_pad( 'v', 64, '0' ),
				'referer'      => '',
				'device'       => 1,
				'is_bot'       => 0,
			)
		);
	}

	protected function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	private function render(): string {
		ob_start();
		( new PostsReportPage() )->render();

		return (string) ob_get_clean();
	}

	public function test_render_lists_the_post(): void {
		$html = $this->render();

		$this->assertStringContainsString( '京都の宿10選', $html );
	}

	public function test_render_shows_views_clicks_and_ctr_columns(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'PV', $html );
		$this->assertStringContainsString( 'クリック', $html );
		$this->assertStringContainsString( 'CTR', $html );
	}

	public function test_render_offers_every_documented_ordering(): void {
		$html = $this->render();

		foreach ( array_keys( PostsReportPage::ORDERINGS ) as $orderby ) {
			$this->assertStringContainsString( 'orderby=' . $orderby, $html );
		}
	}

	public function test_ctr_ascending_ordering_is_available_for_finding_weak_posts(): void {
		$this->assertArrayHasKey( 'ctr_asc', PostsReportPage::ORDERINGS );
	}

	public function test_render_links_to_the_links_screen_filtered_by_post(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'post_id=' . $this->postId, $html );
	}

	public function test_render_escapes_post_titles(): void {
		self::factory()->post->create( array( 'post_title' => '<script>alert(1)</script>', 'post_status' => 'publish' ) );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $this->render() );
	}

	public function test_render_shows_an_empty_state(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Installer::viewsTable() );

		$this->assertStringContainsString( 'まだデータがありません', $this->render() );
	}
}
