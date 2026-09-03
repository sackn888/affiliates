<?php

declare(strict_types=1);

namespace RLT\Admin;

/**
 * Draws a bar chart as inline SVG.
 *
 * Bundling a charting library would bloat the plugin, and loading one from a CDN
 * fails silently wherever outbound requests are blocked. A few dozen bars do not
 * need either.
 *
 * Pure: no WordPress dependency, so it can be unit tested directly.
 */
final class SvgChart {

	private const VIEW_WIDTH  = 720;
	private const PADDING_TOP = 16;
	private const AXIS_HEIGHT = 18;
	private const BAR_GAP     = 2;

	/**
	 * @param array<int, array{label: string, value: int}> $points
	 */
	public static function bars( array $points, string $color, string $title, int $height = 160 ): string {
		$plotHeight = max( 20, $height - self::PADDING_TOP - self::AXIS_HEIGHT );

		$svg = sprintf(
			'<svg class="rlt-chart" role="img" viewBox="0 0 %d %d" preserveAspectRatio="none" style="width:100%%;height:%dpx">',
			self::VIEW_WIDTH,
			$height,
			$height
		);

		$svg .= '<title>' . self::escape( $title ) . '</title>';

		if ( array() === $points ) {
			$svg .= sprintf(
				'<text x="%d" y="%d" text-anchor="middle" font-size="12" fill="#787c82">no data</text>',
				(int) ( self::VIEW_WIDTH / 2 ),
				(int) ( $height / 2 )
			);

			return $svg . '</svg>';
		}

		$values = array_map( static fn ( array $p ): int => (int) $p['value'], $points );
		$max    = max( $values );
		$scale  = $max > 0 ? $max : 1;

		$slot  = self::VIEW_WIDTH / count( $points );
		$width = max( 1.0, $slot - self::BAR_GAP );

		// Baseline.
		$svg .= sprintf(
			'<line x1="0" y1="%1$d" x2="%2$d" y2="%1$d" stroke="#dcdcde" stroke-width="1" />',
			self::PADDING_TOP + $plotHeight,
			self::VIEW_WIDTH
		);

		foreach ( $points as $index => $point ) {
			$value     = (int) $point['value'];
			$barHeight = $value > 0 ? max( 1.0, ( $value / $scale ) * $plotHeight ) : 0.0;
			$x         = $index * $slot;
			$y         = self::PADDING_TOP + $plotHeight - $barHeight;

			$svg .= sprintf(
				'<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" fill="%s" rx="1"><title>%s: %d</title></rect>',
				$x,
				$y,
				$width,
				$barHeight,
				self::escape( $color ),
				self::escape( (string) $point['label'] ),
				$value
			);
		}

		// Peak value and the range of dates covered.
		$svg .= sprintf(
			'<text x="2" y="12" font-size="11" fill="#50575e">%d</text>',
			$max
		);

		$svg .= sprintf(
			'<text x="2" y="%d" font-size="11" fill="#787c82">%s</text>',
			$height - 4,
			self::escape( (string) $points[0]['label'] )
		);

		$svg .= sprintf(
			'<text x="%d" y="%d" text-anchor="end" font-size="11" fill="#787c82">%s</text>',
			self::VIEW_WIDTH - 2,
			$height - 4,
			self::escape( (string) $points[ count( $points ) - 1 ]['label'] )
		);

		return $svg . '</svg>';
	}

	public static function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
