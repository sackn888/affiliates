<?php

declare(strict_types=1);

namespace RLT\Frontend;

use RLT\Data\EventRepository;
use RLT\Data\LinkRepository;
use RLT\Plugin;
use RLT\Settings;
use RLT\Support\RequestContext;

/**
 * Counts article pageviews via a JavaScript beacon.
 *
 * Counting in PHP would be simpler, but any page cache or CDN would serve most
 * requests without running PHP at all and the numbers would be badly short.
 *
 * There is deliberately no nonce: a cached page hands every visitor the same
 * stale nonce, which breaks the endpoint entirely. Since the endpoint only
 * increments an anonymous counter, origin checking and deduplication are
 * proportionate protection.
 */
final class BeaconController {

	public const REST_NAMESPACE = 'rlt/v1';

	private LinkRepository $links;
	private EventRepository $events;

	public function __construct( ?LinkRepository $links = null, ?EventRepository $events = null ) {
		$this->links  = $links ?? new LinkRepository();
		$this->events = $events ?? new EventRepository();
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function registerRoutes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/view',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handleView' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	public function handleView( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->originLooksLocal() ) {
			return new \WP_REST_Response( array( 'recorded' => false ), 403 );
		}

		$postId = (int) $request->get_param( 'post_id' );
		$post   = get_post( $postId );

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return new \WP_REST_Response( array( 'recorded' => false ), 404 );
		}

		try {
			$recorded = $this->trackView( $postId );
		} catch ( \Throwable $e ) {
			error_log( '[rakuten-link-tracker] view tracking failed: ' . $e->getMessage() );
			$recorded = false;
		}

		return new \WP_REST_Response( array( 'recorded' => $recorded ), 200 );
	}

	public function trackView( int $postId ): bool {
		if ( Settings::get( 'exclude_logged_in' ) && is_user_logged_in() ) {
			return false;
		}

		$window = (int) Settings::get( 'view_dedup_seconds' );

		if ( $this->events->hasRecentView( $postId, RequestContext::visitorHash(), $window ) ) {
			return false;
		}

		return $this->events->recordView( $postId );
	}

	public function shouldEnqueue(): bool {
		if ( ! is_singular() ) {
			return false;
		}

		if ( Settings::get( 'exclude_logged_in' ) && is_user_logged_in() ) {
			return false;
		}

		$postId = get_queried_object_id();

		return $postId > 0 && $this->links->countActiveForPost( $postId ) > 0;
	}

	public function enqueue(): void {
		if ( ! $this->shouldEnqueue() ) {
			return;
		}

		wp_enqueue_script(
			'rlt-beacon',
			Plugin::url( 'assets/beacon.js' ),
			array(),
			Plugin::VERSION,
			true
		);

		wp_add_inline_script(
			'rlt-beacon',
			'window.rltBeacon = ' . wp_json_encode(
				array(
					'endpoint' => rest_url( self::REST_NAMESPACE . '/view' ),
					'postId'   => get_queried_object_id(),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Reject only an explicit cross-site origin.
	 *
	 * Some privacy settings strip both Origin and Referer; treating that as an
	 * attack would silently lose real pageviews.
	 */
	private function originLooksLocal(): bool {
		$homeHost = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		foreach ( array( 'HTTP_ORIGIN', 'HTTP_REFERER' ) as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			$host = (string) wp_parse_url( (string) wp_unslash( $_SERVER[ $key ] ), PHP_URL_HOST );

			if ( '' !== $host && $host !== $homeHost ) {
				return false;
			}
		}

		return true;
	}
}
