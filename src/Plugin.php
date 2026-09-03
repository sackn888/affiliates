<?php

declare(strict_types=1);

namespace RLT;

/**
 * Plugin bootstrap. Holds paths and the single place where hooks get registered.
 */
final class Plugin {

	public const VERSION = '1.0.0';

	private static ?Plugin $instance = null;

	private function __construct() {}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Absolute path to a file inside the plugin directory.
	 */
	public static function path( string $relative = '' ): string {
		return plugin_dir_path( RLT_PLUGIN_FILE ) . ltrim( $relative, '/' );
	}

	/**
	 * Public URL to a file inside the plugin directory.
	 */
	public static function url( string $relative = '' ): string {
		return plugin_dir_url( RLT_PLUGIN_FILE ) . ltrim( $relative, '/' );
	}

	/**
	 * Register every hook the plugin uses.
	 */
	public function boot(): void {
		add_action( 'plugins_loaded', array( Installer::class, 'maybeUpgrade' ) );

		( new \RLT\Frontend\PostSync() )->register();
		( new \RLT\Frontend\RedirectHandler() )->register();
		( new \RLT\Frontend\BeaconController() )->register();
		( new \RLT\Api\StatsController() )->register();
		( new \RLT\Api\ExportController() )->register();
		( new Cron() )->register();
	}
}
