<?php

namespace RLT\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RLT\Plugin;

final class SmokeTest extends TestCase {

	public function test_version_is_defined(): void {
		$this->assertSame( '1.0.1', Plugin::VERSION );
	}
}
