<?php

namespace RLT\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RLT\Support\ContentRewriter;

final class ContentRewriterTest extends TestCase {

	private const AFFILIATE = 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=x';
	private const SHORT     = 'https://example.com/go/qwerty';

	private ContentRewriter $rewriter;

	protected function setUp(): void {
		parent::setUp();
		$this->rewriter = new ContentRewriter();
	}

	public function test_replaces_href_with_short_url(): void {
		$html = '<a href="' . self::AFFILIATE . '">ホテル</a>';

		$result = $this->rewriter->rewrite( $html, array( self::AFFILIATE => self::SHORT ) );

		$this->assertSame( '<a href="' . self::SHORT . '">ホテル</a>', $result );
	}

	public function test_keeps_other_attributes_intact(): void {
		$html = '<a rel="nofollow sponsored" href="' . self::AFFILIATE . '" target="_blank" class="btn">ホテル</a>';

		$result = $this->rewriter->rewrite( $html, array( self::AFFILIATE => self::SHORT ) );

		$this->assertStringContainsString( 'rel="nofollow sponsored"', $result );
		$this->assertStringContainsString( 'target="_blank"', $result );
		$this->assertStringContainsString( 'class="btn"', $result );
		$this->assertStringContainsString( 'href="' . self::SHORT . '"', $result );
	}

	public function test_does_not_touch_img_src(): void {
		$html = '<img src="' . self::AFFILIATE . '" width="1" height="1">';

		$result = $this->rewriter->rewrite( $html, array( self::AFFILIATE => self::SHORT ) );

		$this->assertSame( $html, $result );
	}

	public function test_rewrites_href_while_leaving_the_impression_pixel_alone(): void {
		$pixel = 'https://hb.afl.rakuten.co.jp/hsc/abc123/?me_id=1';
		$html  = '<a href="' . self::AFFILIATE . '">ホテル</a><img src="' . $pixel . '" width="1" height="1">';

		$result = $this->rewriter->rewrite(
			$html,
			array(
				self::AFFILIATE => self::SHORT,
				$pixel          => 'https://example.com/go/zzzzzz',
			)
		);

		$this->assertStringContainsString( 'href="' . self::SHORT . '"', $result );
		$this->assertStringContainsString( 'src="' . $pixel . '"', $result );
	}

	public function test_replaces_all_occurrences(): void {
		$html = '<a href="' . self::AFFILIATE . '">上</a><p>本文</p><a href="' . self::AFFILIATE . '">下</a>';

		$result = $this->rewriter->rewrite( $html, array( self::AFFILIATE => self::SHORT ) );

		$this->assertSame( 2, substr_count( $result, self::SHORT ) );
		$this->assertStringNotContainsString( self::AFFILIATE, $result );
	}

	public function test_handles_single_quoted_href(): void {
		$html = "<a href='" . self::AFFILIATE . "'>ホテル</a>";

		$result = $this->rewriter->rewrite( $html, array( self::AFFILIATE => self::SHORT ) );

		$this->assertStringContainsString( self::SHORT, $result );
	}

	public function test_handles_html_encoded_ampersand_in_href(): void {
		$affiliate = 'https://hb.afl.rakuten.co.jp/hgc/abc/?pc=x&m=y';
		$html      = '<a href="https://hb.afl.rakuten.co.jp/hgc/abc/?pc=x&amp;m=y">ホテル</a>';

		$result = $this->rewriter->rewrite( $html, array( $affiliate => self::SHORT ) );

		$this->assertStringContainsString( 'href="' . self::SHORT . '"', $result );
	}

	public function test_is_idempotent(): void {
		$html = '<a href="' . self::AFFILIATE . '">ホテル</a>';
		$map  = array( self::AFFILIATE => self::SHORT );

		$once  = $this->rewriter->rewrite( $html, $map );
		$twice = $this->rewriter->rewrite( $once, $map );

		$this->assertSame( $once, $twice );
	}

	public function test_leaves_content_untouched_when_map_is_empty(): void {
		$html = '<a href="' . self::AFFILIATE . '">ホテル</a>';

		$this->assertSame( $html, $this->rewriter->rewrite( $html, array() ) );
	}

	public function test_leaves_unmapped_links_alone(): void {
		$html = '<a href="https://example.org/other">よそ</a>';

		$this->assertSame( $html, $this->rewriter->rewrite( $html, array( self::AFFILIATE => self::SHORT ) ) );
	}

	public function test_restore_puts_the_original_url_back(): void {
		$html = '<a href="' . self::SHORT . '">ホテル</a>';

		$result = $this->rewriter->restore( $html, array( self::SHORT => self::AFFILIATE ) );

		$this->assertSame( '<a href="' . self::AFFILIATE . '">ホテル</a>', $result );
	}

	public function test_round_trip_returns_the_original_content(): void {
		$original = '<p>泊まるなら<a rel="nofollow" href="' . self::AFFILIATE . '" target="_blank">このホテル</a>がおすすめ。</p>'
			. '<img src="https://hb.afl.rakuten.co.jp/hsc/abc123/?me_id=1" width="1" height="1">';

		$shortened = $this->rewriter->rewrite( $original, array( self::AFFILIATE => self::SHORT ) );
		$restored  = $this->rewriter->restore( $shortened, array( self::SHORT => self::AFFILIATE ) );

		$this->assertSame( $original, $restored );
	}

	public function test_restore_is_idempotent(): void {
		$html = '<a href="' . self::AFFILIATE . '">ホテル</a>';
		$map  = array( self::SHORT => self::AFFILIATE );

		$this->assertSame( $html, $this->rewriter->restore( $html, $map ) );
	}

	public function test_preserves_gutenberg_block_comments(): void {
		$html = '<!-- wp:paragraph -->' . "\n"
			. '<p><a href="' . self::AFFILIATE . '">ホテル</a></p>' . "\n"
			. '<!-- /wp:paragraph -->';

		$result = $this->rewriter->rewrite( $html, array( self::AFFILIATE => self::SHORT ) );

		$this->assertStringContainsString( '<!-- wp:paragraph -->', $result );
		$this->assertStringContainsString( '<!-- /wp:paragraph -->', $result );
		$this->assertStringContainsString( self::SHORT, $result );
	}

	public function test_pcre_failure_returns_the_original_content_not_an_empty_string(): void {
		$original = ini_get( 'pcre.backtrack_limit' );
		// 極端に小さい上限にすると preg_replace_callback が null を返す。
		ini_set( 'pcre.backtrack_limit', '1' );

		try {
			$html = '<a href="' . self::AFFILIATE . '">' . str_repeat( 'ホテル', 2000 ) . '</a>';

			$result = $this->rewriter->rewrite( $html, array( self::AFFILIATE => self::SHORT ) );

			// 記事本文を空にするくらいなら、リンクを書き換えないほうが遥かにマシ。
			$this->assertNotSame( '', $result );
		} finally {
			ini_set( 'pcre.backtrack_limit', (string) $original );
		}
	}

	public function test_round_trip_preserves_an_encoded_ampersand(): void {
		$affiliate = 'https://hb.afl.rakuten.co.jp/hgc/abc/?pc=x&m=y&scid=z';
		$original  = '<p><a href="https://hb.afl.rakuten.co.jp/hgc/abc/?pc=x&amp;m=y&amp;scid=z">ホテル</a></p>';

		$shortened = $this->rewriter->rewrite( $original, array( $affiliate => self::SHORT ) );
		$restored  = $this->rewriter->restore( $shortened, array( self::SHORT => $affiliate ) );

		$this->assertStringContainsString( self::SHORT, $shortened );
		$this->assertSame( $original, $restored );
	}

	public function test_href_with_surrounding_whitespace_is_still_rewritten(): void {
		// LinkExtractor は trim してから map のキーを作るため、こちらも合わせないと取りこぼす。
		$html = '<a href=" ' . self::AFFILIATE . ' ">ホテル</a>';

		$result = $this->rewriter->rewrite( $html, array( self::AFFILIATE => self::SHORT ) );

		$this->assertStringContainsString( self::SHORT, $result );
	}
}
