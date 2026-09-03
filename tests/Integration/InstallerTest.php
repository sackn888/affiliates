<?php

namespace RLT\Tests\Integration;

use RLT\Installer;
use WP_UnitTestCase;

final class InstallerTest extends WP_UnitTestCase {

	public function test_tables_exist_after_activation(): void {
		global $wpdb;

		foreach ( array( Installer::linksTable(), Installer::clicksTable(), Installer::viewsTable() ) as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->assertSame( $table, $found, "Table {$table} is missing." );
		}
	}

	public function test_table_names_use_the_wordpress_prefix(): void {
		global $wpdb;

		$this->assertSame( $wpdb->prefix . 'rlt_links', Installer::linksTable() );
		$this->assertSame( $wpdb->prefix . 'rlt_clicks', Installer::clicksTable() );
		$this->assertSame( $wpdb->prefix . 'rlt_views', Installer::viewsTable() );
	}

	public function test_links_table_has_the_expected_columns(): void {
		global $wpdb;

		$columns = $wpdb->get_col( 'DESC ' . Installer::linksTable(), 0 );

		foreach ( array( 'id', 'code', 'target_url', 'url_hash', 'post_id', 'label', 'status', 'created_at', 'updated_at' ) as $column ) {
			$this->assertContains( $column, $columns, "Column {$column} is missing from the links table." );
		}
	}

	public function test_code_is_unique(): void {
		global $wpdb;

		$table = Installer::linksTable();
		$now   = current_time( 'mysql', true );

		$wpdb->insert(
			$table,
			array(
				'code'       => 'dupdup',
				'target_url' => 'https://hb.afl.rakuten.co.jp/a',
				'url_hash'   => sha1( 'https://hb.afl.rakuten.co.jp/a' ),
				'post_id'    => 1,
				'label'      => 'A',
				'status'     => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		$wpdb->suppress_errors( true );
		$second = $wpdb->insert(
			$table,
			array(
				'code'       => 'dupdup',
				'target_url' => 'https://hb.afl.rakuten.co.jp/b',
				'url_hash'   => sha1( 'https://hb.afl.rakuten.co.jp/b' ),
				'post_id'    => 2,
				'label'      => 'B',
				'status'     => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$wpdb->suppress_errors( false );

		$this->assertFalse( $second, 'Duplicate code should be rejected by the unique index.' );
	}

	public function test_same_url_in_two_posts_is_allowed(): void {
		global $wpdb;

		$table = Installer::linksTable();
		$url   = 'https://hb.afl.rakuten.co.jp/shared';
		$now   = current_time( 'mysql', true );

		$row = static function ( string $code, int $postId ) use ( $url, $now ): array {
			return array(
				'code'       => $code,
				'target_url' => $url,
				'url_hash'   => sha1( $url ),
				'post_id'    => $postId,
				'label'      => 'Shared',
				'status'     => 1,
				'created_at' => $now,
				'updated_at' => $now,
			);
		};

		$this->assertSame( 1, $wpdb->insert( $table, $row( 'aaaaaa', 11 ) ) );
		$this->assertSame( 1, $wpdb->insert( $table, $row( 'bbbbbb', 12 ) ) );
	}

	public function test_same_url_in_the_same_post_is_rejected(): void {
		global $wpdb;

		$table = Installer::linksTable();
		$url   = 'https://hb.afl.rakuten.co.jp/once';
		$now   = current_time( 'mysql', true );

		$row = static function ( string $code ) use ( $url, $now ): array {
			return array(
				'code'       => $code,
				'target_url' => $url,
				'url_hash'   => sha1( $url ),
				'post_id'    => 21,
				'label'      => 'Once',
				'status'     => 1,
				'created_at' => $now,
				'updated_at' => $now,
			);
		};

		$wpdb->insert( $table, $row( 'cccccc' ) );

		$wpdb->suppress_errors( true );
		$second = $wpdb->insert( $table, $row( 'dddddd' ) );
		$wpdb->suppress_errors( false );

		$this->assertFalse( $second, 'The (url_hash, post_id) unique index should reject this.' );
	}

	public function test_activation_stores_the_db_version(): void {
		update_option( Installer::VERSION_OPTION, '0.0.1' );

		Installer::maybeUpgrade();

		$this->assertSame( Installer::DB_VERSION, get_option( Installer::VERSION_OPTION ) );
	}

	public function test_administrator_gets_the_capability(): void {
		Installer::addCapabilities();

		$role = get_role( 'administrator' );
		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( Installer::CAPABILITY ) );
	}

	public function test_tables_exist_reports_true_when_all_three_are_present(): void {
		$this->assertTrue( Installer::tablesExist() );
	}

	public function test_maybe_upgrade_recreates_a_dropped_table(): void {
		global $wpdb;

		// バージョンは一致したまま、テーブルだけが失われた状態。
		// バックアップからwp_optionsごと復元したサイトで実際に起きる。
		update_option( Installer::VERSION_OPTION, Installer::DB_VERSION );
		delete_transient( 'rlt_schema_checked' );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Installer::viewsTable() );

		$this->assertFalse( Installer::tablesExist() );

		Installer::maybeUpgrade();

		$this->assertTrue( Installer::tablesExist() );
	}

	public function test_maybe_upgrade_skips_the_check_while_the_transient_is_set(): void {
		global $wpdb;

		update_option( Installer::VERSION_OPTION, Installer::DB_VERSION );
		set_transient( 'rlt_schema_checked', 1, 12 * HOUR_IN_SECONDS );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Installer::viewsTable() );

		Installer::maybeUpgrade();

		// トランジェントが立っている間は問い合わせ自体を省くため、復旧しないのが正しい。
		$this->assertFalse( Installer::tablesExist() );

		// 後続のテストのために元に戻す。
		delete_transient( 'rlt_schema_checked' );
		Installer::createTables();
	}

	public function set_up(): void {
		parent::set_up();

		// WP_UnitTestCase wraps every test in a transaction and, to keep tests from
		// touching the real schema, silently rewrites `CREATE TABLE` / `DROP TABLE`
		// queries into their `TEMPORARY` equivalents (see start_transaction() in
		// WP core's own test suite). The tests in this class intentionally drop and
		// recreate a REAL table to exercise maybeUpgrade()'s self-healing path, so
		// that rewriting has to be turned off here.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	public function tear_down(): void {
		// DROP TABLE / CREATE TABLE are DDL, which causes an implicit commit and
		// therefore escapes the per-test transaction rollback WP_UnitTestCase relies
		// on. Restore the schema whenever a test in this class left a table missing,
		// so later tests never see a state left behind by the DDL tests above.
		//
		// This check is deliberately conditional: calling createTables() itself runs
		// dbDelta, which is DDL and would implicit-commit the current test's own
		// pending changes even when nothing needs to change — silently breaking the
		// rollback for every other test in the class, not just the DDL ones.
		if ( ! Installer::tablesExist() ) {
			Installer::createTables();
		}

		delete_transient( 'rlt_schema_checked' );

		parent::tear_down();
	}
}
