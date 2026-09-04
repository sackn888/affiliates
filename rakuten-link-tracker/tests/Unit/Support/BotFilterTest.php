<?php

namespace RLT\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RLT\Support\BotFilter;

final class BotFilterTest extends TestCase {

	/**
	 * @dataProvider botAgents
	 */
	public function test_detects_bots( string $ua ): void {
		$this->assertTrue( BotFilter::isBot( $ua ) );
	}

	public static function botAgents(): array {
		return array(
			'googlebot'   => array( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ),
			'bingbot'     => array( 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)' ),
			'yahoo slurp' => array( 'Mozilla/5.0 (compatible; Yahoo! Slurp; http://help.yahoo.com/help/us/ysearch/slurp)' ),
			'facebook'    => array( 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)' ),
			'twitter'     => array( 'Twitterbot/1.0' ),
			'ahrefs'      => array( 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)' ),
			'headless'    => array( 'Mozilla/5.0 (X11; Linux x86_64) HeadlessChrome/120.0.0.0 Safari/537.36' ),
			'curl'        => array( 'curl/8.4.0' ),
			'wget'        => array( 'Wget/1.21.3' ),
			'python'      => array( 'python-requests/2.31.0' ),
			'wordpress'   => array( 'WordPress/6.5; https://example.com' ),
			'empty ua'    => array( '' ),
		);
	}

	/**
	 * @dataProvider humanAgents
	 */
	public function test_does_not_flag_humans( string $ua ): void {
		$this->assertFalse( BotFilter::isBot( $ua ) );
	}

	public static function humanAgents(): array {
		return array(
			'chrome windows' => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36' ),
			'safari iphone'  => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1' ),
			'firefox mac'    => array( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:121.0) Gecko/20100101 Firefox/121.0' ),
			'android chrome' => array( 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36' ),
			'edge'           => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0' ),
		);
	}

	public function test_is_case_insensitive(): void {
		$this->assertTrue( BotFilter::isBot( 'SOME-CRAWLER/1.0' ) );
	}
}
