<?php

declare(strict_types=1);

namespace RLT\Api;

use RLT\Data\EventRepository;
use RLT\Support\DateRange;

/**
 * Serves the raw logs as CSV.
 *
 * The REST server would normally JSON-encode the response, so a marker header
 * tells serve() to emit the body verbatim instead.
 */
final class ExportController {

	private const MARKER = 'X-RLT-Raw-Csv';

	private const CLICK_HEADERS = array( 'clicked_at', 'code', 'label', 'post_id', 'target_url', 'referer', 'device', 'is_bot' );
	private const VIEW_HEADERS  = array( 'viewed_at', 'post_id', 'referer', 'device', 'is_bot' );

	private EventRepository $events;
	private StatsController $stats;

	public function __construct( ?EventRepository $events = null ) {
		$this->events = $events ?? new EventRepository();
		$this->stats  = new StatsController();
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve' ), 10, 4 );
	}

	public function registerRoutes(): void {
		$args = array(
			'from'         => array( 'type' => 'string' ),
			'to'           => array( 'type' => 'string' ),
			'include_bots' => array( 'type' => 'boolean', 'default' => false ),
		);

		register_rest_route(
			StatsController::REST_NAMESPACE,
			'/export/clicks',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'exportClicks' ),
				'permission_callback' => array( $this->stats, 'readPermission' ),
				'args'                => $args,
			)
		);

		register_rest_route(
			StatsController::REST_NAMESPACE,
			'/export/views',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'exportViews' ),
				'permission_callback' => array( $this->stats, 'readPermission' ),
				'args'                => $args,
			)
		);
	}

	public function exportClicks( \WP_REST_Request $request ): \WP_REST_Response {
		$range = DateRange::fromRequest( $request->get_param( 'from' ), $request->get_param( 'to' ), 28 );
		$rows  = $this->events->clicksForExport( $range, (bool) $request->get_param( 'include_bots' ) );

		return $this->csvResponse( $this->toCsv( $rows, self::CLICK_HEADERS ), 'clicks', $range );
	}

	public function exportViews( \WP_REST_Request $request ): \WP_REST_Response {
		$range = DateRange::fromRequest( $request->get_param( 'from' ), $request->get_param( 'to' ), 28 );
		$rows  = $this->events->viewsForExport( $range, (bool) $request->get_param( 'include_bots' ) );

		return $this->csvResponse( $this->toCsv( $rows, self::VIEW_HEADERS ), 'views', $range );
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @param string[]                         $headers
	 */
	public function toCsv( array $rows, array $headers ): string {
		$handle = fopen( 'php://temp', 'r+' );

		fputcsv( $handle, $headers );

		foreach ( $rows as $row ) {
			$line = array();
			foreach ( $headers as $header ) {
				$line[] = $row[ $header ] ?? '';
			}
			fputcsv( $handle, $line );
		}

		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle );

		return $csv;
	}

	/**
	 * Emit the CSV body untouched instead of letting the REST server JSON-encode
	 * it. Only a response carrying this controller's own marker header is
	 * touched -- every other endpoint's WP_REST_Response (including plain JSON
	 * from StatsController) falls through unchanged, and $result is not typed
	 * to WP_REST_Response because rest_pre_serve_request can be called with a
	 * bare array or scalar for some error paths.
	 *
	 * @param mixed $result
	 */
	public function serve( bool $served, $result, \WP_REST_Request $request, \WP_REST_Server $server ): bool {
		if ( $served || ! $result instanceof \WP_REST_Response ) {
			return $served;
		}

		$headers = $result->get_headers();

		if ( empty( $headers[ self::MARKER ] ) ) {
			return $served;
		}

		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput

		return true;
	}

	private function csvResponse( string $csv, string $kind, DateRange $range ): \WP_REST_Response {
		$filename = sprintf( 'rlt-%s-%s_%s.csv', $kind, $range->fromDate(), $range->toDate() );

		$response = new \WP_REST_Response( $csv, 200 );
		$response->set_headers(
			array(
				'Content-Type'        => 'text/csv; charset=utf-8',
				'Content-Disposition' => 'attachment; filename="' . $filename . '"',
				self::MARKER          => '1',
			)
		);

		return $response;
	}
}
