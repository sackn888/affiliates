<?php
/**
 * Bootstrap for WordPress integration tests (run inside wp-env's tests-cli).
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$rlt_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $rlt_tests_dir ) {
	$rlt_tests_dir = '/wordpress-phpunit';
}

require_once $rlt_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		// Load the copy installed at wp-content/plugins/rakuten-link-tracker
		// (not the repo-root checkout mapped in for tests/ and vendor/), so
		// that plugin_basename() and friends resolve exactly as they do on a
		// live site.
		require WP_PLUGIN_DIR . '/rakuten-link-tracker/rakuten-link-tracker.php';
	}
);

require $rlt_tests_dir . '/includes/bootstrap.php';

// Create the plugin's tables once, before any test transaction starts.
\RLT\Installer::createTables();
\RLT\Installer::addCapabilities();

// WP_UnitTestCase isolates each test in a transaction it rolls back after the
// test runs. But dbDelta() (used by Installer::createTables(), which
// InstallerTest calls directly, and which Installer::maybeUpgrade() can also
// trigger mid-suite when it finds a table missing) issues real ALTER TABLE
// statements -- DDL -- even when the schema already matches. DDL causes an
// implicit commit in MySQL, which permanently commits whatever rows were
// pending in the *current* test's transaction, defeating the rollback. A run
// that is filtered, interrupted, or fails partway through can therefore
// leave committed rows behind in the plugin's own tables. A later, unrelated
// full run then starts from a dirty database and fails with confusing
// off-by-N row-count assertions that have nothing to do with the code being
// tested.
//
// Guard against that here by starting every run from a known-clean state:
// wipe the plugin's tables and its options/transients now, before any test
// transaction has opened. TRUNCATE is itself DDL and implicit-commits too,
// but that's fine -- desirable, even -- at this point in bootstrap, since
// nothing is relying on transactional rollback yet.
global $wpdb;

foreach ( array( \RLT\Installer::linksTable(), \RLT\Installer::clicksTable(), \RLT\Installer::viewsTable() ) as $rlt_table ) {
	// Table names come from Installer's own $wpdb->prefix-based getters, never
	// from user input.
	$wpdb->query( "TRUNCATE TABLE {$rlt_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
}

delete_option( \RLT\Installer::VERSION_OPTION );
delete_option( \RLT\Settings::OPTION );
delete_option( \RLT\Settings::SALT_OPTION );
delete_transient( 'rlt_schema_checked' );

if ( class_exists( '\RLT\Data\ApiKeyManager' ) ) {
	delete_option( \RLT\Data\ApiKeyManager::OPTION );
}
