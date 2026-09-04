<?php

namespace RLT\Tests\Integration\Data;

use RLT\Data\EventRepository;
use RLT\Data\LinkRepository;
use RLT\Installer;
use RLT\Support\DateRange;
use RLT\Support\DeviceDetector;
use WP_UnitTestCase;

final class EventRepositoryStatsTest extends WP_UnitTestCase {

	private EventRepository $events;
	private LinkRepository $links;

	protected function setUp(): void {
		parent::setUp();
		update_option( 'timezone_string', 'Asia/Tokyo' );

		$this->events = new EventRepository();
		$this->links  = new LinkRepository();
	}

	/**
	 * Insert a click directly so the test controls the timestamp and visitor.
	 */
	private function click( int $linkId, int $postId, string $localDate, string $visitor = 'v1', bool $isBot = false, string $referer = '', int $device = DeviceDetector::DESKTOP ): void {
		global $wpdb;

		$wpdb->insert(
			Installer::clicksTable(),
			array(
				'link_id'      => $linkId,
				'post_id'      => $postId,
				// 12:00 JST は必ずその日の中に収まる。
				'clicked_at'   => get_gmt_from_date( $localDate . ' 12:00:00' ),
				'visitor_hash' => str_pad( $visitor, 64, '0' ),
				'referer'      => $referer,
				'device'       => $device,
				'is_bot'       => $isBot ? 1 : 0,
			)
		);
	}

	private function view( int $postId, string $localDate, string $visitor = 'v1', bool $isBot = false ): void {
		global $wpdb;

		$wpdb->insert(
			Installer::viewsTable(),
			array(
				'post_id'      => $postId,
				'viewed_at'    => get_gmt_from_date( $localDate . ' 12:00:00' ),
				'visitor_hash' => str_pad( $visitor, 64, '0' ),
				'referer'      => '',
				'device'       => DeviceDetector::DESKTOP,
				'is_bot'       => $isBot ? 1 : 0,
			)
		);
	}

	public function test_summary_counts_clicks_and_views(): void {
		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );

		$this->click( $link['id'], 42, '2026-09-01', 'v1' );
		$this->click( $link['id'], 42, '2026-09-01', 'v2' );
		$this->view( 42, '2026-09-01', 'v1' );
		$this->view( 42, '2026-09-01', 'v2' );
		$this->view( 42, '2026-09-01', 'v3' );
		$this->view( 42, '2026-09-01', 'v4' );

		$summary = $this->events->summary( new DateRange( '2026-09-01', '2026-09-01' ) );

		$this->assertSame( 2, $summary['clicks'] );
		$this->assertSame( 4, $summary['views'] );
		$this->assertSame( 0.5, $summary['ctr'] );
	}

	public function test_summary_counts_unique_visitors(): void {
		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );

		$this->click( $link['id'], 42, '2026-09-01', 'v1' );
		$this->click( $link['id'], 42, '2026-09-01', 'v1' );
		$this->click( $link['id'], 42, '2026-09-01', 'v2' );

		$summary = $this->events->summary( new DateRange( '2026-09-01', '2026-09-01' ) );

		$this->assertSame( 3, $summary['clicks'] );
		$this->assertSame( 2, $summary['unique_clicks'] );
	}

	public function test_summary_excludes_bots_by_default(): void {
		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );

		$this->click( $link['id'], 42, '2026-09-01', 'v1' );
		$this->click( $link['id'], 42, '2026-09-01', 'bot', true );

		$range = new DateRange( '2026-09-01', '2026-09-01' );

		$this->assertSame( 1, $this->events->summary( $range )['clicks'] );
		$this->assertSame( 2, $this->events->summary( $range, true )['clicks'] );
	}

	public function test_summary_ignores_events_outside_the_range(): void {
		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );

		$this->click( $link['id'], 42, '2026-08-31' );
		$this->click( $link['id'], 42, '2026-09-01' );
		$this->click( $link['id'], 42, '2026-09-03' );

		$this->assertSame( 1, $this->events->summary( new DateRange( '2026-09-01', '2026-09-02' ) )['clicks'] );
	}

	public function test_summary_ctr_is_zero_when_there_are_no_views(): void {
		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );
		$this->click( $link['id'], 42, '2026-09-01' );

		$this->assertSame( 0.0, $this->events->summary( new DateRange( '2026-09-01', '2026-09-01' ) )['ctr'] );
	}

	public function test_daily_fills_gaps_with_zeros(): void {
		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );

		$this->click( $link['id'], 42, '2026-09-01' );
		$this->click( $link['id'], 42, '2026-09-03' );

		$daily = $this->events->daily( new DateRange( '2026-09-01', '2026-09-03' ) );

		$this->assertCount( 3, $daily );
		$this->assertSame( array( '2026-09-01', '2026-09-02', '2026-09-03' ), array_column( $daily, 'date' ) );
		$this->assertSame( array( 1, 0, 1 ), array_column( $daily, 'clicks' ) );
	}

	public function test_daily_groups_by_site_local_day(): void {
		global $wpdb;

		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );

		// 2026-09-01 23:30 JST = 2026-09-01 14:30 UTC。JST の 9/1 に入るべき。
		$wpdb->insert(
			Installer::clicksTable(),
			array(
				'link_id'      => $link['id'],
				'post_id'      => 42,
				'clicked_at'   => '2026-09-01 14:30:00',
				'visitor_hash' => str_pad( 'v1', 64, '0' ),
				'referer'      => '',
				'device'       => 1,
				'is_bot'       => 0,
			)
		);

		$daily = $this->events->daily( new DateRange( '2026-09-01', '2026-09-02' ) );

		$this->assertSame( 1, $daily[0]['clicks'] );
		$this->assertSame( 0, $daily[1]['clicks'] );
	}

	public function test_by_post_returns_views_clicks_and_ctr(): void {
		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );

		$this->click( $link['id'], 42, '2026-09-01', 'v1' );
		$this->view( 42, '2026-09-01', 'v1' );
		$this->view( 42, '2026-09-01', 'v2' );
		$this->view( 43, '2026-09-01', 'v3' );

		$rows = $this->events->byPost( new DateRange( '2026-09-01', '2026-09-01' ), false, 'clicks', 50 );
		$byId = array_column( $rows, null, 'post_id' );

		$this->assertSame( 2, $byId[42]['views'] );
		$this->assertSame( 1, $byId[42]['clicks'] );
		$this->assertSame( 0.5, $byId[42]['ctr'] );
		// クリックのない記事も PV があれば出す（改善候補として見たいため）。
		$this->assertSame( 0, $byId[43]['clicks'] );
	}

	public function test_by_post_can_order_by_ctr_ascending(): void {
		$linkA = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );
		$linkB = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/b', 43, 'B' );

		$this->click( $linkA['id'], 42, '2026-09-01', 'v1' );
		$this->view( 42, '2026-09-01', 'v1' );

		$this->view( 43, '2026-09-01', 'v2' );
		$this->view( 43, '2026-09-01', 'v3' );

		$rows = $this->events->byPost( new DateRange( '2026-09-01', '2026-09-01' ), false, 'ctr_asc', 50 );

		$this->assertSame( 43, $rows[0]['post_id'] );
	}

	public function test_by_link_joins_link_metadata(): void {
		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'ホテルA' );

		$this->click( $link['id'], 42, '2026-09-01', 'v1' );
		$this->click( $link['id'], 42, '2026-09-01', 'v1' );
		$this->view( 42, '2026-09-01', 'v1' );
		$this->view( 42, '2026-09-01', 'v2' );

		$rows = $this->events->byLink( new DateRange( '2026-09-01', '2026-09-01' ), false, null, 'clicks', 50 );

		$this->assertCount( 1, $rows );
		$this->assertSame( $link['id'], $rows[0]['link_id'] );
		$this->assertSame( 'ホテルA', $rows[0]['label'] );
		$this->assertSame( $link['code'], $rows[0]['code'] );
		$this->assertSame( 2, $rows[0]['clicks'] );
		$this->assertSame( 1, $rows[0]['unique_clicks'] );
		$this->assertSame( 2, $rows[0]['post_views'] );
		$this->assertSame( 1.0, $rows[0]['ctr'] );
		$this->assertNotNull( $rows[0]['last_click'] );
	}

	public function test_by_link_includes_links_with_no_clicks(): void {
		$this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'クリックゼロ' );

		$rows = $this->events->byLink( new DateRange( '2026-09-01', '2026-09-01' ), false, null, 'clicks', 50 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 0, $rows[0]['clicks'] );
		$this->assertNull( $rows[0]['last_click'] );
	}

	public function test_by_link_can_filter_to_one_post(): void {
		$this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );
		$this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/b', 43, 'B' );

		$rows = $this->events->byLink( new DateRange( '2026-09-01', '2026-09-01' ), false, 42, 'clicks', 50 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 42, $rows[0]['post_id'] );
	}

	public function test_link_detail_breaks_down_by_day_referer_and_device(): void {
		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );

		$this->click( $link['id'], 42, '2026-09-01', 'v1', false, 'https://www.google.com/', DeviceDetector::MOBILE );
		$this->click( $link['id'], 42, '2026-09-01', 'v2', false, 'https://www.google.com/', DeviceDetector::DESKTOP );
		$this->click( $link['id'], 42, '2026-09-02', 'v3', false, '', DeviceDetector::MOBILE );

		$detail = $this->events->linkDetail( $link['id'], new DateRange( '2026-09-01', '2026-09-02' ), false );

		$this->assertSame( array( 2, 1 ), array_column( $detail['daily'], 'clicks' ) );

		$referers = array_column( $detail['referers'], 'clicks', 'referer' );
		$this->assertSame( 2, $referers['https://www.google.com/'] );

		$devices = array_column( $detail['devices'], 'clicks', 'label' );
		$this->assertSame( 2, $devices['mobile'] );
		$this->assertSame( 1, $devices['desktop'] );
	}

	public function test_purge_deletes_only_rows_older_than_the_cutoff(): void {
		global $wpdb;

		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );

		$this->click( $link['id'], 42, gmdate( 'Y-m-d' ) );
		$this->click( $link['id'], 42, gmdate( 'Y-m-d', strtotime( '-400 days' ) ) );
		$this->view( 42, gmdate( 'Y-m-d', strtotime( '-400 days' ) ) );

		$deleted = $this->events->purgeOlderThan( 365 );

		$this->assertSame( 2, $deleted );
		$this->assertSame( '1', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::clicksTable() ) );
		$this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::viewsTable() ) );
	}

	public function test_purge_with_zero_days_keeps_everything(): void {
		global $wpdb;

		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );
		$this->click( $link['id'], 42, gmdate( 'Y-m-d', strtotime( '-1000 days' ) ) );

		$this->assertSame( 0, $this->events->purgeOlderThan( 0 ) );
		$this->assertSame( '1', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::clicksTable() ) );
	}

	public function test_clicks_for_export_returns_flat_rows(): void {
		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'ホテルA' );
		$this->click( $link['id'], 42, '2026-09-01' );

		$rows = $this->events->clicksForExport( new DateRange( '2026-09-01', '2026-09-01' ), false );

		$this->assertCount( 1, $rows );
		$this->assertSame( $link['code'], $rows[0]['code'] );
		$this->assertSame( 'ホテルA', $rows[0]['label'] );
		$this->assertSame( 'desktop', $rows[0]['device'] );
		$this->assertArrayNotHasKey( 'visitor_hash', $rows[0] );
	}

	public function test_by_link_reports_the_posts_view_total_on_every_link_of_that_post(): void {
		$a = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/regression/a', 99, 'A' );
		$b = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/regression/b', 99, 'B' );

		$this->click( $a['id'], 99, '2026-09-01', 'v1' );
		$this->view( 99, '2026-09-01', 'v1' );
		$this->view( 99, '2026-09-01', 'v2' );

		$rows = $this->events->byLink( new DateRange( '2026-09-01', '2026-09-01' ), false, null, 'clicks', 50 );

		$this->assertCount( 2, $rows );

		// 同じ記事のリンクには同じ記事PVが入る。行をまたいで合計してはならない。
		foreach ( $rows as $row ) {
			$this->assertSame( 2, $row['post_views'], 'post_views must be the post total, repeated per link.' );
		}
	}
}
