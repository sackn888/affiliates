<?php

namespace RLT\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RLT\Support\DeviceDetector;

final class DeviceDetectorTest extends TestCase {

	public function test_detects_desktop(): void {
		$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
		$this->assertSame( DeviceDetector::DESKTOP, DeviceDetector::detect( $ua ) );
	}

	public function test_detects_mobile(): void {
		$ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1';
		$this->assertSame( DeviceDetector::MOBILE, DeviceDetector::detect( $ua ) );
	}

	public function test_detects_android_mobile(): void {
		$ua = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';
		$this->assertSame( DeviceDetector::MOBILE, DeviceDetector::detect( $ua ) );
	}

	public function test_detects_ipad_as_tablet(): void {
		$ua = 'Mozilla/5.0 (iPad; CPU OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1';
		$this->assertSame( DeviceDetector::TABLET, DeviceDetector::detect( $ua ) );
	}

	public function test_detects_android_tablet(): void {
		// Android without the "Mobile" token means tablet.
		$ua = 'Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
		$this->assertSame( DeviceDetector::TABLET, DeviceDetector::detect( $ua ) );
	}

	public function test_empty_user_agent_is_unknown(): void {
		$this->assertSame( DeviceDetector::UNKNOWN, DeviceDetector::detect( '' ) );
	}

	public function test_labels_are_stable(): void {
		$this->assertSame( 'desktop', DeviceDetector::label( DeviceDetector::DESKTOP ) );
		$this->assertSame( 'mobile', DeviceDetector::label( DeviceDetector::MOBILE ) );
		$this->assertSame( 'tablet', DeviceDetector::label( DeviceDetector::TABLET ) );
		$this->assertSame( 'unknown', DeviceDetector::label( DeviceDetector::UNKNOWN ) );
		$this->assertSame( 'unknown', DeviceDetector::label( 99 ) );
	}
}
