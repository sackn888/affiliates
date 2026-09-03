<?php

namespace RLT\Tests\Integration;

use RLT\Cron;
use RLT\Data\LinkRepository;
use RLT\Installer;
use RLT\Settings;
use WP_UnitTestCase;

final class CronTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Cron::unschedule();
	}

	public function test_schedule_registers_a_daily_event(): void {
		Cron::schedule();

		$this->assertNotFalse( wp_next_scheduled( Cron::HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( Cron::HOOK ) );
	}

	public function test_schedule_is_idempotent(): void {
		Cron::schedule();
		$first = wp_next_scheduled( Cron::HOOK );

		Cron::schedule();

		$this->assertSame( $first, wp_next_scheduled( Cron::HOOK ) );
	}

	public function test_unschedule_clears_the_event(): void {
		Cron::schedule();
		Cron::unschedule();

		$this->assertFalse( wp_next_scheduled( Cron::HOOK ) );
	}

	public function test_run_rotates_the_salt(): void {
		$before = Settings::salt();

		( new Cron() )->run();

		$this->assertNotSame( $before, Settings::salt() );
	}

	public function test_run_purges_logs_older_than_the_retention_window(): void {
		global $wpdb;

		Settings::update( array( 'retention_days' => 30 ) );

		$link = ( new LinkRepository() )->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );

		$wpdb->insert(
			Installer::clicksTable(),
			array(
				'link_id'      => $link['id'],
				'post_id'      => 42,
				'clicked_at'   => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
				'visitor_hash' => str_pad( 'v', 64, '0' ),
				'referer'      => '',
				'device'       => 1,
				'is_bot'       => 0,
			)
		);

		( new Cron() )->run();

		$this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::clicksTable() ) );
	}

	public function test_run_keeps_everything_when_retention_is_zero(): void {
		global $wpdb;

		Settings::update( array( 'retention_days' => 0 ) );

		$link = ( new LinkRepository() )->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );

		$wpdb->insert(
			Installer::clicksTable(),
			array(
				'link_id'      => $link['id'],
				'post_id'      => 42,
				'clicked_at'   => gmdate( 'Y-m-d H:i:s', strtotime( '-1000 days' ) ),
				'visitor_hash' => str_pad( 'v', 64, '0' ),
				'referer'      => '',
				'device'       => 1,
				'is_bot'       => 0,
			)
		);

		( new Cron() )->run();

		$this->assertSame( '1', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::clicksTable() ) );
	}
}
