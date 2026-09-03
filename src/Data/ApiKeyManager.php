<?php

declare(strict_types=1);

namespace RLT\Data;

/**
 * Read-only API keys.
 *
 * These exist so statistics can be handed to Looker Studio, a spreadsheet or an
 * LLM without giving out a WordPress account. Only the hash is stored, so a
 * leaked options table does not leak working credentials.
 */
final class ApiKeyManager {

	public const OPTION = 'rlt_api_keys';
	public const HEADER = 'X-RLT-Key';

	private const PREFIX = 'rlt_';

	/**
	 * @return array{id:string, label:string, key:string, created_at:string}
	 */
	public static function create( string $label ): array {
		$key   = self::PREFIX . bin2hex( random_bytes( 24 ) );
		$id    = bin2hex( random_bytes( 4 ) );
		$now   = current_time( 'mysql', true );
		$label = sanitize_text_field( $label );

		// Re-read immediately before writing rather than relying on a value
		// captured earlier in the request: two keys created back to back (e.g.
		// two create() calls in the same admin-post request) must both survive,
		// not have the second overwrite the first with a stale array.
		$keys         = self::stored();
		$keys[ $id ]  = array(
			'id'           => $id,
			'label'        => $label,
			'hash'         => hash( 'sha256', $key ),
			'created_at'   => $now,
			'last_used_at' => null,
		);

		update_option( self::OPTION, $keys, false );

		return array(
			'id'         => $id,
			'label'      => $label,
			'key'        => $key,
			'created_at' => $now,
		);
	}

	/**
	 * Keys as safe to display: no plaintext, no hash.
	 *
	 * @return array<int, array{id:string, label:string, created_at:string, last_used_at:?string}>
	 */
	public static function all(): array {
		$out = array();

		foreach ( self::stored() as $record ) {
			$out[] = array(
				'id'           => (string) ( $record['id'] ?? '' ),
				'label'        => (string) ( $record['label'] ?? '' ),
				'created_at'   => (string) ( $record['created_at'] ?? '' ),
				'last_used_at' => $record['last_used_at'] ?? null,
			);
		}

		return $out;
	}

	/**
	 * @return array{id:string, label:string}|null
	 */
	public static function verify( string $key ): ?array {
		if ( '' === $key ) {
			return null;
		}

		$hash = hash( 'sha256', $key );
		$keys = self::stored();

		foreach ( $keys as $id => $record ) {
			// A record without a hash (corrupt/legacy data) can never match; skip it
			// rather than let hash_equals() coerce a missing value into a comparison.
			if ( ! isset( $record['hash'] ) || ! hash_equals( (string) $record['hash'], $hash ) ) {
				continue;
			}

			$keys[ $id ]['last_used_at'] = current_time( 'mysql', true );
			update_option( self::OPTION, $keys, false );

			return array(
				'id'    => (string) $record['id'],
				'label' => (string) $record['label'],
			);
		}

		return null;
	}

	public static function revoke( string $id ): bool {
		$keys = self::stored();

		if ( ! isset( $keys[ $id ] ) ) {
			return false;
		}

		unset( $keys[ $id ] );
		update_option( self::OPTION, $keys, false );

		return true;
	}

	public static function deleteAll(): void {
		delete_option( self::OPTION );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function stored(): array {
		$keys = get_option( self::OPTION, array() );

		return is_array( $keys ) ? $keys : array();
	}
}
