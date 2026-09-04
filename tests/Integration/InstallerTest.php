<?php

namespace RLT\Tests\Integration;

use RLT\Installer;
use WP_UnitTestCase;

final class InstallerTest extends WP_UnitTestCase {

	/**
	 * Set by any test that calls Installer::createTables() / maybeUpgrade().
	 *
	 * dbDelta() issues real `ALTER TABLE` statements even when the schema
	 * already matches (WordPress's temporary-table query filter only rewrites
	 * `CREATE TABLE` / `DROP TABLE`, never `ALTER TABLE`), so every such call
	 * causes a real, uncontrolled implicit commit partway through the test --
	 * not just in the two tests that deliberately drop a table. See
	 * tear_down() for how this is handled.
	 */
	private bool $realSchemaDdlRan = false;

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
		$this->realSchemaDdlRan = true;

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

		$this->allowRealDdl();

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

		$this->allowRealDdl();

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

	public function test_maybe_upgrade_sets_the_transient_after_a_version_mismatch(): void {
		update_option( Installer::VERSION_OPTION, '0.0.1' );
		delete_transient( 'rlt_schema_checked' );

		Installer::maybeUpgrade();
		$this->realSchemaDdlRan = true;

		// createTables() succeeded and tablesExist() verified it, so the version-
		// mismatch branch should now cache that result exactly like the matching-
		// version branch does, instead of trusting createTables() unconditionally.
		$this->assertNotFalse( get_transient( 'rlt_schema_checked' ) );
	}

	/**
	 * Turns off WP_UnitTestCase's rewrite of `CREATE TABLE` / `DROP TABLE` into
	 * their `TEMPORARY` equivalents (see start_transaction() in WP core's own
	 * test suite), for the current test only.
	 *
	 * Only the two tests that exercise maybeUpgrade()'s self-healing path need
	 * this: they must drop and recreate a REAL table, not a temporary one. WP
	 * core adds these filters fresh in parent::set_up(), bound to that test
	 * method's own WP_UnitTestCase instance, so removing them here does not
	 * leak into any other test in this class -- the next test method runs on a
	 * new instance with the rewrite back in place.
	 */
	private function allowRealDdl(): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->realSchemaDdlRan = true;
	}

	public function tear_down(): void {
		// DROP TABLE / CREATE TABLE / ALTER TABLE are DDL, which causes an implicit
		// commit and therefore escapes the per-test transaction rollback
		// WP_UnitTestCase relies on. Restore the schema whenever a test in this
		// class left a table missing, so later tests never see a state left behind
		// by the DDL tests above.
		//
		// This check is deliberately conditional: calling createTables() itself runs
		// dbDelta, which is DDL and would implicit-commit the current test's own
		// pending changes even when nothing needs to change — silently breaking the
		// rollback for every other test in the class, not just the DDL ones.
		if ( ! Installer::tablesExist() ) {
			Installer::createTables();
			$this->realSchemaDdlRan = true;
		}

		if ( $this->realSchemaDdlRan ) {
			global $wpdb;

			// Any call to Installer::createTables() / maybeUpgrade() in this test
			// issued a real ALTER TABLE, which implicit-commits whatever was pending
			// at that point and -- because the connection is still in
			// `autocommit = 0` mode -- silently opens a *new* implicit transaction
			// for every statement that follows, including this class's own cleanup
			// writes below. WP_UnitTestCase's tear_down() only ever issues a single
			// ROLLBACK, which would discard that new transaction (and our cleanup
			// with it), leaving whatever update_option( VERSION_OPTION, ... ) the
			// test made *before* the DDL permanently committed in the database.
			//
			// So the option/transient state has to be restored and the restoration
			// itself explicitly committed here, rather than left to rollback -- the
			// whole point being that rollback cannot be trusted once real DDL has
			// run during this test.
			delete_option( Installer::VERSION_OPTION );
			delete_transient( 'rlt_schema_checked' );
			$wpdb->query( 'COMMIT' );
		}

		parent::tear_down();
	}
}
