<?php

declare(strict_types=1);

namespace RLT\Admin;

use RLT\Data\LinkRepository;
use RLT\Frontend\PostSync;

/**
 * Converts posts published before the plugin existed, and undoes the conversion
 * across the whole site.
 *
 * Work happens in small batches driven from the browser so a large archive does
 * not hit the PHP time limit.
 */
final class BulkConverter {

	public const BATCH_SIZE     = 20;
	public const ACTION_CONVERT = 'rlt_bulk_convert';
	public const ACTION_RESTORE = 'rlt_bulk_restore';
	public const NONCE          = 'rlt_bulk';

	private PostSync $sync;
	private LinkRepository $links;

	public function __construct( ?PostSync $sync = null, ?LinkRepository $links = null ) {
		$this->links = $links ?? new LinkRepository();
		$this->sync  = $sync ?? new PostSync( $this->links );
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION_CONVERT, array( $this, 'ajaxConvert' ) );
		add_action( 'wp_ajax_' . self::ACTION_RESTORE, array( $this, 'ajaxRestore' ) );
	}

	/**
	 * @return array{offset:int, processed:int, changed:int, total:int, done:bool}
	 */
	public function convertBatch( int $offset ): array {
		$ids     = $this->candidateIds();
		$batch   = array_slice( $ids, $offset, self::BATCH_SIZE );
		$changed = 0;

		foreach ( $batch as $postId ) {
			if ( $this->sync->syncPost( $postId ) ) {
				$changed++;
			}
		}

		return $this->progress( $offset, count( $batch ), $changed, count( $ids ) );
	}

	/**
	 * @return array{offset:int, processed:int, changed:int, total:int, done:bool}
	 */
	public function restoreBatch( int $offset ): array {
		$ids = $this->links->postIdsWithLinks();
		sort( $ids );
		$batch   = array_slice( $ids, $offset, self::BATCH_SIZE );
		$changed = 0;

		foreach ( $batch as $postId ) {
			if ( $this->sync->restorePost( $postId ) ) {
				$changed++;
			}
		}

		return $this->progress( $offset, count( $batch ), $changed, count( $ids ) );
	}

	public function ajaxConvert(): void {
		$this->respond( 'convertBatch' );
	}

	public function ajaxRestore(): void {
		$this->respond( 'restoreBatch' );
	}

	private function respond( string $method ): void {
		check_ajax_referer( self::NONCE );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '権限がありません。', 'rakuten-link-tracker' ) ), 403 );
		}

		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;

		wp_send_json_success( $this->{$method}( $offset ) );
	}

	/**
	 * Posts of the synced types, oldest first so the offset stays stable between
	 * batches.
	 *
	 * @return int[]
	 */
	private function candidateIds(): array {
		return get_posts(
			array(
				'post_type'        => $this->sync->syncedPostTypes(),
				'post_status'      => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
	}

	/**
	 * @return array{offset:int, processed:int, changed:int, total:int, done:bool}
	 */
	private function progress( int $offset, int $processed, int $changed, int $total ): array {
		$next = $offset + $processed;

		return array(
			'offset'    => $next,
			'processed' => $processed,
			'changed'   => $changed,
			'total'     => $total,
			'done'      => $next >= $total || 0 === $processed,
		);
	}
}
