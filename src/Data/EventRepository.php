<?php

declare(strict_types=1);

namespace RLT\Data;

use RLT\Installer;
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
}
