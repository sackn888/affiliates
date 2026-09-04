<?php

declare(strict_types=1);

namespace RLT\Admin;

use RLT\Data\ApiKeyManager;
use RLT\Plugin;
use RLT\Settings;

/**
 * Settings, API keys, and the bulk convert/restore controls.
 */
final class SettingsPage {

	public const NONCE = 'rlt_save_settings';

	private const NEW_KEY_TRANSIENT = 'rlt_new_api_key';

	public function register(): void {
		add_action( 'admin_post_rlt_save_settings', array( $this, 'handleSave' ) );
		add_action( 'admin_post_rlt_create_key', array( $this, 'handleCreateKey' ) );
		add_action( 'admin_post_rlt_revoke_key', array( $this, 'handleRevokeKey' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'この画面を表示する権限がありません。', 'rakuten-link-tracker' ) );
		}

		$settings = Settings::all();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( '楽天リンク 設定', 'rakuten-link-tracker' ) . '</h1>';

		$this->renderSettingsForm( $settings );
		$this->renderApiKeys();
		$this->renderBulkTools();
		$this->renderExportLinks();

		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function renderSettingsForm( array $settings ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="rlt_save_settings" />';
		wp_nonce_field( self::NONCE );

		echo '<table class="form-table"><tbody>';

		printf(
			'<tr><th scope="row"><label for="rlt-prefix">%s</label></th><td>
				<input type="text" id="rlt-prefix" name="prefix" class="regular-text" value="%s" />
				<p class="description">%s <code>%s</code></p></td></tr>',
			esc_html__( '短縮URLのプレフィックス', 'rakuten-link-tracker' ),
			esc_attr( (string) $settings['prefix'] ),
			esc_html__( '短縮URLはこの形になります:', 'rakuten-link-tracker' ),
			esc_html( Settings::shortBase() . 'abc123' )
		);

		printf(
			'<tr><th scope="row"><label for="rlt-hosts">%s</label></th><td>
				<textarea id="rlt-hosts" name="hosts" rows="4" class="large-text code">%s</textarea>
				<p class="description">%s</p></td></tr>',
			esc_html__( '対象ホスト', 'rakuten-link-tracker' ),
			esc_textarea( implode( "\n", (array) $settings['hosts'] ) ),
			esc_html__( '1行に1ホスト。ここに書かれたホストへのリンクだけが短縮URLに置き換わります。', 'rakuten-link-tracker' )
		);

		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="exclude_logged_in" value="1" %s /> %s</label></td></tr>',
			esc_html__( '自分のアクセスを除外', 'rakuten-link-tracker' ),
			checked( 1, (int) $settings['exclude_logged_in'], false ),
			esc_html__( 'ログイン中のユーザーのPVとクリックを記録しない', 'rakuten-link-tracker' )
		);

		printf(
			'<tr><th scope="row"><label for="rlt-unknown">%s</label></th><td>
				<select id="rlt-unknown" name="unknown_code">
					<option value="home" %s>%s</option>
					<option value="404" %s>%s</option>
				</select></td></tr>',
			esc_html__( '存在しない短縮URL', 'rakuten-link-tracker' ),
			selected( 'home', $settings['unknown_code'], false ),
			esc_html__( 'サイトトップへリダイレクト', 'rakuten-link-tracker' ),
			selected( '404', $settings['unknown_code'], false ),
			esc_html__( '404を返す', 'rakuten-link-tracker' )
		);

		printf(
			'<tr><th scope="row"><label for="rlt-retention">%s</label></th><td>
				<input type="number" id="rlt-retention" name="retention_days" min="0" value="%d" class="small-text" /> %s
				<p class="description">%s</p></td></tr>',
			esc_html__( '生ログの保持日数', 'rakuten-link-tracker' ),
			(int) $settings['retention_days'],
			esc_html__( '日', 'rakuten-link-tracker' ),
			esc_html__( '0 を指定すると無期限に保持します。', 'rakuten-link-tracker' )
		);

		echo '</tbody></table>';
		submit_button();
		echo '</form>';
	}

	private function renderApiKeys(): void {
		echo '<hr /><h2>' . esc_html__( '読み取り専用APIキー', 'rakuten-link-tracker' ) . '</h2>';
		echo '<p class="description">'
			. esc_html__( '統計の参照だけができるキーです。Looker Studio やスプレッドシート、AIツールに渡すのはこちらを使ってください。WordPress の管理権限は付きません。', 'rakuten-link-tracker' )
			. '</p>';

		$fresh = get_transient( self::NEW_KEY_TRANSIENT );

		if ( is_string( $fresh ) && '' !== $fresh ) {
			delete_transient( self::NEW_KEY_TRANSIENT );
			printf(
				'<div class="notice notice-success"><p>%s</p><p><code>%s</code></p></div>',
				esc_html__( 'キーを発行しました。この値が表示されるのはこの一度きりです。', 'rakuten-link-tracker' ),
				esc_html( $fresh )
			);
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'ラベル', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( '発行日', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( '最終使用', 'rakuten-link-tracker' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		$keys = ApiKeyManager::all();

		if ( array() === $keys ) {
			echo '<tr><td colspan="4">' . esc_html__( 'まだキーがありません。', 'rakuten-link-tracker' ) . '</td></tr>';
		}

		foreach ( $keys as $key ) {
			echo '<tr>';
			echo '<td>' . esc_html( $key['label'] ) . '</td>';
			echo '<td>' . esc_html( get_date_from_gmt( $key['created_at'], 'Y-m-d H:i' ) ) . '</td>';
			echo '<td>' . esc_html( null === $key['last_used_at'] ? '—' : get_date_from_gmt( (string) $key['last_used_at'], 'Y-m-d H:i' ) ) . '</td>';
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="rlt_revoke_key" />';
			printf( '<input type="hidden" name="key_id" value="%s" />', esc_attr( $key['id'] ) );
			wp_nonce_field( self::NONCE );
			submit_button( __( '失効', 'rakuten-link-tracker' ), 'delete small', 'submit', false );
			echo '</form></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">';
		echo '<input type="hidden" name="action" value="rlt_create_key" />';
		wp_nonce_field( self::NONCE );
		printf(
			'<input type="text" name="label" class="regular-text" placeholder="%s" /> ',
			esc_attr__( '用途がわかる名前（例: Looker Studio）', 'rakuten-link-tracker' )
		);
		submit_button( __( 'キーを発行', 'rakuten-link-tracker' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	private function renderBulkTools(): void {
		echo '<hr /><h2>' . esc_html__( '既存記事の一括処理', 'rakuten-link-tracker' ) . '</h2>';

		wp_enqueue_script( 'rlt-admin', Plugin::url( 'assets/admin.js' ), array(), Plugin::VERSION, true );
		wp_add_inline_script(
			'rlt-admin',
			'window.rltBulk = ' . wp_json_encode(
				array(
					'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
					'nonce'          => wp_create_nonce( BulkConverter::NONCE ),
					'finished'       => __( '完了しました（%d 件を処理）。', 'rakuten-link-tracker' ),
					'failed'         => __( '処理に失敗しました。', 'rakuten-link-tracker' ),
					'confirmRestore' => __( 'すべての記事の本文を元の楽天URLに戻します。よろしいですか？', 'rakuten-link-tracker' ),
				)
			) . ';',
			'before'
		);

		echo '<p>';
		printf(
			'<button class="button button-primary" id="rlt-bulk-convert">%s</button> ',
			esc_html__( '既存記事をスキャンして変換', 'rakuten-link-tracker' )
		);
		printf(
			'<button class="button" id="rlt-bulk-restore">%s</button>',
			esc_html__( 'すべて元の楽天URLに戻す', 'rakuten-link-tracker' )
		);
		echo '</p>';

		echo '<div class="rlt-progress"><div class="rlt-progress-bar" id="rlt-bulk-bar"></div></div>';
		echo '<p id="rlt-bulk-status" class="description"></p>';
	}

	private function renderExportLinks(): void {
		echo '<hr /><h2>' . esc_html__( 'CSVエクスポート', 'rakuten-link-tracker' ) . '</h2>';
		echo '<p>';
		printf(
			'<a class="button" href="%s">%s</a> ',
			esc_url( rest_url( 'rlt/v1/export/clicks' ) ),
			esc_html__( 'クリックログ', 'rakuten-link-tracker' )
		);
		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( rest_url( 'rlt/v1/export/views' ) ),
			esc_html__( 'PVログ', 'rakuten-link-tracker' )
		);
		echo '</p>';
		echo '<p class="description">'
			. esc_html__( '既定では直近28日分です。?from=2026-01-01&to=2026-01-31 のように期間を指定できます。', 'rakuten-link-tracker' )
			. '</p>';
	}

	public function handleSave( bool $redirect = true ): void {
		if ( ! $this->authorised() ) {
			return;
		}

		$oldPrefix = Settings::prefix();

		$hosts = isset( $_POST['hosts'] )
			? preg_split( '/\R/', sanitize_textarea_field( wp_unslash( (string) $_POST['hosts'] ) ) )
			: array();

		Settings::update(
			array(
				'prefix'            => isset( $_POST['prefix'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['prefix'] ) ) : 'go',
				'hosts'             => array_filter( array_map( 'trim', (array) $hosts ) ),
				// An unchecked checkbox is simply absent from $_POST, not sent as
				// "0" -- so this must pass an explicit 0 here. Settings::update()
				// treats an absent key as "leave unchanged", so without this the
				// setting could never be turned off once enabled.
				'exclude_logged_in' => empty( $_POST['exclude_logged_in'] ) ? 0 : 1,
				'unknown_code'      => isset( $_POST['unknown_code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['unknown_code'] ) ) : 'home',
				'retention_days'    => isset( $_POST['retention_days'] ) ? absint( wp_unslash( $_POST['retention_days'] ) ) : 365,
			)
		);

		// A changed prefix means the old rewrite rule is stale; without a flush
		// every new short URL would 404.
		if ( Settings::prefix() !== $oldPrefix ) {
			( new \RLT\Frontend\RedirectHandler() )->addRewriteRule();
			flush_rewrite_rules();
			do_action( 'rlt_rewrite_flushed' );
		}

		$this->finish( $redirect, 'saved' );
	}

	public function handleCreateKey( bool $redirect = true ): void {
		if ( ! $this->authorised() ) {
			return;
		}

		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : '';

		if ( '' === $label ) {
			$label = __( '名称未設定', 'rakuten-link-tracker' );
		}

		$created = ApiKeyManager::create( $label );

		// Shown once on the next page load, then discarded.
		set_transient( self::NEW_KEY_TRANSIENT, $created['key'], 5 * MINUTE_IN_SECONDS );

		$this->finish( $redirect, 'key-created' );
	}

	public function handleRevokeKey( bool $redirect = true ): void {
		if ( ! $this->authorised() ) {
			return;
		}

		$id = isset( $_POST['key_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['key_id'] ) ) : '';
		ApiKeyManager::revoke( $id );

		$this->finish( $redirect, 'key-revoked' );
	}

	private function authorised(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		return isset( $_POST['_wpnonce'] )
			&& (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), self::NONCE );
	}

	private function finish( bool $redirect, string $notice ): void {
		if ( ! $redirect ) {
			return;
		}

		wp_safe_redirect( AdminMenu::url( AdminMenu::SLUG_SETTINGS, array( 'notice' => $notice ) ) );
		exit;
	}
}
