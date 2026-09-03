<?php

declare(strict_types=1);

namespace RLT;

use RLT\Data\EventRepository;

/**
 * Daily housekeeping: drop expired raw logs and rotate the visitor salt.
 *
 * Rotating the salt is what makes visitor hashes unlinkable across days, so this
 * job is part of the privacy design, not just cleanup.
 */
final class Cron {

	public const HOOK = 'rlt_daily_maintenance';

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public function run(): void {
		$days = (int) Settings::get( 'retention_days' );

		try {
			( new EventRepository() )->purgeOlderThan( $days );
		} catch ( \Throwable $e ) {
			error_log( '[rakuten-link-tracker] log purge failed: ' . $e->getMessage() );
		}

		Settings::rotateSalt();
	}
}
