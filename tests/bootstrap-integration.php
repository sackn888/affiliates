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
		require dirname( __DIR__ ) . '/rakuten-link-tracker.php';
	}
);

require $rlt_tests_dir . '/includes/bootstrap.php';

// Create the plugin's tables once, before any test transaction starts.
\RLT\Installer::createTables();
\RLT\Installer::addCapabilities();
