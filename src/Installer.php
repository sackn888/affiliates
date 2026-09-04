<?php

declare(strict_types=1);

namespace RLT;

use RLT\Data\ApiKeyManager;
use RLT\Data\LinkRepository;
use RLT\Frontend\PostSync;

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

	/**
	 * How long a successful schema check is trusted before it is repeated.
	 */
	private const SCHEMA_CHECK_TRANSIENT = 'rlt_schema_checked';

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

		Cron::schedule();

		// The /go/{code} rule is registered on init; flush so it takes effect now.
		flush_rewrite_rules();
	}

	/**
	 * Runs on every load. Cheap when the version already matches.
	 */
	public static function maybeUpgrade(): void {
		if ( get_option( self::VERSION_OPTION ) === self::DB_VERSION ) {
			// The version matches, but that alone doesn't prove the tables are
			// still there: a site restored from a backup can bring back
			// wp_options (and so this matching version) without the plugin's
			// custom tables, or an admin can drop a table by hand. Verify the
			// schema before trusting the version number.
			//
			// A `SHOW TABLES` round trip on every single request is wasted cost
			// for the overwhelmingly common case where nothing is wrong, so the
			// result of a successful check is cached in a transient and only
			// re-checked twice a day.
			if ( false !== get_transient( self::SCHEMA_CHECK_TRANSIENT ) ) {
				return;
			}

			if ( self::tablesExist() ) {
				set_transient( self::SCHEMA_CHECK_TRANSIENT, 1, 12 * HOUR_IN_SECONDS );
				return;
			}

			error_log( '[rakuten-link-tracker] Installer: one or more tables were missing despite a matching DB version; recreating.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			self::createAndVerifySchema();

			return;
		}

		self::createAndVerifySchema();
		self::addCapabilities();
		update_option( self::VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Creates the schema and only trusts it once tablesExist() confirms it,
	 * caching that confirmation in the schema-check transient. Shared by both
	 * branches of maybeUpgrade() so an upgrade that only partly succeeds is
	 * never silently trusted for the next 12 hours -- the same guarantee the
	 * matching-version branch already had.
	 */
	private static function createAndVerifySchema(): void {
		self::createTables();

		if ( self::tablesExist() ) {
			set_transient( self::SCHEMA_CHECK_TRANSIENT, 1, 12 * HOUR_IN_SECONDS );
			return;
		}

		error_log( '[rakuten-link-tracker] Installer: createTables() did not produce all expected tables; the schema check transient was not set.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Whether all three of the plugin's tables are present in the database.
	 */
	public static function tablesExist(): bool {
		global $wpdb;

		foreach ( array( self::linksTable(), self::clicksTable(), self::viewsTable() ) as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			if ( $found !== $table ) {
				return false;
			}
		}

		return true;
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

	/**
	 * Put the original affiliate URLs back into every post the plugin touched.
	 *
	 * @return int Number of posts changed.
	 */
	public static function restoreAllPosts(): int {
		$links    = new LinkRepository();
		$sync     = new PostSync( $links );
		$restored = 0;

		foreach ( $links->postIdsWithLinks() as $postId ) {
			if ( $sync->restorePost( $postId ) ) {
				$restored++;
			}
		}

		return $restored;
	}

	/**
	 * Remove every trace of the plugin.
	 *
	 * Restoring the post content has to happen FIRST: once the links table is
	 * gone there is no way to map a short URL back to its affiliate URL, and
	 * every /go/ link in every post would be permanently dead.
	 */
	public static function uninstall(): void {
		global $wpdb;

		self::restoreAllPosts();

		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => PostSync::META_ORIGINAL ) );

		self::dropTables();
		self::removeCapabilities();

		Cron::unschedule();

		delete_option( Settings::OPTION );
		delete_option( Settings::SALT_OPTION );
		delete_option( ApiKeyManager::OPTION );
		delete_option( self::VERSION_OPTION );
		delete_transient( 'rlt_new_api_key' );
	}
}
