<?php

declare(strict_types=1);

namespace RLT;

/**
 * Owns the database schema and its versioning.
 */
final class Installer {

	public const DB_VERSION     = '1.0.0';
	public const VERSION_OPTION = 'rlt_db_version';
	public const CAPABILITY     = 'rlt_view_stats';

	/**
	 * Roles that can read the plugin's statistics.
	 */
	private const ROLES = array( 'administrator', 'editor' );

	public static function linksTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'rlt_links';
	}

	public static function clicksTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'rlt_clicks';
	}

	public static function viewsTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'rlt_views';
	}

	/**
	 * Runs on plugin activation.
	 */
	public static function activate(): void {
		self::createTables();
		self::addCapabilities();
		update_option( self::VERSION_OPTION, self::DB_VERSION );

		// The /go/{code} rule is registered on init; flush so it takes effect now.
		flush_rewrite_rules();
	}

	/**
	 * Runs on every load. Cheap when the version already matches.
	 */
	public static function maybeUpgrade(): void {
		if ( get_option( self::VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::createTables();
		self::addCapabilities();
		update_option( self::VERSION_OPTION, self::DB_VERSION );
	}

	public static function createTables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$links   = self::linksTable();
		$clicks  = self::clicksTable();
		$views   = self::viewsTable();

		dbDelta(
			"CREATE TABLE {$links} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				code VARCHAR(16) NOT NULL,
				target_url TEXT NOT NULL,
				url_hash CHAR(40) NOT NULL,
				post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				label VARCHAR(255) NOT NULL DEFAULT '',
				status TINYINT NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY code (code),
				UNIQUE KEY url_post (url_hash, post_id),
				KEY post_id (post_id),
				KEY status (status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$clicks} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				link_id BIGINT UNSIGNED NOT NULL,
				post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				clicked_at DATETIME NOT NULL,
				visitor_hash CHAR(64) NOT NULL DEFAULT '',
				referer VARCHAR(255) NOT NULL DEFAULT '',
				device TINYINT NOT NULL DEFAULT 0,
				is_bot TINYINT NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY link_time (link_id, clicked_at),
				KEY post_time (post_id, clicked_at),
				KEY dedup (visitor_hash, link_id, clicked_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$views} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				post_id BIGINT UNSIGNED NOT NULL,
				viewed_at DATETIME NOT NULL,
				visitor_hash CHAR(64) NOT NULL DEFAULT '',
				referer VARCHAR(255) NOT NULL DEFAULT '',
				device TINYINT NOT NULL DEFAULT 0,
				is_bot TINYINT NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY post_time (post_id, viewed_at),
				KEY dedup (visitor_hash, post_id, viewed_at)
			) {$charset};"
		);
	}

	public static function dropTables(): void {
		global $wpdb;

		foreach ( array( self::clicksTable(), self::viewsTable(), self::linksTable() ) as $table ) {
			// Table names come from $wpdb->prefix, never from user input.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}

	public static function addCapabilities(): void {
		foreach ( self::ROLES as $roleName ) {
			$role = get_role( $roleName );

			if ( $role instanceof \WP_Role ) {
				$role->add_cap( self::CAPABILITY );
			}
		}
	}

	public static function removeCapabilities(): void {
		foreach ( self::ROLES as $roleName ) {
			$role = get_role( $roleName );

			if ( $role instanceof \WP_Role ) {
				$role->remove_cap( self::CAPABILITY );
			}
		}
	}
}
