<?php

namespace RLT\Tests\Integration;

use RLT\Plugin;
use RLT\Updater;
use WP_UnitTestCase;

final class UpdaterTest extends WP_UnitTestCase {

	private const TRANSIENT = 'rlt_updater_remote_check';

	private int $httpCalls = 0;
	private ?string $lastRequestedUrl = null;

	protected function setUp(): void {
		parent::setUp();
		$this->httpCalls       = 0;
		$this->lastRequestedUrl = null;
		delete_transient( self::TRANSIENT );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		delete_transient( self::TRANSIENT );
		parent::tear_down();
	}

	/**
	 * Stubs wp_remote_get() via pre_http_request so no test ever hits the
	 * real network, and counts how many times (and with which URL) it was
	 * invoked.
	 */
	private function stubHttp( string $body, int $code = 200, bool $asError = false ): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $body, $code, $asError ) {
				++$this->httpCalls;
				$this->lastRequestedUrl = $url;

				if ( $asError ) {
					return new \WP_Error( 'http_request_failed', 'boom' );
				}

				return array(
					'headers'  => array(),
					'body'     => $body,
					'response' => array(
						'code'    => $code,
						'message' => '',
					),
				);
			},
			10,
			3
		);
	}

	private function realisticHeader( string $version ): string {
		return <<<PHP
<?php
/**
 * Plugin Name:       Rakuten Link Tracker
 * Description:       楽天アフィリエイトURLを短縮URLに置き換え、クリックとPVを計測します。
 * Version:           {$version}
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            yusaku
 * License:           GPL-2.0-or-later
 * Text Domain:       rakuten-link-tracker
 */
PHP;
	}

	// -- remoteVersion() -----------------------------------------------

	public function test_remote_version_parses_a_realistic_header(): void {
		$this->stubHttp( $this->realisticHeader( '9.9.9' ) );

		$updater = new Updater( 'octocat/rakuten-link-tracker' );

		$this->assertSame( '9.9.9', $updater->remoteVersion() );
	}

	public function test_remote_version_requests_the_raw_url_at_the_repository_root_when_no_path_is_configured(): void {
		$this->stubHttp( $this->realisticHeader( '9.9.9' ) );

		$updater = new Updater( 'octocat/rakuten-link-tracker', 'master' );
		$updater->remoteVersion();

		$this->assertSame(
			'https://raw.githubusercontent.com/octocat/rakuten-link-tracker/master/rakuten-link-tracker.php',
			$this->lastRequestedUrl
		);
	}

	public function test_remote_version_requests_the_raw_url_including_the_in_repo_path(): void {
		$this->stubHttp( $this->realisticHeader( '9.9.9' ) );

		$updater = new Updater( 'sackn888/affiliates', 'main', 'rakuten-link-tracker' );
		$updater->remoteVersion();

		$this->assertSame(
			'https://raw.githubusercontent.com/sackn888/affiliates/main/rakuten-link-tracker/rakuten-link-tracker.php',
			$this->lastRequestedUrl
		);
	}

	public function test_remote_version_is_null_on_wp_error(): void {
		$this->stubHttp( '', 200, true );

		$updater = new Updater( 'octocat/rakuten-link-tracker' );

		$this->assertNull( $updater->remoteVersion() );
	}

	public function test_remote_version_is_null_on_non_200(): void {
		$this->stubHttp( $this->realisticHeader( '9.9.9' ), 404 );

		$updater = new Updater( 'octocat/rakuten-link-tracker' );

		$this->assertNull( $updater->remoteVersion() );
	}

	public function test_remote_version_is_null_when_no_version_line(): void {
		$this->stubHttp( "<?php\n// no header here\n" );

		$updater = new Updater( 'octocat/rakuten-link-tracker' );

		$this->assertNull( $updater->remoteVersion() );
	}

	public function test_remote_version_caches_success_and_skips_a_second_request(): void {
		$this->stubHttp( $this->realisticHeader( '9.9.9' ) );

		$updater = new Updater( 'octocat/rakuten-link-tracker' );

		$this->assertSame( '9.9.9', $updater->remoteVersion() );
		$this->assertSame( '9.9.9', $updater->remoteVersion() );
		$this->assertSame( 1, $this->httpCalls );
	}

	public function test_remote_version_caches_failure_and_skips_a_second_request(): void {
		$this->stubHttp( '', 500 );

		$updater = new Updater( 'octocat/rakuten-link-tracker' );

		$this->assertNull( $updater->remoteVersion() );
		$this->assertNull( $updater->remoteVersion() );
		$this->assertSame( 1, $this->httpCalls );
	}

	// -- checkForUpdate() ------------------------------------------------

	public function test_check_for_update_adds_an_entry_when_remote_is_newer(): void {
		$this->stubHttp( $this->realisticHeader( '999.0.0' ) );

		$updater  = new Updater( 'octocat/rakuten-link-tracker' );
		$basename = plugin_basename( RLT_PLUGIN_FILE );

		$transient = new \stdClass();
		$result    = $updater->checkForUpdate( $transient );

		$this->assertTrue( isset( $result->response[ $basename ] ) );
		$entry = $result->response[ $basename ];
		$this->assertSame( '999.0.0', $entry->new_version );
		$this->assertSame( $basename, $entry->plugin );
		$this->assertSame( dirname( $basename ), $entry->slug );
		$this->assertSame(
			'https://github.com/octocat/rakuten-link-tracker/archive/refs/heads/master.zip',
			$entry->package
		);
	}

	public function test_check_for_update_adds_nothing_to_response_when_remote_equals_current(): void {
		$this->stubHttp( $this->realisticHeader( Plugin::VERSION ) );

		$updater  = new Updater( 'octocat/rakuten-link-tracker' );
		$basename = plugin_basename( RLT_PLUGIN_FILE );

		$transient = new \stdClass();
		$result    = $updater->checkForUpdate( $transient );

		$this->assertFalse( isset( $result->response[ $basename ] ) );
	}

	/**
	 * Defect: WordPress only offers the auto-update toggle for a plugin that
	 * appears in either $transient->response or $transient->no_update. A
	 * plugin that is already current -- the common case -- was previously
	 * left out of both, so the toggle never appeared. See the comment in
	 * Updater::checkForUpdate() for the exact core code path.
	 */
	public function test_check_for_update_adds_a_no_update_entry_when_remote_equals_current(): void {
		$this->stubHttp( $this->realisticHeader( Plugin::VERSION ) );

		$updater  = new Updater( 'octocat/rakuten-link-tracker' );
		$basename = plugin_basename( RLT_PLUGIN_FILE );

		$transient = new \stdClass();
		$result    = $updater->checkForUpdate( $transient );

		$this->assertTrue( isset( $result->no_update[ $basename ] ) );
		$entry = $result->no_update[ $basename ];
		$this->assertSame( Plugin::VERSION, $entry->new_version );
		$this->assertSame( $basename, $entry->plugin );
		$this->assertSame( dirname( $basename ), $entry->slug );
	}

	public function test_check_for_update_adds_nothing_to_response_when_remote_is_older(): void {
		$this->stubHttp( $this->realisticHeader( '0.0.1' ) );

		$updater  = new Updater( 'octocat/rakuten-link-tracker' );
		$basename = plugin_basename( RLT_PLUGIN_FILE );

		$transient = new \stdClass();
		$result    = $updater->checkForUpdate( $transient );

		$this->assertFalse( isset( $result->response[ $basename ] ) );
	}

	public function test_check_for_update_adds_a_no_update_entry_when_remote_is_older(): void {
		$this->stubHttp( $this->realisticHeader( '0.0.1' ) );

		$updater  = new Updater( 'octocat/rakuten-link-tracker' );
		$basename = plugin_basename( RLT_PLUGIN_FILE );

		$transient = new \stdClass();
		$result    = $updater->checkForUpdate( $transient );

		$this->assertTrue( isset( $result->no_update[ $basename ] ) );
	}

	public function test_check_for_update_adds_neither_entry_when_the_remote_fetch_fails(): void {
		$this->stubHttp( '', 500 );

		$updater  = new Updater( 'octocat/rakuten-link-tracker' );
		$basename = plugin_basename( RLT_PLUGIN_FILE );

		$transient = new \stdClass();
		$result    = $updater->checkForUpdate( $transient );

		$this->assertFalse( isset( $result->response[ $basename ] ) );
		$this->assertFalse( isset( $result->no_update[ $basename ] ) );
	}

	public function test_check_for_update_adds_neither_entry_while_unconfigured(): void {
		$updater  = new Updater( 'OWNER/rakuten-link-tracker' );
		$basename = plugin_basename( RLT_PLUGIN_FILE );

		$transient = new \stdClass();
		$result    = $updater->checkForUpdate( $transient );

		$this->assertFalse( isset( $result->response[ $basename ] ) );
		$this->assertFalse( isset( $result->no_update[ $basename ] ) );
	}

	public function test_check_for_update_leaves_other_plugins_response_entries_untouched(): void {
		$this->stubHttp( $this->realisticHeader( '999.0.0' ) );

		$updater = new Updater( 'octocat/rakuten-link-tracker' );

		$transient = new \stdClass();
		$other     = new \stdClass();
		$other->new_version = '5.0.0';
		$transient->response = array( 'other-plugin/other-plugin.php' => $other );

		$result = $updater->checkForUpdate( $transient );

		$this->assertSame( $other, $result->response['other-plugin/other-plugin.php'] );
		$this->assertTrue( isset( $result->response[ plugin_basename( RLT_PLUGIN_FILE ) ] ) );
	}

	public function test_check_for_update_leaves_other_plugins_no_update_entries_untouched(): void {
		$this->stubHttp( $this->realisticHeader( Plugin::VERSION ) );

		$updater  = new Updater( 'octocat/rakuten-link-tracker' );
		$basename = plugin_basename( RLT_PLUGIN_FILE );

		$transient = new \stdClass();
		$other     = new \stdClass();
		$other->new_version = '5.0.0';
		$transient->no_update = array( 'other-plugin/other-plugin.php' => $other );

		$result = $updater->checkForUpdate( $transient );

		$this->assertSame( $other, $result->no_update['other-plugin/other-plugin.php'] );
		$this->assertTrue( isset( $result->no_update[ $basename ] ) );
	}

	// -- fixSourceDir() ----------------------------------------------------

	public function test_fix_source_dir_renames_the_branch_archive_directory(): void {
		$updater  = new Updater( 'octocat/rakuten-link-tracker' );
		$basename = plugin_basename( RLT_PLUGIN_FILE );

		$parent = trailingslashit( get_temp_dir() ) . 'rlt-updater-test-' . uniqid() . '/';
		wp_mkdir_p( $parent );
		$source = trailingslashit( $parent . 'rakuten-link-tracker-master' );
		wp_mkdir_p( $source );
		file_put_contents( $source . 'rakuten-link-tracker.php', '<?php // marker' );

		$result = $updater->fixSourceDir(
			$source,
			$parent,
			null,
			array(
				'plugin' => $basename,
				'type'   => 'plugin',
				'action' => 'update',
			)
		);

		$expected = trailingslashit( $parent . dirname( $basename ) );

		$this->assertSame( $expected, $result );
		$this->assertDirectoryDoesNotExist( untrailingslashit( $source ) );
		$this->assertFileExists( $expected . 'rakuten-link-tracker.php' );

		$this->deleteDirRecursive( $parent );
	}

	public function test_fix_source_dir_leaves_an_unrelated_plugins_source_alone(): void {
		$updater = new Updater( 'octocat/rakuten-link-tracker' );

		$parent = trailingslashit( get_temp_dir() ) . 'rlt-updater-test-' . uniqid() . '/';
		wp_mkdir_p( $parent );
		$source = trailingslashit( $parent . 'some-other-plugin-master' );
		wp_mkdir_p( $source );

		$result = $updater->fixSourceDir(
			$source,
			$parent,
			null,
			array(
				'plugin' => 'some-other-plugin/some-other-plugin.php',
				'type'   => 'plugin',
				'action' => 'update',
			)
		);

		$this->assertSame( $source, $result );
		$this->assertDirectoryExists( untrailingslashit( $source ) );

		$this->deleteDirRecursive( $parent );
	}

	public function test_fix_source_dir_descends_into_the_configured_in_repo_path(): void {
		$updater  = new Updater( 'sackn888/affiliates', 'main', 'rakuten-link-tracker' );
		$basename = plugin_basename( RLT_PLUGIN_FILE );

		$parent = trailingslashit( get_temp_dir() ) . 'rlt-updater-test-' . uniqid() . '/';
		wp_mkdir_p( $parent );
		$source = trailingslashit( $parent . 'affiliates-main' );
		wp_mkdir_p( $source );
		$inner = trailingslashit( $source . dirname( $basename ) );
		wp_mkdir_p( $inner );
		file_put_contents( $inner . 'rakuten-link-tracker.php', '<?php // marker' );

		$result = $updater->fixSourceDir(
			untrailingslashit( $source ),
			$parent,
			null,
			array(
				'plugin' => $basename,
				'type'   => 'plugin',
				'action' => 'update',
			)
		);

		$this->assertSame( $inner, $result );
		$this->assertFileExists( $inner . 'rakuten-link-tracker.php' );

		$this->deleteDirRecursive( $parent );
	}

	public function test_fix_source_dir_returns_a_wp_error_when_the_in_repo_path_is_missing(): void {
		$updater  = new Updater( 'sackn888/affiliates', 'main', 'rakuten-link-tracker' );
		$basename = plugin_basename( RLT_PLUGIN_FILE );

		$parent = trailingslashit( get_temp_dir() ) . 'rlt-updater-test-' . uniqid() . '/';
		wp_mkdir_p( $parent );
		// The archive root exists, but the expected in-repo plugin directory
		// inside it does not -- e.g. the path was misconfigured or the
		// repository's layout changed.
		$source = trailingslashit( $parent . 'affiliates-main' );
		wp_mkdir_p( $source );

		$result = $updater->fixSourceDir(
			untrailingslashit( $source ),
			$parent,
			null,
			array(
				'plugin' => $basename,
				'type'   => 'plugin',
				'action' => 'update',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rlt_updater_missing_plugin_dir', $result->get_error_code() );

		$this->deleteDirRecursive( $parent );
	}

	private function deleteDirRecursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $path ) ) {
				$this->deleteDirRecursive( $path );
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}

		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	// -- adminNotice() -------------------------------------------------

	public function test_admin_notice_warns_when_the_last_check_failed(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->stubHttp( '', 500 );

		$updater = new Updater( 'octocat/rakuten-link-tracker' );
		$updater->remoteVersion();

		ob_start();
		$updater->adminNotice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );
	}

	public function test_admin_notice_is_silent_for_users_who_cannot_update_plugins(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$this->stubHttp( '', 500 );

		$updater = new Updater( 'octocat/rakuten-link-tracker' );
		$updater->remoteVersion();

		$this->expectOutputString( '' );
		$updater->adminNotice();
	}

	public function test_admin_notice_is_silent_when_nothing_has_been_checked_yet(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$updater = new Updater( 'octocat/rakuten-link-tracker' );

		$this->expectOutputString( '' );
		$updater->adminNotice();
	}

	// -- OWNER placeholder ---------------------------------------------

	public function test_updater_is_a_no_op_while_the_repo_still_contains_owner_placeholder(): void {
		$this->stubHttp( $this->realisticHeader( '999.0.0' ) );

		$updater = new Updater( 'OWNER/rakuten-link-tracker' );

		$this->assertNull( $updater->remoteVersion() );
		$this->assertSame( 0, $this->httpCalls );

		$transient = new \stdClass();
		$result    = $updater->checkForUpdate( $transient );
		$this->assertSame( $transient, $result );
		$this->assertFalse( isset( $result->response ) );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->expectOutputString( '' );
		$updater->adminNotice();

		$updater->register();
		$this->assertFalse( has_action( 'admin_notices', array( $updater, 'adminNotice' ) ) );
		$this->assertFalse( has_filter( 'pre_set_site_transient_update_plugins', array( $updater, 'checkForUpdate' ) ) );
	}

	// -- autoloading without Composer -----------------------------------

	public function test_rlt_autoloader_resolves_a_class_without_composer(): void {
		$composerLoader = null;

		foreach ( spl_autoload_functions() as $callable ) {
			if ( is_array( $callable ) && isset( $callable[0] ) && is_object( $callable[0] )
				&& false !== strpos( get_class( $callable[0] ), 'ComposerAutoloaderInit' )
			) {
				$composerLoader = $callable;
			} elseif ( is_array( $callable ) && isset( $callable[0] ) && is_object( $callable[0] )
				&& method_exists( $callable[0], 'loadClass' )
				&& false !== strpos( get_class( $callable[0] ), 'ClassLoader' )
			) {
				$composerLoader = $callable;
			}
		}

		if ( null !== $composerLoader ) {
			spl_autoload_unregister( $composerLoader );
		}

		try {
			$this->assertFalse( class_exists( 'RLT\\Support\\AutoloadProbe', false ) );
			$this->assertTrue( class_exists( 'RLT\\Support\\AutoloadProbe', true ) );

			$probe = new \RLT\Support\AutoloadProbe();
			$this->assertSame( 'pong', $probe->ping() );
		} finally {
			if ( null !== $composerLoader ) {
				spl_autoload_register( $composerLoader );
			}
		}
	}
}
