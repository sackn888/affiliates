<?php

namespace RLT\Tests\Integration;

use WP_UnitTestCase;

final class BootstrapTest extends WP_UnitTestCase {

	public function test_plugin_file_is_loaded(): void {
		$this->assertTrue( defined( 'RLT_PLUGIN_FILE' ) );
	}
}
