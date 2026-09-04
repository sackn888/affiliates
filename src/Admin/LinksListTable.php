<?php

declare(strict_types=1);

namespace RLT\Admin;

use RLT\Data\EventRepository;
use RLT\Settings;
use RLT\Support\DateRange;

/**
 * The links table, backed by the aggregated per-link statistics.
 */
final class LinksListTable extends \WP_List_Table {

	private const PER_PAGE = 30;

	private DateRange $range;
	private EventRepository $events;

	public function __construct( DateRange $range, ?EventRepository $events = null ) {
		parent::__construct(
			array(
				'singular' => 'rlt_link',
				'plural'   => 'rlt_links',
				'ajax'     => false,
			)
		);

		$this->range  = $range;
		$this->events = $events ?? new EventRepository();
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'label'      => __( 'ラベル', 'rakuten-link-tracker' ),
			'code'       => __( '短縮URL', 'rakuten-link-tracker' ),
			'post'       => __( '掲載記事', 'rakuten-link-tracker' ),
			'clicks'     => __( 'クリック', 'rakuten-link-tracker' ),
			'ctr'        => __( 'CTR', 'rakuten-link-tracker' ),
			'last_click' => __( '最終クリック', 'rakuten-link-tracker' ),
			'status'     => __( '状態', 'rakuten-link-tracker' ),
		);
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function get_sortable_columns(): array {
		return array(
			'clicks'     => array( 'clicks', true ),
			'ctr'        => array( 'ctr', false ),
			'last_click' => array( 'last_click', false ),
		);
	}

	public function prepare_items(): void {
		// Read-only listing filters; no state changes, so no nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$postId  = isset( $_GET['post_id'] ) ? absint( wp_unslash( $_GET['post_id'] ) ) : 0;
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( (string) $_GET['orderby'] ) ) : 'clicks';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$rows = $this->events->byLink( $this->range, false, $postId > 0 ? $postId : null, $orderby, 500 );

		if ( '' !== $search ) {
			$needle = mb_strtolower( $search );
			$rows   = array_values(
				array_filter(
					$rows,
					static function ( array $row ) use ( $needle ): bool {
						return str_contains( mb_strtolower( $row['label'] ), $needle )
							|| str_contains( mb_strtolower( $row['code'] ), $needle )
							|| str_contains( mb_strtolower( $row['target_url'] ), $needle );
					}
				)
			);
		}

		$total   = count( $rows );
		$current = $this->get_pagenum();

		$this->items = array_slice( $rows, ( $current - 1 ) * self::PER_PAGE, self::PER_PAGE );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total / self::PER_PAGE ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_label( array $item ): string {
		$name = '' === $item['label'] ? $item['code'] : $item['label'];

		$actions = array(
			'detail' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( AdminMenu::url( AdminMenu::SLUG_LINKS, array( 'code' => $item['code'] ) ) ),
				esc_html__( '詳細・編集', 'rakuten-link-tracker' )
			),
			'target' => sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( $item['target_url'] ),
				esc_html__( '遷移先を開く', 'rakuten-link-tracker' )
			),
		);

		return sprintf(
			'<strong><a href="%s">%s</a></strong>%s',
			esc_url( AdminMenu::url( AdminMenu::SLUG_LINKS, array( 'code' => $item['code'] ) ) ),
			esc_html( $name ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_code( array $item ): string {
		return sprintf(
			'<code class="rlt-short-url">%s</code>',
			esc_html( Settings::shortUrl( $item['code'] ) )
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_post( array $item ): string {
		if ( $item['post_id'] <= 0 ) {
			return '—';
		}

		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( (string) get_edit_post_link( $item['post_id'] ) ),
			esc_html( get_the_title( $item['post_id'] ) )
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_clicks( array $item ): string {
		return sprintf(
			'%d <span class="description">(%s %d)</span>',
			(int) $item['clicks'],
			esc_html__( 'ユニーク', 'rakuten-link-tracker' ),
			(int) $item['unique_clicks']
		);
	}

	/**
	 * CTR here is clicks over the post's total views (post_views), not a sum of
	 * per-link view counts -- byLink() repeats the post's view total on every
	 * link row of that post, so it must never be summed across rows.
	 *
	 * @param array<string, mixed> $item
	 */
	public function column_ctr( array $item ): string {
		return esc_html( number_format( $item['ctr'] * 100, 2 ) . '%' );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_last_click( array $item ): string {
		if ( null === $item['last_click'] ) {
			return '—';
		}

		return esc_html( get_date_from_gmt( (string) $item['last_click'], 'Y-m-d H:i' ) );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_status( array $item ): string {
		return 1 === (int) $item['status']
			? esc_html__( '有効', 'rakuten-link-tracker' )
			: esc_html__( 'アーカイブ', 'rakuten-link-tracker' );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	public function no_items(): void {
		esc_html_e( 'リンクがまだありません。楽天アフィリエイトリンクを含む記事を保存すると、ここに出てきます。', 'rakuten-link-tracker' );
	}
}
