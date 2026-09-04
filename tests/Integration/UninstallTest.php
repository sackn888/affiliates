<?php

namespace RLT\Tests\Integration;

use RLT\Data\ApiKeyManager;
use RLT\Data\LinkRepository;
use RLT\Frontend\PostSync;
use RLT\Installer;
use RLT\Settings;
use WP_UnitTestCase;

final class UninstallTest extends WP_UnitTestCase {

	private const AFFILIATE = 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=x';

	/**
	 * Set by any test that calls Installer::uninstall() (which drops the
	 * plugin's tables) or otherwise needs real, non-temporary DDL. See
	 * allowRealDdl() and tear_down() for why this matters.
	 */
	private bool $realSchemaDdlRan = false;

	protected function setUp(): void {
		parent::setUp();
		( new PostSync() )->register();
	}

	public function tear_down(): void {
		// Installer::uninstall() drops the plugin's real tables (see
		// allowRealDdl() below for why the drop must be real, not the
		// TEMPORARY-table rewrite WP_UnitTestCase normally applies to DDL).
		// Recreate them here so every later test class still finds the schema
		// it expects.
		if ( ! Installer::tablesExist() ) {
			Installer::createTables();
			$this->realSchemaDdlRan = true;
		}

		if ( $this->realSchemaDdlRan ) {
			global $wpdb;

			// DROP TABLE / CREATE TABLE are DDL, which causes an implicit
			// commit and defeats WP_UnitTestCase's per-test transaction
			// rollback. The connection stays in autocommit = 0 mode after
			// that implicit commit, so a fresh implicit transaction is
			// silently open for whatever runs next -- including this
			// cleanup -- and a single ROLLBACK in the base class's
			// tear_down() would not touch it. Commit explicitly so nothing
			// here is left half-applied for the next test.
			Installer::addCapabilities();
			$wpdb->query( 'COMMIT' );
		}

		parent::tear_down();
	}

	private function postWithLink(): int {
		return self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<a href="' . self::AFFILIATE . '">ホテル</a>',
			)
		);
	}

	/**
	 * Turns off WP_UnitTestCase's rewrite of `CREATE TABLE` / `DROP TABLE`
	 * into their `TEMPORARY` equivalents, for the current test only. Without
	 * this, Installer::dropTables() (called by Installer::uninstall()) turns
	 * into `DROP TEMPORARY TABLE IF EXISTS`, which is a silent no-op against
	 * the real table created in bootstrap -- so a test asserting the tables
	 * are actually gone (via `SHOW TABLES`, which never lists TEMPORARY
	 * tables) would pass even if uninstall() never really dropped anything,
	 * and a test asserting restore-before-drop ordering would not be able to
	 * detect the order being reversed. See InstallerTest::allowRealDdl() for
	 * the identical reasoning.
	 */
	private function allowRealDdl(): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->realSchemaDdlRan = true;
	}

	public function test_restore_all_posts_puts_the_original_urls_back(): void {
		$postId = $this->postWithLink();

		$this->assertStringNotContainsString( self::AFFILIATE, get_post_field( 'post_content', $postId ) );

		$restored = Installer::restoreAllPosts();

		$this->assertSame( 1, $restored );
		$this->assertStringContainsString( self::AFFILIATE, get_post_field( 'post_content', $postId ) );
	}

	public function test_uninstall_restores_before_dropping_tables(): void {
		$this->allowRealDdl();

		$postId = $this->postWithLink();

		Installer::uninstall();

		// If the tables had been dropped first, this content would be stuck
		// holding the short URL forever: there would be nothing left to map
		// it back to the affiliate URL.
		$this->assertStringContainsString( self::AFFILIATE, get_post_field( 'post_content', $postId ) );
	}

	public function test_uninstall_drops_the_tables(): void {
		global $wpdb;

		$this->allowRealDdl();

		$this->postWithLink();

		Installer::uninstall();

		foreach ( array( Installer::linksTable(), Installer::clicksTable(), Installer::viewsTable() ) as $table ) {
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		}
	}

	public function test_uninstall_removes_the_options(): void {
		$this->allowRealDdl();

		Settings::update( array( 'prefix' => 'out' ) );
		ApiKeyManager::create( 'BI' );
		Settings::salt();

		Installer::uninstall();

		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_option( Settings::SALT_OPTION ) );
		$this->assertFalse( get_option( ApiKeyManager::OPTION ) );
		$this->assertFalse( get_option( Installer::VERSION_OPTION ) );
	}

	public function test_uninstall_removes_the_capability(): void {
		$this->allowRealDdl();

		Installer::uninstall();

		$this->assertFalse( get_role( 'administrator' )->has_cap( Installer::CAPABILITY ) );

		Installer::addCapabilities();
	}

	public function test_uninstall_removes_the_backup_post_meta(): void {
		$this->allowRealDdl();

		$postId = $this->postWithLink();

		$this->assertNotSame( '', get_post_meta( $postId, PostSync::META_ORIGINAL, true ) );

		Installer::uninstall();

		$this->assertSame( '', get_post_meta( $postId, PostSync::META_ORIGINAL, true ) );
	}
}
