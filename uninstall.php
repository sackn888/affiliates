<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * @package RLT
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

if ( ! defined( 'RLT_PLUGIN_FILE' ) ) {
	define( 'RLT_PLUGIN_FILE', __DIR__ . '/rakuten-link-tracker.php' );
}

\RLT\Installer::uninstall();
