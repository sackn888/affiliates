<?php

declare(strict_types=1);

namespace RLT\Admin;

use RLT\Data\EventRepository;
use RLT\Installer;
use RLT\Support\DateRange;

/**
 * Overview screen: totals, daily trend, and the leading links and posts.
 */
final class DashboardPage {

	public const PERIODS = array( 7, 28, 90 );

	private const DEFAULT_DAYS = 28;

	private EventRepository $events;

	public function __construct( ?EventRepository $events = null ) {
		$this->events = $events ?? new EventRepository();
	}

	/**
	 * Read the period out of the query string, defaulting to 28 days.
	 */
	public function rangeFromQuery(): DateRange {
		// Read-only reporting filters; no state changes, so no nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['from'] ) ) : null;
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['to'] ) ) : null;

		if ( null !== $from && null !== $to ) {
			return DateRange::fromRequest( $from, $to, self::DEFAULT_DAYS );
		}

		$days = isset( $_GET['days'] ) ? absint( wp_unslash( $_GET['days'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return DateRange::lastDays( in_array( $days, self::PERIODS, true ) ? $days : self::DEFAULT_DAYS );
	}

	public function render(): void {
		if ( ! current_user_can( Installer::CAPABILITY ) ) {
			wp_die( esc_html__( 'この画面を表示する権限がありません。', 'rakuten-link-tracker' ) );
		}

		$range    = $this->rangeFromQuery();
		$totals   = $this->events->summary( $range );
		$previous = $this->events->summary( $range->previous() );
		$daily    = $this->events->daily( $range );
		$topLinks = $this->events->byLink( $range, false, null, 'clicks', 10 );
		$topPosts = $this->events->byPost( $range, false, 'views', 10 );

		echo '<div class="wrap rlt-dashboard">';
		echo '<h1>' . esc_html__( '楽天リンク ダッシュボード', 'rakuten-link-tracker' ) . '</h1>';

		$this->renderPeriodSelector( $range );

		echo '<div class="rlt-cards">';
		$this->renderCard( __( 'PV', 'rakuten-link-tracker' ), number_format( $totals['views'] ), $totals['views'], $previous['views'] );
		$this->renderCard( __( 'クリック', 'rakuten-link-tracker' ), number_format( $totals['clicks'] ), $totals['clicks'], $previous['clicks'] );
		$this->renderCard( __( 'CTR', 'rakuten-link-tracker' ), number_format( $totals['ctr'] * 100, 2 ) . '%', $totals['ctr'], $previous['ctr'] );
		$this->renderCard( __( 'ユニーククリック', 'rakuten-link-tracker' ), number_format( $totals['unique_clicks'] ), $totals['unique_clicks'], $previous['unique_clicks'] );
		echo '</div>';

		$viewPoints  = array_map(
			static fn ( array $d ): array => array( 'label' => $d['date'], 'value' => $d['views'] ),
			$daily
		);
		$clickPoints = array_map(
			static fn ( array $d ): array => array( 'label' => $d['date'], 'value' => $d['clicks'] ),
			$daily
		);

		echo '<h2>' . esc_html__( '日別PV', 'rakuten-link-tracker' ) . '</h2>';
		// SvgChart escapes every value it writes.
		echo SvgChart::bars( $viewPoints, '#2271b1', __( '日別PV', 'rakuten-link-tracker' ) ); // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<h2>' . esc_html__( '日別クリック', 'rakuten-link-tracker' ) . '</h2>';
		echo SvgChart::bars( $clickPoints, '#d63638', __( '日別クリック', 'rakuten-link-tracker' ) ); // phpcs:ignore WordPress.Security.EscapeOutput

		$this->renderTopLinks( $topLinks );
		$this->renderTopPosts( $topPosts );

		echo '</div>';
	}

	private function renderPeriodSelector( DateRange $range ): void {
		echo '<p class="rlt-periods">';

		foreach ( self::PERIODS as $days ) {
			printf(
				'<a class="button%s" href="%s">%s</a> ',
				$range->dayCount() === $days ? ' button-primary' : '',
				esc_url( AdminMenu::url( AdminMenu::SLUG, array( 'days' => $days ) ) ),
				esc_html( sprintf( /* translators: %d: number of days */ __( '過去%d日', 'rakuten-link-tracker' ), $days ) )
			);
		}

		printf(
			'<span class="rlt-range">%s 〜 %s（%s）</span>',
			esc_html( $range->fromDate() ),
			esc_html( $range->toDate() ),
			esc_html( wp_timezone_string() )
		);

		echo '</p>';
	}

	private function renderCard( string $label, string $value, float $current, float $previous ): void {
		echo '<div class="rlt-card">';
		echo '<span class="rlt-card-label">' . esc_html( $label ) . '</span>';
		echo '<span class="rlt-card-value">' . esc_html( $value ) . '</span>';

		if ( $previous > 0 ) {
			$change = ( ( $current - $previous ) / $previous ) * 100;
			printf(
				'<span class="rlt-card-change %s">%s%s%%</span>',
				$change >= 0 ? 'is-up' : 'is-down',
				$change >= 0 ? '+' : '',
				esc_html( number_format( $change, 1 ) )
			);
		}

		echo '</div>';
	}

	/**
	 * @param array<int, array<string, mixed>> $links
	 */
	private function renderTopLinks( array $links ): void {
		echo '<h2>' . esc_html__( 'クリックの多いリンク', 'rakuten-link-tracker' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'ラベル', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( '記事', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'クリック', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'CTR', 'rakuten-link-tracker' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( array() === $links ) {
			echo '<tr><td colspan="4">' . esc_html__( 'まだデータがありません。', 'rakuten-link-tracker' ) . '</td></tr>';
		}

		foreach ( $links as $link ) {
			printf(
				'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%d</td><td>%s%%</td></tr>',
				esc_url( AdminMenu::url( AdminMenu::SLUG_LINKS, array( 'code' => $link['code'] ) ) ),
				esc_html( '' === $link['label'] ? $link['code'] : $link['label'] ),
				esc_html( get_the_title( $link['post_id'] ) ),
				(int) $link['clicks'],
				esc_html( number_format( $link['ctr'] * 100, 2 ) )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * @param array<int, array<string, mixed>> $posts
	 */
	private function renderTopPosts( array $posts ): void {
		echo '<h2>' . esc_html__( 'PVの多い記事', 'rakuten-link-tracker' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( '記事', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'PV', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'クリック', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'CTR', 'rakuten-link-tracker' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( array() === $posts ) {
			echo '<tr><td colspan="4">' . esc_html__( 'まだデータがありません。', 'rakuten-link-tracker' ) . '</td></tr>';
		}

		foreach ( $posts as $post ) {
			printf(
				'<tr><td>%s</td><td>%d</td><td>%d</td><td>%s%%</td></tr>',
				esc_html( get_the_title( $post['post_id'] ) ),
				(int) $post['views'],
				(int) $post['clicks'],
				esc_html( number_format( $post['ctr'] * 100, 2 ) )
			);
		}

		echo '</tbody></table>';
	}
}
