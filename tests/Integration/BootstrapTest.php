<?php

namespace RLT\Tests\Integration;

use WP_UnitTestCase;

final class BootstrapTest extends WP_UnitTestCase {

	public function test_plugin_file_is_loaded(): void {
		$this->assertTrue( defined( 'RLT_PLUGIN_FILE' ) );
	}

	public function test_the_header_version_matches_the_version_constant(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$header = get_plugin_data( RLT_PLUGIN_FILE, false, false );

		// Plugin::VERSION はアセットのキャッシュバスターに使われる。
		// ヘッダとずれると、更新しても古い JS/CSS が配られ続ける。
		$this->assertSame( $header['Version'], \RLT\Plugin::VERSION );
	}
}
