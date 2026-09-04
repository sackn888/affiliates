<?php
/**
 * Plugin Name:       Rakuten Link Tracker
 * Description:       楽天アフィリエイトURLを短縮URLに置き換え、クリックとPVを計測します。
 * Version:           1.0.1
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

// GitHub repository ("owner/name") the self-updater checks for new releases.
// Left as the OWNER placeholder until the account name is filled in; the
// updater no-ops cleanly while it does.
define( 'RLT_GITHUB_REPO', 'OWNER/rakuten-link-tracker' );

// This plugin is installed straight from its git repository -- on shared
// hosting there is no `composer install` step, no build, no release ZIP.
// `vendor/` is git-ignored (it only ever held Composer's own autoloading
// scaffolding; the plugin has no runtime dependencies), so the repository
// checkout must be a working plugin without it. This small PSR-4 autoloader
// is what makes that true; Composer's autoloader below is kept only because
// the test suites still use it to load PHPUnit and its polyfills.
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'RLT\\';

		if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );

		$src  = realpath( __DIR__ . '/src' );
		$path = __DIR__ . '/src' . DIRECTORY_SEPARATOR . $relative . '.php';

		$resolved = realpath( $path );

		// Guard against a crafted class name resolving outside src/ (e.g. via
		// "../" segments) before ever requiring anything.
		if ( false === $src || false === $resolved || 0 !== strpos( $resolved, $src . DIRECTORY_SEPARATOR ) ) {
			return;
		}

		require $resolved;
	}
);

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

register_activation_hook( __FILE__, array( \RLT\Installer::class, 'activate' ) );
register_deactivation_hook(
	__FILE__,
	static function (): void {
		\RLT\Cron::unschedule();
		flush_rewrite_rules();
	}
);

\RLT\Plugin::instance()->boot();
