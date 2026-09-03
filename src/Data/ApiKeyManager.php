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
		$now   = current_time( 'mysql', true );
		$label = sanitize_text_field( $label );

		// Re-read immediately before writing rather than relying on a value
		// captured earlier in the request: two keys created back to back (e.g.
		// two create() calls in the same admin-post request) must both survive,
		// not have the second overwrite the first with a stale array.
		$keys = self::stored();

		// 64 bits of id makes an accidental collision effectively impossible, but
		// an id must never be allowed to silently overwrite an existing record:
		// that would destroy the earlier key's hash (it stops verifying, with no
		// error shown to whoever holds it) and misdirect revoke() at the wrong
		// record. Regenerate on collision instead, bounded so a broken RNG can't
		// spin forever.
		$attempts = 0;
		do {
			$id = bin2hex( random_bytes( 8 ) );
			++$attempts;
		} while ( isset( $keys[ $id ] ) && $attempts < 5 );

		$keys[ $id ] = array(
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

			if ( ! self::touchLastUsed( $id ) ) {
				// A concurrent revoke() removed this id between our read above and
				// the write below; treat the key as unauthenticated rather than
				// writing back a stale snapshot that would undo the revoke.
				return null;
			}

			return array(
				'id'    => (string) $record['id'],
				'label' => (string) $record['label'],
			);
		}

		return null;
	}

	/**
	 * Sets last_used_at for $id, re-reading the stored option as late as
	 * possible — from inside the pre_update_option filter that update_option()
	 * itself fires, right before the write — rather than reusing a value read
	 * earlier in this request. A revoke() can complete (its own read, unset and
	 * write) at any point up to that instant, including nested inside this very
	 * update_option() call via another callback on the same filter; reusing an
	 * earlier snapshot would silently resurrect a key an admin just revoked.
	 *
	 * @return bool False if $id was no longer present at write time (revoked
	 *              concurrently), true if last_used_at was updated.
	 */
	private static function touchLastUsed( string $id ): bool {
		$now      = current_time( 'mysql', true );
		$found    = false;
		// The value we are asking update_option() to write. pre_update_option
		// fires for *any* update_option() call on this option, including one
		// nested inside another callback on this same hook (e.g. a revoke()
		// that a concurrent request runs, which — in the worst case a test can
		// force deterministically — happens synchronously in between). Only the
		// invocation carrying this exact, unmodified value is ours; any other
		// invocation belongs to that nested call and must be left untouched.
		$intended = self::stored();

		$filter = static function ( $value ) use ( $id, $now, &$found, $intended ) {
			if ( $value !== $intended ) {
				return $value;
			}

			$fresh = self::stored();

			if ( ! isset( $fresh[ $id ] ) ) {
				// Revoked concurrently (by now-committed nested write above):
				// write back the current, id-less reality, not the stale $value.
				return $fresh;
			}

			$found                        = true;
			$fresh[ $id ]['last_used_at'] = $now;

			return $fresh;
		};

		add_filter( 'pre_update_option_' . self::OPTION, $filter );
		update_option( self::OPTION, $intended, false );
		remove_filter( 'pre_update_option_' . self::OPTION, $filter );

		return $found;
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
