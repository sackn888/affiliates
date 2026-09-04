<?php

declare(strict_types=1);

namespace RLT\Admin;

use RLT\Data\EventRepository;
use RLT\Data\LinkRepository;
use RLT\Installer;
use RLT\Settings;
use RLT\Support\DateRange;

/**
 * The links screen: a list, and a detail view for one link.
 */
final class LinkDetailPage {

	private LinkRepository $links;
	private EventRepository $events;

	public function __construct( ?LinkRepository $links = null, ?EventRepository $events = null ) {
		$this->links  = $links ?? new LinkRepository();
		$this->events = $events ?? new EventRepository();
	}

	public function register(): void {
		add_action( 'admin_post_rlt_update_link', array( $this, 'handleUpdate' ) );
	}

	public function route(): void {
		if ( ! current_user_can( Installer::CAPABILITY ) ) {
			wp_die( esc_html__( 'この画面を表示する権限がありません。', 'rakuten-link-tracker' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['code'] ) ? sanitize_key( wp_unslash( (string) $_GET['code'] ) ) : '';

		if ( '' === $code ) {
			$this->renderList();

			return;
		}

		$this->renderDetail( $code );
	}

	public function renderList(): void {
		$range = ( new DashboardPage() )->rangeFromQuery();
		$table = new LinksListTable( $range, $this->events );
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'リンク一覧', 'rakuten-link-tracker' ) . '</h1>';
		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( AdminMenu::SLUG_LINKS ) );
		$table->search_box( __( 'リンクを検索', 'rakuten-link-tracker' ), 'rlt-link-search' );
		$table->display();
		echo '</form>';
		echo '<p class="description">' . esc_html__( 'CTR（記事PV比）は、このリンクのクリック数を掲載記事全体のPVで割った値です。1つの記事に複数のリンクがある場合、それらは同じ分母（記事のPV）を共有します。', 'rakuten-link-tracker' ) . '</p>';
		echo '</div>';
	}

	public function renderDetail( string $code ): void {
		$link = $this->links->findByCode( $code );

		if ( null === $link ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>'
				. esc_html__( 'リンクが見つかりません。', 'rakuten-link-tracker' )
				. '</p></div></div>';

			return;
		}

		$range  = ( new DashboardPage() )->rangeFromQuery();
		$detail = $this->events->linkDetail( $link['id'], $range, false );

		echo '<div class="wrap">';
		printf(
			'<h1>%s</h1>',
			esc_html( '' === $link['label'] ? $link['code'] : $link['label'] )
		);

		printf(
			'<p><code class="rlt-short-url">%s</code></p>',
			esc_html( Settings::shortUrl( $link['code'] ) )
		);

		$this->renderEditForm( $link );

		echo '<h2>' . esc_html__( '日別クリック', 'rakuten-link-tracker' ) . '</h2>';
		$points = array_map(
			static fn ( array $d ): array => array( 'label' => $d['date'], 'value' => $d['clicks'] ),
			$detail['daily']
		);
		echo SvgChart::bars( $points, '#d63638', __( '日別クリック', 'rakuten-link-tracker' ) ); // phpcs:ignore WordPress.Security.EscapeOutput

		$this->renderBreakdown(
			__( 'リファラ', 'rakuten-link-tracker' ),
			array_map(
				static fn ( array $r ): array => array(
					'name'   => '' === $r['referer'] ? __( '（不明・直接）', 'rakuten-link-tracker' ) : $r['referer'],
					'clicks' => $r['clicks'],
				),
				$detail['referers']
			)
		);

		$this->renderBreakdown(
			__( 'デバイス', 'rakuten-link-tracker' ),
			array_map(
				static fn ( array $d ): array => array(
					'name'   => $d['label'],
					'clicks' => $d['clicks'],
				),
				$detail['devices']
			)
		);

		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $link
	 */
	private function renderEditForm( array $link ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="rlt_update_link" />';
		printf( '<input type="hidden" name="code" value="%s" />', esc_attr( $link['code'] ) );
		wp_nonce_field( 'rlt_update_link' );

		echo '<table class="form-table"><tbody>';

		printf(
			'<tr><th scope="row"><label for="rlt-label">%s</label></th><td><input class="regular-text" type="text" id="rlt-label" name="label" value="%s" /></td></tr>',
			esc_html__( 'ラベル', 'rakuten-link-tracker' ),
			esc_attr( $link['label'] )
		);

		printf(
			'<tr><th scope="row"><label for="rlt-target">%s</label></th><td><input class="large-text" type="url" id="rlt-target" name="target_url" value="%s" /><p class="description">%s</p></td></tr>',
			esc_html__( '遷移先URL', 'rakuten-link-tracker' ),
			esc_attr( $link['target_url'] ),
			esc_html__( '楽天のリンク先が変わったときは、記事を編集せずここだけ直せます。', 'rakuten-link-tracker' )
		);

		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="status" value="1" %s /> %s</label></td></tr>',
			esc_html__( '状態', 'rakuten-link-tracker' ),
			checked( 1, (int) $link['status'], false ),
			esc_html__( '有効', 'rakuten-link-tracker' )
		);

		echo '</tbody></table>';
		submit_button( __( '保存', 'rakuten-link-tracker' ) );
		echo '</form>';
	}

	/**
	 * @param array<int, array{name: string, clicks: int}> $rows
	 */
	private function renderBreakdown( string $heading, array $rows ): void {
		echo '<h2>' . esc_html( $heading ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';

		if ( array() === $rows ) {
			echo '<tr><td>' . esc_html__( 'データがありません。', 'rakuten-link-tracker' ) . '</td></tr>';
		}

		foreach ( $rows as $row ) {
			printf(
				'<tr><td>%s</td><td style="width:100px">%d</td></tr>',
				esc_html( $row['name'] ),
				(int) $row['clicks']
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * @param bool $redirect Set false in tests so the process does not exit.
	 */
	public function handleUpdate( bool $redirect = true ): void {
		if ( ! current_user_can( Installer::CAPABILITY ) ) {
			return;
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), 'rlt_update_link' ) ) {
			return;
		}

		$code = isset( $_POST['code'] ) ? sanitize_key( wp_unslash( (string) $_POST['code'] ) ) : '';
		$link = $this->links->findByCode( $code );

		if ( null === $link ) {
			return;
		}

		$fields = array(
			'label'  => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : $link['label'],
			'status' => empty( $_POST['status'] ) ? 0 : 1,
		);

		if ( isset( $_POST['target_url'] ) ) {
			// Match StatsController::updateLink()'s approach: esc_url_raw() only
			// sanitises characters and rejects a scheme when one is *present* --
			// it lets a protocol-relative "//evil.example/x" through unchanged
			// and even normalises a bare "evil.example/x" into a valid-looking
			// "http://evil.example/x". Checking the scheme on the raw,
			// pre-sanitisation input is what actually rejects those.
			$raw       = (string) $_POST['target_url'];
			$rawScheme = strtolower( (string) parse_url( wp_unslash( $raw ), PHP_URL_SCHEME ) );
			$url       = esc_url_raw( wp_unslash( $raw ), array( 'http', 'https' ) );

			// Match StatsController::updateLink()'s length cap so the two entry
			// points that write the same column agree. There is no JSON error
			// channel here, so an over-length value is simply left unset --
			// the stored value stays untouched, exactly as for a bad scheme.
			if ( strlen( $raw ) <= 2000 && '' !== $url && in_array( $rawScheme, array( 'http', 'https' ), true ) ) {
				$fields['target_url'] = $url;
			}
		}

		$this->links->update( $link['id'], $fields );

		if ( $redirect ) {
			wp_safe_redirect( AdminMenu::url( AdminMenu::SLUG_LINKS, array( 'code' => $code, 'updated' => 1 ) ) );
			exit;
		}
	}
}
