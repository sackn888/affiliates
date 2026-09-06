<?php

declare(strict_types=1);

namespace RLT\Admin;

use RLT\Data\EventRepository;
use RLT\Data\LinkRepository;
use RLT\Frontend\RedirectHandler;
use RLT\Installer;
use RLT\Plugin;
use RLT\Settings;
use RLT\Support\DateRange;
use RLT\Support\RequestContext;

/**
 * "Why was my click not counted?" -- a single screen an owner without database
 * access can use to find out.
 *
 * Read-only apart from the self-test button, which writes exactly one click
 * row through the same EventRepository path the live redirect uses, tagged so
 * it can never be mistaken for real traffic.
 */
final class DiagnosticsPage {

	public const NONCE              = 'rlt_diagnostics_selftest';
	public const SELFTEST_REFERER   = 'rlt-selftest';
	private const SELFTEST_TRANSIENT = 'rlt_selftest_result';
	private const RECENT_LIMIT      = 10;

	private LinkRepository $links;
	private EventRepository $events;

	public function __construct( ?LinkRepository $links = null, ?EventRepository $events = null ) {
		$this->links  = $links ?? new LinkRepository();
		$this->events = $events ?? new EventRepository();
	}

	public function register(): void {
		add_action( 'admin_post_rlt_diagnostics_selftest', array( $this, 'handleSelfTest' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'この画面を表示する権限がありません。', 'rakuten-link-tracker' ) );
		}

		echo '<div class="wrap rlt-diagnostics">';
		echo '<h1>' . esc_html__( '楽天リンク 診断', 'rakuten-link-tracker' ) . '</h1>';

		$this->renderSelfTestResult();
		$this->renderOwnClickStatus();
		$this->renderEnvironment();
		$this->renderPlumbing();
		$this->renderRecentActivity();
		$this->renderSelfTestButton();

		echo '</div>';
	}

	/**
	 * The single most likely cause: is exclude_logged_in on while the owner
	 * themselves is logged in.
	 */
	private function renderOwnClickStatus(): void {
		$excludeLoggedIn = (bool) Settings::get( 'exclude_logged_in' );
		$loggedIn        = is_user_logged_in();
		$wouldRecord     = ! ( $excludeLoggedIn && $loggedIn );

		echo '<h2>' . esc_html__( 'このユーザー自身のクリックが記録されるか', 'rakuten-link-tracker' ) . '</h2>';

		if ( $wouldRecord ) {
			$this->status( true, __( '今このユーザーがクリックすれば記録されます。', 'rakuten-link-tracker' ) );
		} else {
			$this->status(
				false,
				__( '今このユーザーがクリックしても記録されません：「自分のアクセスを除外」が有効で、かつログイン中です。', 'rakuten-link-tracker' )
			);
			printf(
				'<p><a href="%s">%s</a></p>',
				esc_url( AdminMenu::url( AdminMenu::SLUG_SETTINGS ) ),
				esc_html__( '設定画面で「自分のアクセスを除外」を確認する', 'rakuten-link-tracker' )
			);
		}
	}

	private function renderEnvironment(): void {
		echo '<h2>' . esc_html__( '環境', 'rakuten-link-tracker' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';

		$this->row( __( 'プラグインバージョン', 'rakuten-link-tracker' ), esc_html( Plugin::VERSION ) );
		$this->row( __( 'DBスキーマバージョン', 'rakuten-link-tracker' ), esc_html( (string) get_option( Installer::VERSION_OPTION, '-' ) ) );

		$tz     = wp_timezone_string();
		$nowUtc = current_time( 'mysql', true );
		$nowLoc = current_time( 'mysql', false );
		$this->row( __( 'WordPressのタイムゾーン', 'rakuten-link-tracker' ), esc_html( $tz ) );
		$this->row(
			__( '現在時刻', 'rakuten-link-tracker' ),
			esc_html(
				sprintf(
					/* translators: 1: site-local time, 2: UTC time */
					__( 'サイト時間: %1$s ／ UTC: %2$s', 'rakuten-link-tracker' ),
					$nowLoc,
					$nowUtc
				)
			)
		);

		$permalink = (string) get_option( 'permalink_structure' );
		if ( '' === $permalink ) {
			echo '<tr><th scope="row">' . esc_html__( 'パーマリンク構造', 'rakuten-link-tracker' ) . '</th><td>';
			$this->status( false, __( '「基本」のままです。/go/ 短縮URLはこの設定では一切機能しません。', 'rakuten-link-tracker' ) );
			echo '</td></tr>';
		} else {
			$this->row( __( 'パーマリンク構造', 'rakuten-link-tracker' ), esc_html( $permalink ) );
		}

		echo '</tbody></table>';
	}

	private function renderPlumbing(): void {
		global $wpdb;

		echo '<h2>' . esc_html__( '配線の確認', 'rakuten-link-tracker' ) . '</h2>';

		echo '<h3>' . esc_html__( 'テーブル', 'rakuten-link-tracker' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'テーブル', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( '状態', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( '行数', 'rakuten-link-tracker' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach (
			array(
				__( 'リンク', 'rakuten-link-tracker' ) => Installer::linksTable(),
				__( 'クリック', 'rakuten-link-tracker' ) => Installer::clicksTable(),
				__( 'PV', 'rakuten-link-tracker' )       => Installer::viewsTable(),
			) as $label => $table
		) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
			$count  = $exists ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table ) : 0;

			echo '<tr>';
			echo '<td>' . esc_html( $label ) . '</td>';
			echo '<td>' . ( $exists
				? '<span class="rlt-ok">OK</span>'
				: '<span class="rlt-warn">' . esc_html__( '要確認：テーブルが存在しません', 'rakuten-link-tracker' ) . '</span>' )
				. '</td>';
			echo '<td>' . esc_html( number_format_i18n( $count ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'リライトルール', 'rakuten-link-tracker' ) . '</h3>';
		$rules = get_option( 'rewrite_rules' );
		$rules = is_array( $rules ) ? $rules : array();

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'プレフィックス', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( '状態', 'rakuten-link-tracker' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( Settings::allPrefixes() as $prefix ) {
			$key    = RedirectHandler::ruleKeyFor( $prefix );
			$exists = array_key_exists( $key, $rules );

			echo '<tr>';
			echo '<td>' . esc_html( $prefix ) . '</td>';
			echo '<td>' . ( $exists
				? '<span class="rlt-ok">OK</span>'
				: '<span class="rlt-warn">' . esc_html__( '要確認：リライトルールが見つかりません', 'rakuten-link-tracker' ) . '</span>' )
				. '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( '記録を抑制する設定', 'rakuten-link-tracker' ) . '</h3>';
		$settings = Settings::all();
		echo '<table class="widefat striped"><tbody>';
		$this->row( __( '自分のアクセスを除外', 'rakuten-link-tracker' ), esc_html( $settings['exclude_logged_in'] ? __( '有効', 'rakuten-link-tracker' ) : __( '無効', 'rakuten-link-tracker' ) ) );
		$this->row( __( 'クリックの重複排除秒数', 'rakuten-link-tracker' ), esc_html( (string) (int) $settings['click_dedup_seconds'] ) );
		$this->row( __( 'PVの重複排除秒数', 'rakuten-link-tracker' ), esc_html( (string) (int) $settings['view_dedup_seconds'] ) );
		$this->row( __( '生ログの保持日数', 'rakuten-link-tracker' ), esc_html( (string) (int) $settings['retention_days'] ) );
		$this->row( __( 'プレフィックス', 'rakuten-link-tracker' ), esc_html( (string) $settings['prefix'] ) );
		$this->row( __( '対象ホスト', 'rakuten-link-tracker' ), esc_html( implode( ', ', (array) $settings['hosts'] ) ) );
		echo '</tbody></table>';
	}

	private function renderRecentActivity(): void {
		echo '<h2>' . esc_html__( '直近の記録（判断材料）', 'rakuten-link-tracker' ) . '</h2>';

		if ( ! $this->events->hasAnyClickEver() ) {
			echo '<p>' . esc_html__( 'これまでに一件もクリックが記録されていません。', 'rakuten-link-tracker' ) . '</p>';
		} else {
			echo '<h3>' . esc_html__( '直近のクリック（最大10件、bot含む）', 'rakuten-link-tracker' ) . '</h3>';
			$this->renderEventTable(
				$this->events->recentClicks( self::RECENT_LIMIT ),
				'clicked_at',
				true
			);
		}

		echo '<h3>' . esc_html__( '直近のPV（最大10件、bot含む）', 'rakuten-link-tracker' ) . '</h3>';
		$views = $this->events->recentViews( self::RECENT_LIMIT );
		if ( array() === $views ) {
			echo '<p>' . esc_html__( 'これまでに一件もPVが記録されていません。', 'rakuten-link-tracker' ) . '</p>';
		} else {
			$this->renderEventTable( $views, 'viewed_at', false );
		}

		$this->renderCounts();
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function renderEventTable( array $rows, string $timeKey, bool $isClick ): void {
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( '日時（サイト時間）', 'rakuten-link-tracker' ) . '</th>';
		if ( $isClick ) {
			echo '<th>' . esc_html__( 'コード', 'rakuten-link-tracker' ) . '</th>';
			echo '<th>' . esc_html__( 'ラベル', 'rakuten-link-tracker' ) . '</th>';
		} else {
			echo '<th>' . esc_html__( '記事ID', 'rakuten-link-tracker' ) . '</th>';
		}
		echo '<th>' . esc_html__( 'デバイス', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'bot', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'リファラ', 'rakuten-link-tracker' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$localTime = get_date_from_gmt( (string) $row[ $timeKey ], 'Y-m-d H:i:s' );
			$isBot     = (int) $row['is_bot'] === 1;

			echo '<tr' . ( $isBot ? ' class="rlt-bot-row"' : '' ) . '>';
			echo '<td>' . esc_html( $localTime ) . '</td>';
			if ( $isClick ) {
				echo '<td>' . esc_html( (string) $row['code'] ) . '</td>';
				echo '<td>' . esc_html( (string) $row['label'] ) . '</td>';
			} else {
				echo '<td>' . esc_html( (string) $row['post_id'] ) . '</td>';
			}
			echo '<td>' . esc_html( (string) $row['device'] ) . '</td>';
			echo '<td>' . ( $isBot
				? '<strong class="rlt-warn">' . esc_html__( 'bot', 'rakuten-link-tracker' ) . '</strong>'
				: esc_html__( '-', 'rakuten-link-tracker' ) )
				. '</td>';
			echo '<td>' . esc_html( (string) $row['referer'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function renderCounts(): void {
		echo '<h3>' . esc_html__( '件数（bot / 非bot）', 'rakuten-link-tracker' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th></th>';
		echo '<th>' . esc_html__( 'クリック（非bot）', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'クリック（bot）', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'PV（非bot）', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'PV（bot）', 'rakuten-link-tracker' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach (
			array(
				__( '本日', 'rakuten-link-tracker' )      => DateRange::lastDays( 1 ),
				__( '過去7日間', 'rakuten-link-tracker' ) => DateRange::lastDays( 7 ),
			) as $label => $range
		) {
			$clicksHuman = $this->events->summary( $range, false )['clicks'];
			$clicksAll   = $this->events->summary( $range, true )['clicks'];
			$viewsHuman  = $this->events->summary( $range, false )['views'];
			$viewsAll    = $this->events->summary( $range, true )['views'];

			echo '<tr>';
			echo '<td>' . esc_html( $label ) . '</td>';
			echo '<td>' . esc_html( (string) $clicksHuman ) . '</td>';
			echo '<td>' . esc_html( (string) max( 0, $clicksAll - $clicksHuman ) ) . '</td>';
			echo '<td>' . esc_html( (string) $viewsHuman ) . '</td>';
			echo '<td>' . esc_html( (string) max( 0, $viewsAll - $viewsHuman ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function renderSelfTestButton(): void {
		echo '<h2>' . esc_html__( 'セルフテスト', 'rakuten-link-tracker' ) . '</h2>';
		echo '<p class="description">'
			. esc_html__( 'このサイトに実在するリンクに対して実際にクリックを1件記録し、結果を表示します。実トラフィックと区別できるよう、リファラには "rlt-selftest" が入ります。', 'rakuten-link-tracker' )
			. '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="rlt_diagnostics_selftest" />';
		wp_nonce_field( self::NONCE );
		submit_button( __( 'セルフテストを実行', 'rakuten-link-tracker' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	private function renderSelfTestResult(): void {
		$result = get_transient( self::SELFTEST_TRANSIENT );

		if ( ! is_array( $result ) ) {
			return;
		}

		delete_transient( self::SELFTEST_TRANSIENT );

		$messages = array(
			'recorded'           => array( true, __( 'セルフテスト: 記録されました。上の「直近のクリック」に rlt-selftest という参照元で表示されます。', 'rakuten-link-tracker' ) ),
			'skipped_logged_in'  => array( false, __( 'セルフテスト: 記録されませんでした。理由：「自分のアクセスを除外」が有効で、実行者がログイン中のためです。', 'rakuten-link-tracker' ) ),
			'skipped_dedup'      => array( false, __( 'セルフテスト: 記録されませんでした。理由：クリックの重複排除ウィンドウ内のためです。少し時間をおいて再実行してください。', 'rakuten-link-tracker' ) ),
			'no_links'           => array( false, __( 'セルフテスト: 記録できませんでした。理由：このサイトにリンクが1件も存在しないためです。まず記事を変換してください。', 'rakuten-link-tracker' ) ),
			'failed'             => array( false, __( 'セルフテスト: 書き込みに失敗しました。データベースの権限やディスク容量を確認してください。', 'rakuten-link-tracker' ) ),
		);

		$status = (string) ( $result['status'] ?? 'failed' );
		[ $ok, $message ] = $messages[ $status ] ?? array( false, __( 'セルフテスト: 不明な結果です。', 'rakuten-link-tracker' ) );

		echo '<div class="notice notice-' . ( $ok ? 'success' : 'warning' ) . '"><p>';
		$this->status( $ok, $message );
		echo '</p></div>';
	}

	/**
	 * Runs the self-test and stores the outcome for the next render(), then
	 * redirects back -- mirroring SettingsPage's admin_post handlers.
	 *
	 * Writes through EventRepository::recordClick(), the exact method the
	 * live /go/ redirect uses (via RedirectHandler::trackClick()), with the
	 * referer temporarily overridden so the created row is unmistakably a
	 * test row rather than genuine traffic.
	 */
	public function handleSelfTest( bool $redirect = true ): void {
		if ( ! $this->authorised() ) {
			return;
		}

		$result = $this->runSelfTest();

		set_transient( self::SELFTEST_TRANSIENT, $result, MINUTE_IN_SECONDS );

		if ( ! $redirect ) {
			return;
		}

		wp_safe_redirect( AdminMenu::url( AdminMenu::SLUG_DIAGNOSTICS ) );
		exit;
	}

	/**
	 * @return array{status:string}
	 */
	private function runSelfTest(): array {
		$link = $this->links->any();

		if ( null === $link ) {
			return array( 'status' => 'no_links' );
		}

		if ( Settings::get( 'exclude_logged_in' ) && is_user_logged_in() ) {
			return array( 'status' => 'skipped_logged_in' );
		}

		$window = (int) Settings::get( 'click_dedup_seconds' );

		if ( $this->events->hasRecentClick( (int) $link['id'], RequestContext::visitorHash(), $window ) ) {
			return array( 'status' => 'skipped_dedup' );
		}

		$previousReferer = $_SERVER['HTTP_REFERER'] ?? null;
		$_SERVER['HTTP_REFERER'] = self::SELFTEST_REFERER;

		try {
			$recorded = $this->events->recordClick( (int) $link['id'], (int) $link['post_id'] );
		} finally {
			if ( null === $previousReferer ) {
				unset( $_SERVER['HTTP_REFERER'] );
			} else {
				$_SERVER['HTTP_REFERER'] = $previousReferer;
			}
		}

		return array( 'status' => $recorded ? 'recorded' : 'failed' );
	}

	private function authorised(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		return isset( $_POST['_wpnonce'] )
			&& (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), self::NONCE );
	}

	private function row( string $label, string $escapedValue ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $escapedValue . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private function status( bool $ok, string $message ): void {
		printf(
			'<strong class="%s">%s</strong> %s',
			$ok ? 'rlt-ok' : 'rlt-warn',
			$ok ? 'OK' : esc_html__( '要確認', 'rakuten-link-tracker' ),
			esc_html( $message )
		);
	}
}
