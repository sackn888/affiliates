<?php

namespace RLT\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use RLT\Admin\SvgChart;

final class SvgChartTest extends TestCase {

	private function points(): array {
		return array(
			array( 'label' => '2026-09-01', 'value' => 3 ),
			array( 'label' => '2026-09-02', 'value' => 0 ),
			array( 'label' => '2026-09-03', 'value' => 9 ),
		);
	}

	public function test_renders_an_svg_element(): void {
		$svg = SvgChart::bars( $this->points(), '#2271b1', 'クリック数' );

		$this->assertStringStartsWith( '<svg', $svg );
		$this->assertStringEndsWith( '</svg>', $svg );
		$this->assertStringContainsString( 'viewBox=', $svg );
	}

	public function test_renders_one_rect_per_point(): void {
		$svg = SvgChart::bars( $this->points(), '#2271b1', 'クリック数' );

		$this->assertSame( 3, substr_count( $svg, '<rect' ) );
	}

	public function test_uses_the_given_colour(): void {
		$svg = SvgChart::bars( $this->points(), '#d63638', 'クリック数' );

		$this->assertStringContainsString( '#d63638', $svg );
	}

	public function test_includes_the_title_for_accessibility(): void {
		$svg = SvgChart::bars( $this->points(), '#2271b1', 'クリック数' );

		$this->assertStringContainsString( '<title>クリック数</title>', $svg );
		$this->assertStringContainsString( 'role="img"', $svg );
	}

	public function test_shows_the_maximum_value(): void {
		$svg = SvgChart::bars( $this->points(), '#2271b1', 'クリック数' );

		$this->assertStringContainsString( '>9<', $svg );
	}

	public function test_all_zero_values_do_not_divide_by_zero(): void {
		$points = array(
			array( 'label' => '2026-09-01', 'value' => 0 ),
			array( 'label' => '2026-09-02', 'value' => 0 ),
		);

		$svg = SvgChart::bars( $points, '#2271b1', 'クリック数' );

		$this->assertStringContainsString( '<svg', $svg );
		$this->assertStringNotContainsString( 'NAN', strtoupper( $svg ) );
		$this->assertStringNotContainsString( 'INF', strtoupper( $svg ) );
	}

	public function test_empty_input_renders_a_placeholder_not_a_crash(): void {
		$svg = SvgChart::bars( array(), '#2271b1', 'クリック数' );

		$this->assertStringContainsString( '<svg', $svg );
		$this->assertStringNotContainsString( '<rect', $svg );
	}

	public function test_labels_are_escaped(): void {
		$points = array( array( 'label' => '<script>alert(1)</script>', 'value' => 1 ) );

		$svg = SvgChart::bars( $points, '#2271b1', '<img onerror=x>' );

		$this->assertStringNotContainsString( '<script>', $svg );
		$this->assertStringNotContainsString( '<img', $svg );
	}

	public function test_each_bar_carries_a_tooltip_title(): void {
		$svg = SvgChart::bars( $this->points(), '#2271b1', 'クリック数' );

		$this->assertStringContainsString( '2026-09-03: 9', $svg );
	}
}
