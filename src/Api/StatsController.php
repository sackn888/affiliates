<?php

declare(strict_types=1);

namespace RLT\Api;

use RLT\Data\ApiKeyManager;
use RLT\Data\EventRepository;
use RLT\Data\LinkRepository;
use RLT\Installer;
use RLT\Settings;
use RLT\Support\DateRange;

/**
 * Read API for the collected statistics.
 *
 * Two ways in: a WordPress user holding the rlt_view_stats capability, or a
 * read-only API key. The key can never write, so it is safe to paste into a
 * dashboard tool or hand to an LLM.
 */
final class StatsController {

	public const REST_NAMESPACE = 'rlt/v1';

	private const DEFAULT_DAYS  = 28;
	private const DEFAULT_LIMIT = 50;
	private const MAX_LIMIT     = 500;

	private LinkRepository $links;
	private EventRepository $events;

	public function __construct( ?LinkRepository $links = null, ?EventRepository $events = null ) {
		$this->links  = $links ?? new LinkRepository();
		$this->events = $events ?? new EventRepository();
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	public function registerRoutes(): void {
		$read = array(
			'methods'             => 'GET',
			'permission_callback' => array( $this, 'readPermission' ),
			'args'                => $this->commonArgs(),
		);

		register_rest_route( self::REST_NAMESPACE, '/stats/summary', array_merge( $read, array( 'callback' => array( $this, 'getSummary' ) ) ) );
		register_rest_route( self::REST_NAMESPACE, '/stats/daily', array_merge( $read, array( 'callback' => array( $this, 'getDaily' ) ) ) );
		register_rest_route( self::REST_NAMESPACE, '/stats/posts', array_merge( $read, array( 'callback' => array( $this, 'getPosts' ) ) ) );
		register_rest_route( self::REST_NAMESPACE, '/stats/links', array_merge( $read, array( 'callback' => array( $this, 'getLinks' ) ) ) );
		register_rest_route( self::REST_NAMESPACE, '/stats/report', array_merge( $read, array( 'callback' => array( $this, 'getReport' ) ) ) );
		register_rest_route( self::REST_NAMESPACE, '/links', array_merge( $read, array( 'callback' => array( $this, 'getLinks' ) ) ) );

		register_rest_route(
			self::REST_NAMESPACE,
			'/stats/links/(?P<code>[a-z0-9]{4,16})',
			array_merge( $read, array( 'callback' => array( $this, 'getLink' ) ) )
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/links/(?P<code>[a-z0-9]{4,16})',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'updateLink' ),
				'permission_callback' => array( $this, 'writePermission' ),
				'args'                => array(
					'target_url' => array( 'type' => 'string' ),
					'label'      => array( 'type' => 'string' ),
					'status'     => array( 'type' => 'integer' ),
				),
			)
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function commonArgs(): array {
		return array(
			'from'         => array( 'type' => 'string' ),
			'to'           => array( 'type' => 'string' ),
			'include_bots' => array( 'type' => 'boolean', 'default' => false ),
			'limit'        => array( 'type' => 'integer', 'default' => self::DEFAULT_LIMIT ),
			'orderby'      => array( 'type' => 'string', 'default' => 'clicks' ),
			'post_id'      => array( 'type' => 'integer' ),
		);
	}

	public function readPermission( \WP_REST_Request $request ): bool|\WP_Error {
		if ( current_user_can( Installer::CAPABILITY ) ) {
			return true;
		}

		$key = (string) $request->get_header( ApiKeyManager::HEADER );

		if ( '' !== $key && null !== ApiKeyManager::verify( $key ) ) {
			return true;
		}

		return new \WP_Error(
			'rlt_forbidden',
			__( 'この統計を参照する権限がありません。', 'rakuten-link-tracker' ),
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
	}

	/**
	 * Writes require a real WordPress user. A read-only key must never change data.
	 */
	public function writePermission(): bool|\WP_Error {
		if ( current_user_can( Installer::CAPABILITY ) ) {
			return true;
		}

		return new \WP_Error(
			'rlt_forbidden',
			__( 'リンクを変更する権限がありません。', 'rakuten-link-tracker' ),
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
	}

	public function getSummary( \WP_REST_Request $request ): \WP_REST_Response {
		$range = $this->range( $request );
		$bots  = (bool) $request->get_param( 'include_bots' );

		return $this->respond(
			$range,
			array(
				'totals'   => $this->events->summary( $range, $bots ),
				'previous' => $this->events->summary( $range->previous(), $bots ),
			)
		);
	}

	public function getDaily( \WP_REST_Request $request ): \WP_REST_Response {
		$range = $this->range( $request );

		return $this->respond(
			$range,
			array( 'days' => $this->events->daily( $range, (bool) $request->get_param( 'include_bots' ) ) )
		);
	}

	public function getPosts( \WP_REST_Request $request ): \WP_REST_Response {
		$range = $this->range( $request );

		$rows = $this->events->byPost(
			$range,
			(bool) $request->get_param( 'include_bots' ),
			(string) $request->get_param( 'orderby' ),
			$this->limit( $request )
		);

		foreach ( $rows as &$row ) {
			$row['title']     = html_entity_decode( (string) get_the_title( $row['post_id'] ), ENT_QUOTES, 'UTF-8' );
			$row['permalink'] = (string) get_permalink( $row['post_id'] );
		}
		unset( $row );

		return $this->respond( $range, array( 'posts' => $rows ) );
	}

	public function getLinks( \WP_REST_Request $request ): \WP_REST_Response {
		$range  = $this->range( $request );
		$postId = $request->get_param( 'post_id' );

		$rows = $this->events->byLink(
			$range,
			(bool) $request->get_param( 'include_bots' ),
			null === $postId ? null : (int) $postId,
			(string) $request->get_param( 'orderby' ),
			$this->limit( $request )
		);

		foreach ( $rows as &$row ) {
			$row['short_url']  = Settings::shortUrl( $row['code'] );
			$row['post_title'] = html_entity_decode( (string) get_the_title( $row['post_id'] ), ENT_QUOTES, 'UTF-8' );
		}
		unset( $row );

		return $this->respond( $range, array( 'links' => $rows ) );
	}

	public function getLink( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$link = $this->links->findByCode( (string) $request->get_param( 'code' ) );

		if ( null === $link ) {
			return new \WP_Error( 'rlt_not_found', __( 'リンクが見つかりません。', 'rakuten-link-tracker' ), array( 'status' => 404 ) );
		}

		$range  = $this->range( $request );
		$detail = $this->events->linkDetail( $link['id'], $range, (bool) $request->get_param( 'include_bots' ) );

		$link['short_url']  = Settings::shortUrl( $link['code'] );
		$link['post_title'] = html_entity_decode( (string) get_the_title( $link['post_id'] ), ENT_QUOTES, 'UTF-8' );

		return $this->respond( $range, array_merge( array( 'link' => $link ), $detail ) );
	}

	/**
	 * A single response shaped for handing to an LLM: the numbers plus plain
	 * sentences describing what stands out.
	 */
	public function getReport( \WP_REST_Request $request ): \WP_REST_Response {
		$range = $this->range( $request );
		$bots  = (bool) $request->get_param( 'include_bots' );

		$totals   = $this->events->summary( $range, $bots );
		$previous = $this->events->summary( $range->previous(), $bots );
		$topLinks = $this->events->byLink( $range, $bots, null, 'clicks', 10 );
		$topPosts = $this->events->byPost( $range, $bots, 'views', 10 );
		$lowCtr   = $this->events->byPost( $range, $bots, 'ctr_asc', 10 );

		$notes = array();

		$notes[] = sprintf(
			/* translators: 1: page views, 2: clicks, 3: CTR percentage */
			__( '期間中のPVは %1$d、クリックは %2$d、CTRは %3$s%% です。', 'rakuten-link-tracker' ),
			$totals['views'],
			$totals['clicks'],
			number_format( $totals['ctr'] * 100, 2 )
		);

		if ( $previous['clicks'] > 0 ) {
			$change  = ( ( $totals['clicks'] - $previous['clicks'] ) / $previous['clicks'] ) * 100;
			$notes[] = sprintf(
				/* translators: %s: percentage change */
				__( 'クリック数は前期間比 %s%% です。', 'rakuten-link-tracker' ),
				number_format( $change, 1 )
			);
		}

		// post_views is the whole post's view total, repeated on every one of that
		// post's link rows -- it must never be summed across rows, only compared
		// per-row against that same row's own clicks.
		$dead = array_values(
			array_filter(
				$topLinks,
				static fn ( array $row ): bool => 0 === $row['clicks'] && $row['post_views'] > 0
			)
		);

		if ( array() !== $dead ) {
			$notes[] = sprintf(
				/* translators: %d: number of links */
				__( 'PVはあるのにクリックが0のリンクが %d 件あります。リンクの配置か訴求文を見直す候補です。', 'rakuten-link-tracker' ),
				count( $dead )
			);
		}

		foreach ( $lowCtr as $row ) {
			if ( $row['views'] >= 20 && $row['ctr'] < ( $totals['ctr'] / 2 ) ) {
				$notes[] = sprintf(
					/* translators: 1: post title, 2: page views, 3: CTR percentage */
					__( '「%1$s」はPV %2$d に対してCTR %3$s%% と平均を大きく下回っています。', 'rakuten-link-tracker' ),
					html_entity_decode( (string) get_the_title( $row['post_id'] ), ENT_QUOTES, 'UTF-8' ),
					$row['views'],
					number_format( $row['ctr'] * 100, 2 )
				);
			}
		}

		return $this->respond(
			$range,
			array(
				'totals'         => $totals,
				'previous'       => $previous,
				'top_links'      => $topLinks,
				'top_posts'      => $topPosts,
				'low_ctr_posts'  => $lowCtr,
				'notes'          => $notes,
			)
		);
	}

	public function updateLink( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$link = $this->links->findByCode( (string) $request->get_param( 'code' ) );

		if ( null === $link ) {
			return new \WP_Error( 'rlt_not_found', __( 'リンクが見つかりません。', 'rakuten-link-tracker' ), array( 'status' => 404 ) );
		}

		$fields = array();

		if ( null !== $request->get_param( 'target_url' ) ) {
			$raw = (string) $request->get_param( 'target_url' );

			if ( strlen( $raw ) > 2000 ) {
				return new \WP_Error(
					'rlt_invalid_url',
					__( '遷移先URLが長すぎます。', 'rakuten-link-tracker' ),
					array( 'status' => 400 )
				);
			}

			// esc_url_raw() only sanitises characters and rejects a scheme when one
			// is *present* -- a protocol-relative URL like "//evil.example.com/x"
			// has no scheme to reject, so it sails through unchanged, and a bare
			// "evil.example.com/x" is even normalised *into* a valid-looking
			// "http://evil.example.com/x" (WP assumes a missing scheme means a
			// relative URL and fills one in). Checking the scheme on the raw,
			// pre-sanitisation input -- the same thing RedirectHandler's own
			// scheme check protects against -- is what makes this endpoint's own
			// error message ("must start with http or https") actually true,
			// rather than accidentally true because of a second, independent
			// check elsewhere.
			$rawScheme = strtolower( (string) parse_url( $raw, PHP_URL_SCHEME ) );
			$url       = esc_url_raw( $raw, array( 'http', 'https' ) );

			if ( '' === $url || ! in_array( $rawScheme, array( 'http', 'https' ), true ) ) {
				return new \WP_Error(
					'rlt_invalid_url',
					__( '遷移先URLは http または https で始まる必要があります。', 'rakuten-link-tracker' ),
					array( 'status' => 400 )
				);
			}

			$fields['target_url'] = $url;
		}

		if ( null !== $request->get_param( 'label' ) ) {
			$fields['label'] = sanitize_text_field( (string) $request->get_param( 'label' ) );
		}

		if ( null !== $request->get_param( 'status' ) ) {
			$fields['status'] = $request->get_param( 'status' ) ? 1 : 0;
		}

		if ( array() === $fields ) {
			return new \WP_Error( 'rlt_nothing_to_update', __( '変更する項目がありません。', 'rakuten-link-tracker' ), array( 'status' => 400 ) );
		}

		$this->links->update( $link['id'], $fields );

		return new \WP_REST_Response( array( 'link' => $this->links->findById( $link['id'] ) ), 200 );
	}

	private function range( \WP_REST_Request $request ): DateRange {
		return DateRange::fromRequest(
			$request->get_param( 'from' ),
			$request->get_param( 'to' ),
			self::DEFAULT_DAYS
		);
	}

	private function limit( \WP_REST_Request $request ): int {
		return min( self::MAX_LIMIT, max( 1, (int) $request->get_param( 'limit' ) ) );
	}

	/**
	 * Every response states the window and timezone it was computed in, so a
	 * consumer never has to guess.
	 *
	 * @param array<string, mixed> $payload
	 */
	private function respond( DateRange $range, array $payload ): \WP_REST_Response {
		return new \WP_REST_Response(
			array_merge(
				array(
					'range'    => array(
						'from' => $range->fromDate(),
						'to'   => $range->toDate(),
						'days' => $range->dayCount(),
					),
					'timezone' => wp_timezone_string(),
				),
				$payload
			),
			200
		);
	}
}
