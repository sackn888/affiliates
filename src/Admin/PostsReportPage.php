<?php

declare(strict_types=1);

namespace RLT\Admin;

use RLT\Data\EventRepository;
use RLT\Installer;

/**
 * Per-post view of the same data, so a weak article is easy to spot.
 */
final class PostsReportPage {

	/**
	 * @var array<string, string>
	 */
	public const ORDERINGS = array(
		'views'   => 'PVが多い順',
		'clicks'  => 'クリックが多い順',
		'ctr'     => 'CTRが高い順',
		'ctr_asc' => 'CTRが低い順（改善候補）',
	);

	private EventRepository $events;

	public function __construct( ?EventRepository $events = null ) {
		$this->events = $events ?? new EventRepository();
	}

	public function render(): void {
		if ( ! current_user_can( Installer::CAPABILITY ) ) {
			wp_die( esc_html__( 'この画面を表示する権限がありません。', 'rakuten-link-tracker' ) );
		}

		$range = ( new DashboardPage() )->rangeFromQuery();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( (string) $_GET['orderby'] ) ) : 'views';

		if ( ! isset( self::ORDERINGS[ $orderby ] ) ) {
			$orderby = 'views';
		}

		$rows = $this->events->byPost( $range, false, $orderby, 200 );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( '記事別レポート', 'rakuten-link-tracker' ) . '</h1>';

		printf(
			'<p class="description">%s 〜 %s（%s）</p>',
			esc_html( $range->fromDate() ),
			esc_html( $range->toDate() ),
			esc_html( wp_timezone_string() )
		);

		echo '<p>';
		foreach ( self::ORDERINGS as $key => $label ) {
			printf(
				'<a class="button%s" href="%s">%s</a> ',
				$key === $orderby ? ' button-primary' : '',
				esc_url( AdminMenu::url( AdminMenu::SLUG_POSTS, array( 'orderby' => $key, 'days' => $range->dayCount() ) ) ),
				esc_html( $label )
			);
		}
		echo '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( '記事', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'PV', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'クリック', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'CTR', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'リンク', 'rakuten-link-tracker' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( array() === $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'まだデータがありません。', 'rakuten-link-tracker' ) . '</td></tr>';
		}

		foreach ( $rows as $row ) {
			printf(
				'<tr><td><a href="%s">%s</a></td><td>%d</td><td>%d</td><td>%s%%</td><td><a href="%s">%s</a></td></tr>',
				esc_url( (string) get_edit_post_link( $row['post_id'] ) ),
				esc_html( get_the_title( $row['post_id'] ) ),
				(int) $row['views'],
				(int) $row['clicks'],
				esc_html( number_format( $row['ctr'] * 100, 2 ) ),
				esc_url( AdminMenu::url( AdminMenu::SLUG_LINKS, array( 'post_id' => $row['post_id'] ) ) ),
				esc_html__( 'この記事のリンク', 'rakuten-link-tracker' )
			);
		}

		echo '</tbody></table>';
		echo '</div>';
	}
}
