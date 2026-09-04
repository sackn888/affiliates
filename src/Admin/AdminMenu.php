<?php

declare(strict_types=1);

namespace RLT\Admin;

use RLT\Installer;
use RLT\Plugin;

/**
 * Registers the admin menu and routes each page to its renderer.
 */
final class AdminMenu {

	public const SLUG          = 'rakuten-link-tracker';
	public const SLUG_LINKS    = 'rlt-links';
	public const SLUG_POSTS    = 'rlt-posts';
	public const SLUG_SETTINGS = 'rlt-settings';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addPages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		( new LinkDetailPage() )->register();
	}

	public function addPages(): void {
		add_menu_page(
			__( '楽天リンク', 'rakuten-link-tracker' ),
			__( '楽天リンク', 'rakuten-link-tracker' ),
			Installer::CAPABILITY,
			self::SLUG,
			array( new DashboardPage(), 'render' ),
			'dashicons-chart-line',
			58
		);

		add_submenu_page(
			self::SLUG,
			__( 'ダッシュボード', 'rakuten-link-tracker' ),
			__( 'ダッシュボード', 'rakuten-link-tracker' ),
			Installer::CAPABILITY,
			self::SLUG,
			array( new DashboardPage(), 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'リンク一覧', 'rakuten-link-tracker' ),
			__( 'リンク一覧', 'rakuten-link-tracker' ),
			Installer::CAPABILITY,
			self::SLUG_LINKS,
			array( new LinkDetailPage(), 'route' )
		);

		add_submenu_page(
			self::SLUG,
			__( '記事別レポート', 'rakuten-link-tracker' ),
			__( '記事別レポート', 'rakuten-link-tracker' ),
			Installer::CAPABILITY,
			self::SLUG_POSTS,
			array( new PostsReportPage(), 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( '設定', 'rakuten-link-tracker' ),
			__( '設定', 'rakuten-link-tracker' ),
			'manage_options',
			self::SLUG_SETTINGS,
			array( new SettingsPage(), 'render' )
		);
	}

	public function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, self::SLUG ) && ! str_contains( $hook, 'rlt-' ) ) {
			return;
		}

		wp_enqueue_style( 'rlt-admin', Plugin::url( 'assets/admin.css' ), array(), Plugin::VERSION );
	}

	/**
	 * URL of one of the plugin's admin pages.
	 *
	 * @param array<string, string|int> $args
	 */
	public static function url( string $slug, array $args = array() ): string {
		return add_query_arg(
			array_merge( array( 'page' => $slug ), $args ),
			admin_url( 'admin.php' )
		);
	}
}
