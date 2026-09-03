<?php

declare(strict_types=1);

namespace RLT\Data;

use RLT\Installer;
use RLT\Settings;
use RLT\Support\CodeGenerator;

/**
 * Reads and writes tracked links.
 */
final class LinkRepository {

	/**
	 * How many times to retry when a generated code collides with an existing one.
	 */
	private const CODE_ATTEMPTS = 10;

	private const WRITABLE_FIELDS = array( 'target_url', 'label', 'status', 'post_id' );

	private \wpdb $db;

	public function __construct() {
		global $wpdb;

		$this->db = $wpdb;
	}

	public function findByCode( string $code ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM ' . Installer::linksTable() . ' WHERE code = %s', $code ),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	public function findById( int $id ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM ' . Installer::linksTable() . ' WHERE id = %d', $id ),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	public function findByUrlAndPost( string $url, int $postId ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . Installer::linksTable() . ' WHERE url_hash = %s AND post_id = %d',
				sha1( $url ),
				$postId
			),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Return the link for this URL/post pair, creating it if needed.
	 *
	 * An existing row is reactivated and relabelled rather than duplicated, so a
	 * link that was removed from the post and later added back keeps its code and
	 * its click history.
	 */
	public function findOrCreate( string $url, int $postId, string $label ): ?array {
		$existing = $this->findByUrlAndPost( $url, $postId );

		if ( null !== $existing ) {
			if ( 1 !== $existing['status'] || $existing['label'] !== $label ) {
				$this->update(
					$existing['id'],
					array(
						'status' => 1,
						'label'  => $label,
					)
				);

				return $this->findById( $existing['id'] );
			}

			return $existing;
		}

		$now = current_time( 'mysql', true );

		// Errors are expected here: a code collision makes insert() fail and we
		// retry. Suppress them for the duration of the loop only, and restore
		// the previous state afterwards -- on the success path, on every retry,
		// and if all attempts are exhausted -- so a caller's own error-reporting
		// setting is never left altered by this call.
		$previousSuppress = $this->db->suppress_errors( true );

		try {
			for ( $attempt = 0; $attempt < self::CODE_ATTEMPTS; $attempt++ ) {
				$inserted = $this->db->insert(
					Installer::linksTable(),
					array(
						'code'       => CodeGenerator::generate(),
						'target_url' => $url,
						'url_hash'   => sha1( $url ),
						'post_id'    => $postId,
						'label'      => $label,
						'status'     => 1,
						'created_at' => $now,
						'updated_at' => $now,
					),
					array( '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
				);

				if ( false !== $inserted ) {
					return $this->findById( (int) $this->db->insert_id );
				}

				// Another request may have inserted the same URL/post pair meanwhile.
				$raced = $this->findByUrlAndPost( $url, $postId );
				if ( null !== $raced ) {
					return $raced;
				}
			}

			return null;
		} finally {
			$this->db->suppress_errors( $previousSuppress );
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function findByPost( int $postId, bool $activeOnly = true ): array {
		$sql = 'SELECT * FROM ' . Installer::linksTable() . ' WHERE post_id = %d';

		if ( $activeOnly ) {
			$sql .= ' AND status = 1';
		}

		$sql .= ' ORDER BY id ASC';

		$rows = $this->db->get_results( $this->db->prepare( $sql, $postId ), ARRAY_A ) ?: array();

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	public function countActiveForPost( int $postId ): int {
		return (int) $this->db->get_var(
			$this->db->prepare(
				'SELECT COUNT(*) FROM ' . Installer::linksTable() . ' WHERE post_id = %d AND status = 1',
				$postId
			)
		);
	}

	/**
	 * Archive every active link of a post except the given ids.
	 *
	 * Rows are archived rather than deleted: their click history must survive, and
	 * the short URL may already be shared somewhere outside the post. The
	 * `post_id = %d` clause is what keeps this scoped to one post -- a caller
	 * passing ids that belong to a different post cannot affect that post's rows,
	 * because the NOT IN() only excludes matching ids from an already
	 * post_id-filtered set.
	 *
	 * @param int[] $keepIds
	 * @return int Number of rows archived.
	 */
	public function archiveOthers( int $postId, array $keepIds ): int {
		$table = Installer::linksTable();
		$now   = current_time( 'mysql', true );

		$keepIds = array_values( array_unique( array_map( 'intval', $keepIds ) ) );

		if ( array() === $keepIds ) {
			$sql = $this->db->prepare(
				"UPDATE {$table} SET status = 0, updated_at = %s WHERE post_id = %d AND status = 1",
				$now,
				$postId
			);
		} else {
			$placeholders = implode( ',', array_fill( 0, count( $keepIds ), '%d' ) );
			$sql          = $this->db->prepare(
				"UPDATE {$table} SET status = 0, updated_at = %s WHERE post_id = %d AND status = 1 AND id NOT IN ({$placeholders})",
				array_merge( array( $now, $postId ), $keepIds )
			);
		}

		return (int) $this->db->query( $sql );
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	public function update( int $id, array $fields ): bool {
		$data = array();

		foreach ( self::WRITABLE_FIELDS as $field ) {
			if ( array_key_exists( $field, $fields ) ) {
				$data[ $field ] = $fields[ $field ];
			}
		}

		if ( array() === $data ) {
			return false;
		}

		// url_hash must follow target_url or findOrCreate would start creating duplicates.
		if ( isset( $data['target_url'] ) ) {
			$data['url_hash'] = sha1( (string) $data['target_url'] );
		}

		$data['updated_at'] = current_time( 'mysql', true );

		return false !== $this->db->update( Installer::linksTable(), $data, array( 'id' => $id ) );
	}

	/**
	 * Short URL => original URL, for every link including archived ones.
	 *
	 * Archived links must be included: their short URLs may still sit in post content.
	 *
	 * A key is emitted for every prefix the site has ever used (current plus
	 * past, see Settings::allPrefixes()), all pointing at the same target URL.
	 * The prefix is configurable and may have changed since a link's short URL
	 * was embedded in a post; if this only emitted the current prefix, restoring
	 * a post that still holds an old-prefix short URL would silently do nothing.
	 *
	 * @return array<string, string>
	 */
	public function restoreMap(): array {
		$rows = $this->db->get_results( 'SELECT code, target_url FROM ' . Installer::linksTable(), ARRAY_A ) ?: array();

		$prefixes = Settings::allPrefixes();

		$map = array();
		foreach ( $rows as $row ) {
			$code   = (string) $row['code'];
			$target = (string) $row['target_url'];

			foreach ( $prefixes as $prefix ) {
				$map[ home_url( '/' . $prefix . '/' . $code ) ] = $target;
			}
		}

		return $map;
	}

	/**
	 * @return int[]
	 */
	public function postIdsWithLinks(): array {
		$ids = $this->db->get_col( 'SELECT DISTINCT post_id FROM ' . Installer::linksTable() . ' WHERE post_id > 0' ) ?: array();

		return array_map( 'intval', $ids );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		$row['id']      = (int) $row['id'];
		$row['post_id'] = (int) $row['post_id'];
		$row['status']  = (int) $row['status'];

		return $row;
	}
}
