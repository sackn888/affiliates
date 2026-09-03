<?php

declare(strict_types=1);

namespace RLT\Data;

use RLT\Installer;
use RLT\Support\DateRange;
use RLT\Support\DeviceDetector;
use RLT\Support\RequestContext;

/**
 * Records click and view events, and answers questions about them.
 *
 * Bot traffic is stored with is_bot = 1 rather than dropped, so the bot rules
 * can be revised later without losing history.
 */
final class EventRepository {

	private \wpdb $db;

	public function __construct() {
		global $wpdb;

		$this->db = $wpdb;
	}

	public function recordClick( int $linkId, int $postId ): bool {
		return false !== $this->db->insert(
			Installer::clicksTable(),
			array(
				'link_id'      => $linkId,
				'post_id'      => $postId,
				'clicked_at'   => current_time( 'mysql', true ),
				'visitor_hash' => RequestContext::visitorHash(),
				'referer'      => RequestContext::referer(),
				'device'       => RequestContext::device(),
				'is_bot'       => RequestContext::isBot() ? 1 : 0,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%d' )
		);
	}

	public function recordView( int $postId ): bool {
		return false !== $this->db->insert(
			Installer::viewsTable(),
			array(
				'post_id'      => $postId,
				'viewed_at'    => current_time( 'mysql', true ),
				'visitor_hash' => RequestContext::visitorHash(),
				'referer'      => RequestContext::referer(),
				'device'       => RequestContext::device(),
				'is_bot'       => RequestContext::isBot() ? 1 : 0,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d' )
		);
	}

	public function hasRecentClick( int $linkId, string $visitorHash, int $seconds ): bool {
		if ( $seconds <= 0 || '' === $visitorHash ) {
			return false;
		}

		return (bool) $this->db->get_var(
			$this->db->prepare(
				'SELECT 1 FROM ' . Installer::clicksTable() . '
				 WHERE link_id = %d AND visitor_hash = %s AND clicked_at > DATE_SUB(%s, INTERVAL %d SECOND)
				 LIMIT 1',
				$linkId,
				$visitorHash,
				current_time( 'mysql', true ),
				$seconds
			)
		);
	}

	public function hasRecentView( int $postId, string $visitorHash, int $seconds ): bool {
		if ( $seconds <= 0 || '' === $visitorHash ) {
			return false;
		}

		return (bool) $this->db->get_var(
			$this->db->prepare(
				'SELECT 1 FROM ' . Installer::viewsTable() . '
				 WHERE post_id = %d AND visitor_hash = %s AND viewed_at > DATE_SUB(%s, INTERVAL %d SECOND)
				 LIMIT 1',
				$postId,
				$visitorHash,
				current_time( 'mysql', true ),
				$seconds
			)
		);
	}

	/**
	 * @return array{clicks:int, unique_clicks:int, views:int, unique_views:int, ctr:float}
	 */
	public function summary( DateRange $range, bool $includeBots = false ): array {
		$clicks = $this->db->get_row(
			$this->db->prepare(
				'SELECT COUNT(*) AS total, COUNT(DISTINCT visitor_hash) AS uniques
				 FROM ' . Installer::clicksTable() . '
				 WHERE clicked_at BETWEEN %s AND %s' . $this->botClause( $includeBots ),
				$range->startUtc(),
				$range->endUtc()
			),
			ARRAY_A
		);

		$views = $this->db->get_row(
			$this->db->prepare(
				'SELECT COUNT(*) AS total, COUNT(DISTINCT visitor_hash) AS uniques
				 FROM ' . Installer::viewsTable() . '
				 WHERE viewed_at BETWEEN %s AND %s' . $this->botClause( $includeBots ),
				$range->startUtc(),
				$range->endUtc()
			),
			ARRAY_A
		);

		$clickTotal = (int) ( $clicks['total'] ?? 0 );
		$viewTotal  = (int) ( $views['total'] ?? 0 );

		return array(
			'clicks'        => $clickTotal,
			'unique_clicks' => (int) ( $clicks['uniques'] ?? 0 ),
			'views'         => $viewTotal,
			'unique_views'  => (int) ( $views['uniques'] ?? 0 ),
			'ctr'           => self::ctr( $clickTotal, $viewTotal ),
		);
	}

	/**
	 * @return array<int, array{date:string, clicks:int, views:int}>
	 */
	public function daily( DateRange $range, bool $includeBots = false ): array {
		$offset = $range->offsetSeconds();

		$clicks = $this->dailyCounts( Installer::clicksTable(), 'clicked_at', $range, $includeBots, $offset );
		$views  = $this->dailyCounts( Installer::viewsTable(), 'viewed_at', $range, $includeBots, $offset );

		$out = array();
		foreach ( $range->days() as $day ) {
			$out[] = array(
				'date'   => $day,
				'clicks' => $clicks[ $day ] ?? 0,
				'views'  => $views[ $day ] ?? 0,
			);
		}

		return $out;
	}

	/**
	 * @return array<int, array{post_id:int, views:int, clicks:int, ctr:float}>
	 */
	public function byPost( DateRange $range, bool $includeBots, string $orderby, int $limit ): array {
		$clickRows = $this->db->get_results(
			$this->db->prepare(
				'SELECT post_id, COUNT(*) AS clicks
				 FROM ' . Installer::clicksTable() . '
				 WHERE clicked_at BETWEEN %s AND %s' . $this->botClause( $includeBots ) . '
				 GROUP BY post_id',
				$range->startUtc(),
				$range->endUtc()
			),
			ARRAY_A
		) ?: array();

		$viewRows = $this->db->get_results(
			$this->db->prepare(
				'SELECT post_id, COUNT(*) AS views
				 FROM ' . Installer::viewsTable() . '
				 WHERE viewed_at BETWEEN %s AND %s' . $this->botClause( $includeBots ) . '
				 GROUP BY post_id',
				$range->startUtc(),
				$range->endUtc()
			),
			ARRAY_A
		) ?: array();

		$merged = array();

		foreach ( $viewRows as $row ) {
			$merged[ (int) $row['post_id'] ] = array(
				'post_id' => (int) $row['post_id'],
				'views'   => (int) $row['views'],
				'clicks'  => 0,
			);
		}

		foreach ( $clickRows as $row ) {
			$postId = (int) $row['post_id'];

			if ( ! isset( $merged[ $postId ] ) ) {
				$merged[ $postId ] = array(
					'post_id' => $postId,
					'views'   => 0,
					'clicks'  => 0,
				);
			}

			$merged[ $postId ]['clicks'] = (int) $row['clicks'];
		}

		foreach ( $merged as &$row ) {
			$row['ctr'] = self::ctr( $row['clicks'], $row['views'] );
		}
		unset( $row );

		$rows = array_values( $merged );
		self::sortRows( $rows, $orderby );

		return array_slice( $rows, 0, max( 1, $limit ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function byLink( DateRange $range, bool $includeBots, ?int $postId, string $orderby, int $limit ): array {
		$links  = Installer::linksTable();
		$clicks = Installer::clicksTable();
		$views  = Installer::viewsTable();

		$botClicks = $includeBots ? '' : ' AND c.is_bot = 0';
		$botViews  = $includeBots ? '' : ' AND is_bot = 0';
		$where     = null === $postId ? '' : ' WHERE l.post_id = %d';

		$sql = "SELECT l.id AS link_id, l.code, l.label, l.post_id, l.target_url, l.status,
					COUNT(c.id) AS clicks,
					COUNT(DISTINCT c.visitor_hash) AS unique_clicks,
					MAX(c.clicked_at) AS last_click,
					COALESCE(v.views, 0) AS views
				FROM {$links} l
				LEFT JOIN {$clicks} c
					ON c.link_id = l.id AND c.clicked_at BETWEEN %s AND %s{$botClicks}
				LEFT JOIN (
					SELECT post_id, COUNT(*) AS views
					FROM {$views}
					WHERE viewed_at BETWEEN %s AND %s{$botViews}
					GROUP BY post_id
				) v ON v.post_id = l.post_id
				{$where}
				GROUP BY l.id";

		$params = array( $range->startUtc(), $range->endUtc(), $range->startUtc(), $range->endUtc() );

		if ( null !== $postId ) {
			$params[] = $postId;
		}

		$rows = $this->db->get_results( $this->db->prepare( $sql, $params ), ARRAY_A ) ?: array();

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'link_id'       => (int) $row['link_id'],
				'code'          => (string) $row['code'],
				'label'         => (string) $row['label'],
				'post_id'       => (int) $row['post_id'],
				'target_url'    => (string) $row['target_url'],
				'status'        => (int) $row['status'],
				'clicks'        => (int) $row['clicks'],
				'unique_clicks' => (int) $row['unique_clicks'],
				'views'         => (int) $row['views'],
				'ctr'           => self::ctr( (int) $row['clicks'], (int) $row['views'] ),
				'last_click'    => null === $row['last_click'] ? null : (string) $row['last_click'],
			);
		}

		self::sortRows( $out, $orderby );

		return array_slice( $out, 0, max( 1, $limit ) );
	}

	/**
	 * @return array{daily:array, referers:array, devices:array}
	 */
	public function linkDetail( int $linkId, DateRange $range, bool $includeBots ): array {
		$table  = Installer::clicksTable();
		$offset = $range->offsetSeconds();
		$bots   = $this->botClause( $includeBots );

		$dailyRows = $this->db->get_results(
			$this->db->prepare(
				"SELECT DATE(clicked_at + INTERVAL %d SECOND) AS day, COUNT(*) AS clicks
				 FROM {$table}
				 WHERE link_id = %d AND clicked_at BETWEEN %s AND %s{$bots}
				 GROUP BY day",
				$offset,
				$linkId,
				$range->startUtc(),
				$range->endUtc()
			),
			ARRAY_A
		) ?: array();

		$byDay = array();
		foreach ( $dailyRows as $row ) {
			$byDay[ (string) $row['day'] ] = (int) $row['clicks'];
		}

		$daily = array();
		foreach ( $range->days() as $day ) {
			$daily[] = array(
				'date'   => $day,
				'clicks' => $byDay[ $day ] ?? 0,
			);
		}

		$refererRows = $this->db->get_results(
			$this->db->prepare(
				"SELECT referer, COUNT(*) AS clicks
				 FROM {$table}
				 WHERE link_id = %d AND clicked_at BETWEEN %s AND %s{$bots}
				 GROUP BY referer
				 ORDER BY clicks DESC
				 LIMIT 20",
				$linkId,
				$range->startUtc(),
				$range->endUtc()
			),
			ARRAY_A
		) ?: array();

		$referers = array();
		foreach ( $refererRows as $row ) {
			$referers[] = array(
				'referer' => (string) $row['referer'],
				'clicks'  => (int) $row['clicks'],
			);
		}

		$deviceRows = $this->db->get_results(
			$this->db->prepare(
				"SELECT device, COUNT(*) AS clicks
				 FROM {$table}
				 WHERE link_id = %d AND clicked_at BETWEEN %s AND %s{$bots}
				 GROUP BY device
				 ORDER BY clicks DESC",
				$linkId,
				$range->startUtc(),
				$range->endUtc()
			),
			ARRAY_A
		) ?: array();

		$devices = array();
		foreach ( $deviceRows as $row ) {
			$devices[] = array(
				'device' => (int) $row['device'],
				'label'  => DeviceDetector::label( (int) $row['device'] ),
				'clicks' => (int) $row['clicks'],
			);
		}

		return array(
			'daily'    => $daily,
			'referers' => $referers,
			'devices'  => $devices,
		);
	}

	/**
	 * Flat click rows for CSV export. The visitor hash is deliberately omitted.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function clicksForExport( DateRange $range, bool $includeBots ): array {
		$clicks = Installer::clicksTable();
		$links  = Installer::linksTable();

		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT c.clicked_at, l.code, l.label, c.post_id, l.target_url, c.referer, c.device, c.is_bot
				 FROM ' . $clicks . ' c
				 LEFT JOIN ' . $links . ' l ON l.id = c.link_id
				 WHERE c.clicked_at BETWEEN %s AND %s' . ( $includeBots ? '' : ' AND c.is_bot = 0' ) . '
				 ORDER BY c.clicked_at ASC',
				$range->startUtc(),
				$range->endUtc()
			),
			ARRAY_A
		) ?: array();

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'clicked_at' => get_date_from_gmt( (string) $row['clicked_at'], 'c' ),
				'code'       => (string) $row['code'],
				'label'      => (string) $row['label'],
				'post_id'    => (int) $row['post_id'],
				'target_url' => (string) $row['target_url'],
				'referer'    => (string) $row['referer'],
				'device'     => DeviceDetector::label( (int) $row['device'] ),
				'is_bot'     => (int) $row['is_bot'],
			);
		}

		return $out;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function viewsForExport( DateRange $range, bool $includeBots ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT viewed_at, post_id, referer, device, is_bot
				 FROM ' . Installer::viewsTable() . '
				 WHERE viewed_at BETWEEN %s AND %s' . $this->botClause( $includeBots ) . '
				 ORDER BY viewed_at ASC',
				$range->startUtc(),
				$range->endUtc()
			),
			ARRAY_A
		) ?: array();

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'viewed_at' => get_date_from_gmt( (string) $row['viewed_at'], 'c' ),
				'post_id'   => (int) $row['post_id'],
				'referer'   => (string) $row['referer'],
				'device'    => DeviceDetector::label( (int) $row['device'] ),
				'is_bot'    => (int) $row['is_bot'],
			);
		}

		return $out;
	}

	/**
	 * Delete raw log rows older than the retention window.
	 *
	 * @param int $days 0 keeps everything.
	 * @return int Rows deleted across both tables.
	 */
	public function purgeOlderThan( int $days ): int {
		if ( $days <= 0 ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$deleted = (int) $this->db->query(
			$this->db->prepare( 'DELETE FROM ' . Installer::clicksTable() . ' WHERE clicked_at < %s', $cutoff )
		);

		$deleted += (int) $this->db->query(
			$this->db->prepare( 'DELETE FROM ' . Installer::viewsTable() . ' WHERE viewed_at < %s', $cutoff )
		);

		return $deleted;
	}

	/**
	 * @return array<string, int> Local date => count.
	 */
	private function dailyCounts( string $table, string $column, DateRange $range, bool $includeBots, int $offset ): array {
		$bots = $this->botClause( $includeBots );

		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT DATE({$column} + INTERVAL %d SECOND) AS day, COUNT(*) AS total
				 FROM {$table}
				 WHERE {$column} BETWEEN %s AND %s{$bots}
				 GROUP BY day",
				$offset,
				$range->startUtc(),
				$range->endUtc()
			),
			ARRAY_A
		) ?: array();

		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['day'] ] = (int) $row['total'];
		}

		return $out;
	}

	/**
	 * Bots are stored, not dropped, so every read has to decide whether to include them.
	 */
	private function botClause( bool $includeBots ): string {
		return $includeBots ? '' : ' AND is_bot = 0';
	}

	private static function ctr( int $clicks, int $views ): float {
		if ( $views <= 0 ) {
			return 0.0;
		}

		return round( $clicks / $views, 4 );
	}

	/**
	 * Sorting happens in PHP because the result sets are small and the merge in
	 * byPost() has no single SQL statement to order.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 */
	private static function sortRows( array &$rows, string $orderby ): void {
		$comparators = array(
			'clicks'        => static fn ( $a, $b ) => $b['clicks'] <=> $a['clicks'],
			'views'         => static fn ( $a, $b ) => ( $b['views'] ?? 0 ) <=> ( $a['views'] ?? 0 ),
			'unique_clicks' => static fn ( $a, $b ) => ( $b['unique_clicks'] ?? 0 ) <=> ( $a['unique_clicks'] ?? 0 ),
			'ctr'           => static fn ( $a, $b ) => $b['ctr'] <=> $a['ctr'],
			// CTR の低い順。改善余地のある記事を先頭に持ってくるための並び。
			'ctr_asc'       => static fn ( $a, $b ) => $a['ctr'] <=> $b['ctr'],
			'last_click'    => static fn ( $a, $b ) => (string) ( $b['last_click'] ?? '' ) <=> (string) ( $a['last_click'] ?? '' ),
		);

		usort( $rows, $comparators[ $orderby ] ?? $comparators['clicks'] );
	}
}
