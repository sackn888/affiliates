<?php

namespace RLT\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RLT\Support\LinkExtractor;

final class LinkExtractorTest extends TestCase {

	private const HOSTS      = array( 'hb.afl.rakuten.co.jp', 'af.rakuten.co.jp' );
	private const SHORT_BASE = 'https://example.com/go/';

	private function extractor(): LinkExtractor {
		return new LinkExtractor( self::HOSTS, self::SHORT_BASE );
	}

	public function test_extracts_a_simple_affiliate_link(): void {
		$html = '<p><a href="https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=https%3A%2F%2Ftravel.rakuten.co.jp%2F">ホテル雅叙園東京</a></p>';

		$links = $this->extractor()->extract( $html );

		$this->assertCount( 1, $links );
		$this->assertSame( 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=https%3A%2F%2Ftravel.rakuten.co.jp%2F', $links[0]['url'] );
		$this->assertSame( 'ホテル雅叙園東京', $links[0]['label'] );
	}

	public function test_ignores_non_rakuten_links(): void {
		$html = '<a href="https://example.org/page">よそ</a><a href="https://www.google.com/">検索</a>';

		$this->assertSame( array(), $this->extractor()->extract( $html ) );
	}

	public function test_does_not_touch_img_src(): void {
		// 楽天のバナーHTMLはインプレッション計測用の 1x1 画像を含む。
		// これを拾って書き換えると計測が壊れるため、絶対に対象にしない。
		$html = '<img src="https://hb.afl.rakuten.co.jp/hsc/abc123/?me_id=1&me_adv_id=2" width="1" height="1" border="0">';

		$this->assertSame( array(), $this->extractor()->extract( $html ) );
	}

	public function test_extracts_href_but_not_src_in_the_same_banner(): void {
		$html = '<a href="https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=x" target="_blank">'
			. '<img src="https://hbb.afl.rakuten.co.jp/banner.gif" alt="楽天トラベル"></a>'
			. '<img src="https://hb.afl.rakuten.co.jp/hsc/abc123/?me_id=1" width="1" height="1">';

		$links = $this->extractor()->extract( $html );

		$this->assertCount( 1, $links );
		$this->assertSame( 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=x', $links[0]['url'] );
	}

	public function test_uses_img_alt_as_label_when_anchor_has_no_text(): void {
		$html = '<a href="https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=x"><img src="https://img.example/b.gif" alt="変なホテル東京"></a>';

		$links = $this->extractor()->extract( $html );

		$this->assertSame( '変なホテル東京', $links[0]['label'] );
	}

	public function test_falls_back_to_url_fragment_when_no_text_and_no_alt(): void {
		$html = '<a href="https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=x"><img src="https://img.example/b.gif"></a>';

		$links = $this->extractor()->extract( $html );

		$this->assertNotSame( '', $links[0]['label'] );
		$this->assertStringContainsString( 'hb.afl.rakuten.co.jp', $links[0]['label'] );
	}

	public function test_skips_urls_already_shortened(): void {
		$html = '<a href="https://example.com/go/abc123">変換済み</a>';

		$this->assertSame( array(), $this->extractor()->extract( $html ) );
	}

	public function test_is_idempotent_on_mixed_content(): void {
		$html = '<a href="https://example.com/go/abc123">変換済み</a>'
			. '<a href="https://hb.afl.rakuten.co.jp/hgc/xyz/?pc=y">未変換</a>';

		$links = $this->extractor()->extract( $html );

		$this->assertCount( 1, $links );
		$this->assertSame( 'https://hb.afl.rakuten.co.jp/hgc/xyz/?pc=y', $links[0]['url'] );
	}

	public function test_handles_single_quoted_attributes(): void {
		$html = "<a href='https://hb.afl.rakuten.co.jp/hgc/abc/?pc=x'>シングルクォート</a>";

		$links = $this->extractor()->extract( $html );

		$this->assertCount( 1, $links );
		$this->assertSame( 'https://hb.afl.rakuten.co.jp/hgc/abc/?pc=x', $links[0]['url'] );
	}

	public function test_handles_attributes_before_href(): void {
		$html = '<a rel="nofollow sponsored" target="_blank" href="https://hb.afl.rakuten.co.jp/hgc/abc/?pc=x" class="btn">順序違い</a>';

		$links = $this->extractor()->extract( $html );

		$this->assertCount( 1, $links );
		$this->assertSame( 'https://hb.afl.rakuten.co.jp/hgc/abc/?pc=x', $links[0]['url'] );
	}

	public function test_deduplicates_the_same_url(): void {
		$html = '<a href="https://hb.afl.rakuten.co.jp/hgc/abc/?pc=x">上のボタン</a>'
			. '<p>本文</p>'
			. '<a href="https://hb.afl.rakuten.co.jp/hgc/abc/?pc=x">下のボタン</a>';

		$links = $this->extractor()->extract( $html );

		$this->assertCount( 1, $links );
		// 最初に現れたラベルを採用する。
		$this->assertSame( '上のボタン', $links[0]['label'] );
	}

	public function test_preserves_first_appearance_order(): void {
		$html = '<a href="https://hb.afl.rakuten.co.jp/hgc/one/?pc=x">A</a>'
			. '<a href="https://af.rakuten.co.jp/two">B</a>';

		$links = $this->extractor()->extract( $html );

		$this->assertSame( 'A', $links[0]['label'] );
		$this->assertSame( 'B', $links[1]['label'] );
	}

	public function test_decodes_html_entities_in_url(): void {
		// WordPress は & を &amp; として保存することがある。
		$html = '<a href="https://hb.afl.rakuten.co.jp/hgc/abc/?pc=x&amp;m=y">エンティティ</a>';

		$links = $this->extractor()->extract( $html );

		$this->assertSame( 'https://hb.afl.rakuten.co.jp/hgc/abc/?pc=x&m=y', $links[0]['url'] );
	}

	public function test_trims_and_collapses_label_whitespace(): void {
		$html = "<a href=\"https://hb.afl.rakuten.co.jp/hgc/abc/?pc=x\">\n  ホテル  名前\n</a>";

		$links = $this->extractor()->extract( $html );

		$this->assertSame( 'ホテル 名前', $links[0]['label'] );
	}

	public function test_strips_nested_tags_from_label(): void {
		$html = '<a href="https://hb.afl.rakuten.co.jp/hgc/abc/?pc=x"><strong>太字</strong>のホテル</a>';

		$links = $this->extractor()->extract( $html );

		$this->assertSame( '太字のホテル', $links[0]['label'] );
	}

	public function test_truncates_long_labels(): void {
		$long = str_repeat( 'あ', 300 );
		$html = '<a href="https://hb.afl.rakuten.co.jp/hgc/abc/?pc=x">' . $long . '</a>';

		$links = $this->extractor()->extract( $html );

		// DB の VARCHAR(255) に収まる必要がある。
		$this->assertLessThanOrEqual( 255, strlen( $links[0]['label'] ) );
	}

	public function test_returns_empty_array_for_empty_content(): void {
		$this->assertSame( array(), $this->extractor()->extract( '' ) );
	}

	public function test_matches_host_exactly_not_as_substring(): void {
		// 攻撃的なドメインを誤って対象にしない。
		$html = '<a href="https://hb.afl.rakuten.co.jp.evil.example/x">なりすまし</a>';

		$this->assertSame( array(), $this->extractor()->extract( $html ) );
	}

	public function test_matches_configured_extra_host(): void {
		$extractor = new LinkExtractor( array( 'example-asp.jp' ), self::SHORT_BASE );
		$html      = '<a href="https://example-asp.jp/click/1">追加ホスト</a>';

		$links = $extractor->extract( $html );

		$this->assertCount( 1, $links );
	}
}
