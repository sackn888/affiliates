<?php

namespace RLT\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RLT\Plugin;

final class SmokeTest extends TestCase {

	public function test_version_is_defined(): void {
		$this->assertSame( '1.2.0', Plugin::VERSION );
	}

	public function test_plugin_header_version_matches_plugin_version_constant(): void {
		$pluginFile = dirname( __DIR__, 2 ) . '/rakuten-link-tracker/rakuten-link-tracker.php';
		$contents   = (string) file_get_contents( $pluginFile );

		$this->assertMatchesRegularExpression( '/^\s*\*\s*Version:\s*([0-9.]+)/m', $contents );
		preg_match( '/^\s*\*\s*Version:\s*([0-9.]+)/m', $contents, $m );

		$this->assertSame( Plugin::VERSION, $m[1] );
	}
}
