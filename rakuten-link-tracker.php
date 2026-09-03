<?php
/**
 * Plugin Name:       Rakuten Link Tracker
 * Description:       楽天アフィリエイトURLを短縮URLに置き換え、クリックとPVを計測します。
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            yusaku
 * License:           GPL-2.0-or-later
 * Text Domain:       rakuten-link-tracker
 *
 * @package RLT
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RLT_PLUGIN_FILE', __FILE__ );

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

register_activation_hook( __FILE__, array( \RLT\Installer::class, 'activate' ) );
register_deactivation_hook(
	__FILE__,
	static function (): void {
		flush_rewrite_rules();
	}
);

\RLT\Plugin::instance()->boot();
