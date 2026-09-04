<?php

namespace RLT\Tests\Integration\Admin;

use RLT\Admin\LinkDetailPage;
use RLT\Admin\LinksListTable;
use RLT\Data\LinkRepository;
use RLT\Installer;
use RLT\Support\DateRange;
use WP_UnitTestCase;

final class LinksListTableTest extends WP_UnitTestCase {

	private LinkRepository $links;
	private array $link;
	private int $postId;

	protected function setUp(): void {
		parent::setUp();
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		update_option( 'timezone_string', 'Asia/Tokyo' );
		set_current_screen( 'toplevel_page_rakuten-link-tracker' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->postId = self::factory()->post->create( array( 'post_title' => '東京のホテル特集', 'post_status' => 'publish' ) );
		$this->links  = new LinkRepository();
		$this->link   = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', $this->postId, 'ホテルA' );
	}

	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		parent::tearDown();
	}

	private function table(): LinksListTable {
		$table = new LinksListTable( DateRange::lastDays( 28 ) );
		$table->prepare_items();

		return $table;
	}

	public function test_columns_cover_the_documented_fields(): void {
		$columns = ( new LinksListTable( DateRange::lastDays( 28 ) ) )->get_columns();

		foreach ( array( 'label', 'code', 'post', 'clicks', 'ctr', 'last_click', 'status' ) as $column ) {
			$this->assertArrayHasKey( $column, $columns, "Column {$column} is missing." );
		}
	}

	public function test_prepare_items_loads_the_links(): void {
		$table = $this->table();

		$this->assertCount( 1, $table->items );
		$this->assertSame( $this->link['code'], $table->items[0]['code'] );
	}

	public function test_prepare_items_can_filter_by_post(): void {
		$other = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/b', $other, 'B' );

		$_GET['post_id'] = (string) $this->postId;

		$this->assertCount( 1, $this->table()->items );
	}

	public function test_prepare_items_can_search_by_label(): void {
		$this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/b', $this->postId, '大阪の宿' );

		$_GET['s'] = '大阪';

		$items = $this->table()->items;

		$this->assertCount( 1, $items );
		$this->assertSame( '大阪の宿', $items[0]['label'] );
	}

	public function test_list_screen_renders_the_short_url(): void {
		ob_start();
		( new LinkDetailPage() )->route();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '/go/' . $this->link['code'], $html );
		$this->assertStringContainsString( 'ホテルA', $html );
	}

	public function test_detail_screen_renders_breakdowns(): void {
		global $wpdb;

		$wpdb->insert(
			Installer::clicksTable(),
			array(
				'link_id'      => $this->link['id'],
				'post_id'      => $this->postId,
				'clicked_at'   => gmdate( 'Y-m-d H:i:s' ),
				'visitor_hash' => str_pad( 'v', 64, '0' ),
				'referer'      => 'https://www.google.com/',
				'device'       => 2,
				'is_bot'       => 0,
			)
		);

		$_GET['code'] = $this->link['code'];

		ob_start();
		( new LinkDetailPage() )->route();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'https://www.google.com/', $html );
		$this->assertStringContainsString( 'mobile', $html );
		$this->assertStringContainsString( '<svg', $html );
	}

	public function test_detail_screen_shows_a_notice_for_an_unknown_code(): void {
		$_GET['code'] = 'zzzzzz';

		ob_start();
		( new LinkDetailPage() )->route();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $html );
	}

	public function test_update_changes_the_target_url(): void {
		$_POST = array(
			'code'       => $this->link['code'],
			'target_url' => 'https://hb.afl.rakuten.co.jp/hgc/new',
			'label'      => '改名しました',
			'_wpnonce'   => wp_create_nonce( 'rlt_update_link' ),
		);

		( new LinkDetailPage() )->handleUpdate( false );

		$updated = $this->links->findById( $this->link['id'] );

		$this->assertSame( 'https://hb.afl.rakuten.co.jp/hgc/new', $updated['target_url'] );
		$this->assertSame( '改名しました', $updated['label'] );
	}

	public function test_update_without_a_valid_nonce_changes_nothing(): void {
		$_POST = array(
			'code'       => $this->link['code'],
			'target_url' => 'https://evil.example/',
			'_wpnonce'   => 'wrong',
		);

		( new LinkDetailPage() )->handleUpdate( false );

		$this->assertSame( 'https://hb.afl.rakuten.co.jp/hgc/a', $this->links->findById( $this->link['id'] )['target_url'] );
	}

	public function test_update_rejects_a_javascript_url(): void {
		$_POST = array(
			'code'       => $this->link['code'],
			'target_url' => 'javascript:alert(1)',
			'_wpnonce'   => wp_create_nonce( 'rlt_update_link' ),
		);

		( new LinkDetailPage() )->handleUpdate( false );

		$this->assertSame( 'https://hb.afl.rakuten.co.jp/hgc/a', $this->links->findById( $this->link['id'] )['target_url'] );
	}
}
