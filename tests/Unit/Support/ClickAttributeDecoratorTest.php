<?php

namespace RLT\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RLT\Support\ClickAttributeDecorator;

final class ClickAttributeDecoratorTest extends TestCase {

	private const SHORT = 'https://fukuoka.tabidachi.org/go/5fqm9k';

	/**
	 * The exact URL shape from the production bug report: the destination
	 * hotel id (9611) is URL-encoded inside the `pc` query parameter.
	 */
	private const AFFILIATE_URL_HOTEL_9611 = 'https://hb.afl.rakuten.co.jp/hgc/2452369c.9c4d0e37.2452369d.f06761a5/_RTLink137659'
		. '?pc=https%3A%2F%2Ftravel.rakuten.co.jp%2FHOTEL%2F9611%2F9611.html';

	private ClickAttributeDecorator $decorator;

	protected function setUp(): void {
		parent::setUp();
		$this->decorator = new ClickAttributeDecorator();
	}

	/**
	 * @return array<string, array{code: string, domain: string, label: string, target_url?: string}>
	 */
	private function map( array $overrides = array() ): array {
		return array(
			self::SHORT => array_merge(
				array(
					'code'   => '5fqm9k',
					'domain' => 'travel.rakuten.co.jp',
					'label'  => 'ホテルグレイスリー福岡',
				),
				$overrides
			),
		);
	}

	public function test_adds_all_four_attributes_with_correct_values(): void {
		$html = '<a href="' . self::SHORT . '" target="_blank" rel="noopener noreferrer">グレイスリー</a>';

		$result = $this->decorator->decorate( $html, $this->map() );

		$this->assertStringContainsString( 'data-ga4-click="affiliate"', $result );
		$this->assertStringContainsString( 'data-ga4-code="5fqm9k"', $result );
		$this->assertStringContainsString( 'data-ga4-domain="travel.rakuten.co.jp"', $result );
		$this->assertStringContainsString( 'data-ga4-label="ホテルグレイスリー福岡"', $result );
	}

	public function test_leaves_href_target_and_rel_byte_identical(): void {
		$html = '<a href="' . self::SHORT . '" target="_blank" rel="noopener noreferrer">グレイスリー</a>';

		$result = $this->decorator->decorate( $html, $this->map() );

		$this->assertStringContainsString( 'href="' . self::SHORT . '"', $result );
		$this->assertStringContainsString( 'target="_blank"', $result );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $result );
	}

	public function test_an_anchor_pointing_elsewhere_is_untouched(): void {
		$html = '<a href="https://example.org/other">よそ</a>';

		$this->assertSame( $html, $this->decorator->decorate( $html, $this->map() ) );
	}

	public function test_img_src_on_the_short_url_is_untouched(): void {
		$html = '<img src="' . self::SHORT . '" width="1" height="1">';

		$this->assertSame( $html, $this->decorator->decorate( $html, $this->map() ) );
	}

	public function test_is_idempotent_when_run_twice(): void {
		$html = '<a href="' . self::SHORT . '">グレイスリー</a>';

		$once  = $this->decorator->decorate( $html, $this->map() );
		$twice = $this->decorator->decorate( $once, $this->map() );

		$this->assertSame( $once, $twice );
	}

	public function test_an_anchor_that_already_has_data_ga4_click_is_left_alone(): void {
		$html = '<a href="' . self::SHORT . '" data-ga4-click="affiliate">グレイスリー</a>';

		$this->assertSame( $html, $this->decorator->decorate( $html, $this->map() ) );
	}

	public function test_handles_single_quoted_href(): void {
		$html = "<a href='" . self::SHORT . "'>グレイスリー</a>";

		$result = $this->decorator->decorate( $html, $this->map() );

		$this->assertStringContainsString( 'data-ga4-click="affiliate"', $result );
	}

	public function test_handles_attributes_appearing_before_href(): void {
		$html = '<a target="_blank" rel="noopener noreferrer" href="' . self::SHORT . '">グレイスリー</a>';

		$result = $this->decorator->decorate( $html, $this->map() );

		$this->assertStringContainsString( 'data-ga4-click="affiliate"', $result );
		$this->assertStringContainsString( 'target="_blank"', $result );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $result );
	}

	public function test_a_label_over_100_characters_is_cut_to_exactly_100_valid_utf8_characters(): void {
		$label = str_repeat( 'ホテルグレイスリー福岡博多', 20 ); // far more than 100 characters
		$this->assertGreaterThan( 100, mb_strlen( $label, 'UTF-8' ) );

		$html   = '<a href="' . self::SHORT . '">リンク</a>';
		$result = $this->decorator->decorate( $html, $this->map( array( 'label' => $label ) ) );

		$this->assertMatchesRegularExpression( '/data-ga4-label="([^"]*)"/u', $result, 'attribute must be present' );
		preg_match( '/data-ga4-label="([^"]*)"/u', $result, $m );

		$this->assertSame( 100, mb_strlen( $m[1], 'UTF-8' ) );
		$this->assertTrue( mb_check_encoding( $m[1], 'UTF-8' ) );
		$this->assertSame( mb_substr( $label, 0, 100, 'UTF-8' ), $m[1] );
	}

	public function test_a_short_url_under_an_older_prefix_is_still_decorated_when_present_in_the_map(): void {
		$oldShort = 'https://fukuoka.tabidachi.org/short/5fqm9k';
		$html     = '<a href="' . $oldShort . '">グレイスリー</a>';

		$map = array(
			$oldShort => array(
				'code'   => '5fqm9k',
				'domain' => 'travel.rakuten.co.jp',
				'label'  => 'ホテルグレイスリー福岡',
			),
		);

		$result = $this->decorator->decorate( $html, $map );

		$this->assertStringContainsString( 'data-ga4-click="affiliate"', $result );
		$this->assertStringContainsString( 'data-ga4-code="5fqm9k"', $result );
	}

	public function test_returns_content_unchanged_when_map_is_empty(): void {
		$html = '<a href="' . self::SHORT . '">グレイスリー</a>';

		$this->assertSame( $html, $this->decorator->decorate( $html, array() ) );
	}

	public function test_attribute_values_are_escaped(): void {
		$html = '<a href="' . self::SHORT . '">リンク</a>';

		$result = $this->decorator->decorate( $html, $this->map( array( 'label' => '"><script>alert(1)</script>' ) ) );

		$this->assertStringNotContainsString( '<script>', $result );
		$this->assertStringContainsString( '&quot;&gt;&lt;script&gt;', $result );
	}

	public function test_the_reported_url_appends_the_hotel_id_from_the_pc_parameter(): void {
		$html = '<a href="' . self::SHORT . '">楽天トラベルで見る</a>';

		$result = $this->decorator->decorate(
			$html,
			$this->map( array( 'label' => '楽天トラベルで見る', 'target_url' => self::AFFILIATE_URL_HOTEL_9611 ) )
		);

		$this->assertStringContainsString( 'data-ga4-label="楽天トラベルで見る (9611)"', $result );
	}

	public function test_two_links_with_identical_anchor_text_get_different_labels(): void {
		$shortA = 'https://fukuoka.tabidachi.org/go/aaaaaa';
		$shortB = 'https://fukuoka.tabidachi.org/go/bbbbbb';

		$html = '<a href="' . $shortA . '">楽天トラベルで見る</a> <a href="' . $shortB . '">楽天トラベルで見る</a>';

		$map = array(
			$shortA => array(
				'code'       => 'aaaaaa',
				'domain'     => 'travel.rakuten.co.jp',
				'label'      => '楽天トラベルで見る',
				'target_url' => 'https://hb.afl.rakuten.co.jp/hgc/aaa/_RTLink1?pc=' . rawurlencode( 'https://travel.rakuten.co.jp/HOTEL/1111/1111.html' ),
			),
			$shortB => array(
				'code'       => 'bbbbbb',
				'domain'     => 'travel.rakuten.co.jp',
				'label'      => '楽天トラベルで見る',
				'target_url' => 'https://hb.afl.rakuten.co.jp/hgc/bbb/_RTLink2?pc=' . rawurlencode( 'https://travel.rakuten.co.jp/HOTEL/2222/2222.html' ),
			),
		);

		$result = $this->decorator->decorate( $html, $map );

		$this->assertStringContainsString( 'data-ga4-label="楽天トラベルで見る (1111)"', $result );
		$this->assertStringContainsString( 'data-ga4-label="楽天トラベルで見る (2222)"', $result );
	}

	public function test_label_is_untouched_when_target_url_has_no_pc_parameter(): void {
		$html = '<a href="' . self::SHORT . '">楽天トラベルで見る</a>';

		$result = $this->decorator->decorate(
			$html,
			$this->map(
				array(
					'label'      => '楽天トラベルで見る',
					'target_url' => 'https://hb.afl.rakuten.co.jp/hgc/abc123/',
				)
			)
		);

		$this->assertStringContainsString( 'data-ga4-label="楽天トラベルで見る"', $result );
		$this->assertStringNotContainsString( '(', $result );
	}

	public function test_label_is_untouched_when_pc_is_blank(): void {
		$html = '<a href="' . self::SHORT . '">楽天トラベルで見る</a>';

		$result = $this->decorator->decorate(
			$html,
			$this->map(
				array(
					'label'      => '楽天トラベルで見る',
					'target_url' => 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=',
				)
			)
		);

		$this->assertStringContainsString( 'data-ga4-label="楽天トラベルで見る"', $result );
	}

	public function test_label_is_untouched_when_pc_path_has_no_digit_only_segment(): void {
		$html = '<a href="' . self::SHORT . '">楽天トラベルで見る</a>';

		$result = $this->decorator->decorate(
			$html,
			$this->map(
				array(
					'label'      => '楽天トラベルで見る',
					'target_url' => 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=' . rawurlencode( 'https://travel.rakuten.co.jp/HOTEL/abc/index.html' ),
				)
			)
		);

		$this->assertStringContainsString( 'data-ga4-label="楽天トラベルで見る"', $result );
	}

	public function test_label_is_untouched_when_pc_is_not_http(): void {
		$html = '<a href="' . self::SHORT . '">楽天トラベルで見る</a>';

		$result = $this->decorator->decorate(
			$html,
			$this->map(
				array(
					'label'      => '楽天トラベルで見る',
					'target_url' => 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=' . rawurlencode( 'javascript://travel.rakuten.co.jp/9611/' ),
				)
			)
		);

		$this->assertStringContainsString( 'data-ga4-label="楽天トラベルで見る"', $result );
	}

	public function test_label_is_untouched_when_target_url_is_absent(): void {
		$html = '<a href="' . self::SHORT . '">楽天トラベルで見る</a>';

		$result = $this->decorator->decorate( $html, $this->map( array( 'label' => '楽天トラベルで見る' ) ) );

		$this->assertStringContainsString( 'data-ga4-label="楽天トラベルで見る"', $result );
	}

	public function test_picks_the_first_all_digit_path_segment(): void {
		$html = '<a href="' . self::SHORT . '">楽天トラベルで見る</a>';

		$result = $this->decorator->decorate(
			$html,
			$this->map(
				array(
					'label'      => '楽天トラベルで見る',
					'target_url' => 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=' . rawurlencode( 'https://travel.rakuten.co.jp/HOTEL/9611/9611.html?plan=2222' ),
				)
			)
		);

		$this->assertStringContainsString( 'data-ga4-label="楽天トラベルで見る (9611)"', $result );
	}

	public function test_decorating_already_decorated_html_does_not_append_the_id_twice(): void {
		$html = '<a href="' . self::SHORT . '">楽天トラベルで見る</a>';
		$map  = $this->map( array( 'label' => '楽天トラベルで見る', 'target_url' => self::AFFILIATE_URL_HOTEL_9611 ) );

		$once  = $this->decorator->decorate( $html, $map );
		$twice = $this->decorator->decorate( $once, $map );

		$this->assertSame( $once, $twice );
		$this->assertSame( 1, substr_count( $once, '(9611)' ) );
	}

	public function test_a_label_already_ending_with_the_same_id_suffix_is_not_doubled(): void {
		$html = '<a href="' . self::SHORT . '">楽天トラベルで見る (9611)</a>';

		$result = $this->decorator->decorate(
			$html,
			$this->map( array( 'label' => '楽天トラベルで見る (9611)', 'target_url' => self::AFFILIATE_URL_HOTEL_9611 ) )
		);

		preg_match( '/data-ga4-label="([^"]*)"/u', $result, $m );

		$this->assertSame( '楽天トラベルで見る (9611)', $m[1] );
		$this->assertSame( 1, substr_count( $m[1], '(9611)' ) );
	}

	public function test_a_long_japanese_label_plus_id_is_truncated_to_100_chars_and_keeps_the_id(): void {
		$label = str_repeat( 'ホテルグレイスリー福岡博多', 20 ); // far more than 100 characters
		$this->assertGreaterThan( 100, mb_strlen( $label, 'UTF-8' ) );

		$html = '<a href="' . self::SHORT . '">リンク</a>';

		$result = $this->decorator->decorate(
			$html,
			$this->map( array( 'label' => $label, 'target_url' => self::AFFILIATE_URL_HOTEL_9611 ) )
		);

		$this->assertMatchesRegularExpression( '/data-ga4-label="([^"]*)"/u', $result );
		preg_match( '/data-ga4-label="([^"]*)"/u', $result, $m );

		$this->assertLessThanOrEqual( 100, mb_strlen( $m[1], 'UTF-8' ) );
		$this->assertTrue( mb_check_encoding( $m[1], 'UTF-8' ) );
		$this->assertStringEndsWith( ' (9611)', $m[1] );
	}
}
