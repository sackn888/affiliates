# Rakuten Link Tracker 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 楽天アフィリエイトURLを自サイトの短縮URLに自動置換し、クリックとPVを計測してCTRを可視化し、外部から読み取れるAPIを備えた WordPress プラグインを作る。

**Architecture:** 計測ロジックの中核（URL抽出・本文置換・コード生成・ボット判定・デバイス判定・訪問者ハッシュ）を WordPress 非依存の純粋クラスとして `src/Support/` に切り出し、PHPUnit で単体テストする。WordPress のフック（`save_post` / `template_redirect` / REST）はそれらを繋ぐ薄い層に閉じ込め、統合テストで担保する。データは生ログ3テーブル（links / clicks / views）に保存し、集計はクエリで都度行う。

**Tech Stack:** PHP 8.2 / WordPress 6.x / MySQL / Composer (PSR-4) / PHPUnit 9.6 / @wordpress/env (Docker) / 素の JavaScript（ビルド不要）/ インラインSVG（チャートライブラリなし）

## Global Constraints

これらは**全タスクの要件に暗黙的に含まれる**。

- プラグインディレクトリ名・テキストドメイン: `rakuten-link-tracker`
- PHP 名前空間ルート: `RLT\` → `src/`（PSR-4）
- テーブル接頭辞: `{$wpdb->prefix}rlt_`（例: `wp_rlt_links`）
- オプション接頭辞: `rlt_`
- PHP 最低バージョン: **8.0**（`composer.json` の `require.php` は `>=8.0`、実行環境は 8.2）
- WordPress 最低バージョン: **6.0**
- **IPアドレスを生のまま保存してはならない。** 必ず `VisitorHash::make()` を通す
- **`<img>` の `src` 属性を書き換えてはならない。** 楽天の計測用画像を壊すため
- **リダイレクト処理は、計測の失敗によって中断してはならない。** 記録は必ず try/catch で隔離する
- DB書き込みは必ず `$wpdb->prepare()` を使う。出力は必ずエスケープする（`esc_html` / `esc_attr` / `esc_url`）
- REST の入力は必ず `sanitize_*` を通す
- ユーザー向け文字列は `__( '...', 'rakuten-link-tracker' )` で包む
- 全コミットメッセージは Conventional Commits 形式（`feat:` / `fix:` / `test:` / `docs:` / `chore:`）
- 参照仕様書: `docs/superpowers/specs/2026-09-03-rakuten-link-tracker-design.md`

### 環境コマンド（PowerShell）

```powershell
# 依存インストール（PHP を Windows に入れずに Docker の composer イメージで実行）
docker run --rm -v "${PWD}:/app" -w /app composer:2 install

# wp-env の起動 / 停止
npx wp-env start
npx wp-env stop

# 単体テスト（WordPress 非依存）
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-unit.xml.dist"

# 統合テスト（WordPress 込み）
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist"
```

---

## File Structure

| パス | 責務 |
|---|---|
| `rakuten-link-tracker.php` | プラグインヘッダと起動のみ。ロジックを置かない |
| `uninstall.php` | 本文復元 → テーブル削除 → オプション削除 |
| `src/Plugin.php` | フック登録の一覧。どのクラスがどのフックに繋がるかが一望できる唯一の場所 |
| `src/Installer.php` | `dbDelta` スキーマ、DBバージョン管理、権限付与 |
| `src/Settings.php` | オプションの既定値と読み書き、短縮URLのベース、訪問者ソルト |
| `src/Cron.php` | 日次のログ削除とソルト更新 |
| `src/Support/CodeGenerator.php` | 短縮コード生成 **[純粋]** |
| `src/Support/BotFilter.php` | UAによるボット判定 **[純粋]** |
| `src/Support/DeviceDetector.php` | UAによるデバイス判定 **[純粋]** |
| `src/Support/VisitorHash.php` | 訪問者ハッシュ生成 **[純粋]** |
| `src/Support/LinkExtractor.php` | 本文HTML → 楽天URLの配列 **[純粋]** |
| `src/Support/ContentRewriter.php` | 本文の置換／復元 **[純粋]** |
| `src/Data/LinkRepository.php` | `rlt_links` の CRUD |
| `src/Data/EventRepository.php` | `rlt_clicks` / `rlt_views` の記録と集計クエリ |
| `src/Data/ApiKeyManager.php` | 読み取り専用APIキーの発行・検証・失効 |
| `src/Frontend/PostSync.php` | `save_post` → 抽出・発行・本文置換 |
| `src/Frontend/RedirectHandler.php` | `/go/{code}` の解決・記録・リダイレクト |
| `src/Frontend/BeaconController.php` | REST `POST /view`（PV受信）とスクリプト読み込み |
| `src/Api/StatsController.php` | REST 読み取りAPI（統計・リンク管理） |
| `src/Api/ExportController.php` | REST CSVエクスポート |
| `src/Admin/AdminMenu.php` | 管理メニュー登録とページ振り分け |
| `src/Admin/SvgChart.php` | インラインSVG描画 **[純粋に近い]** |
| `src/Admin/DashboardPage.php` | ダッシュボード画面 |
| `src/Admin/LinksListTable.php` | リンク一覧（`WP_List_Table`） |
| `src/Admin/LinkDetailPage.php` | リンク詳細画面 |
| `src/Admin/PostsReportPage.php` | 記事別レポート画面 |
| `src/Admin/SettingsPage.php` | 設定画面とAPIキー管理 |
| `src/Admin/BulkConverter.php` | 既存記事の一括変換／一括復元（AJAX） |
| `assets/beacon.js` | PVビーコン送信 |
| `assets/admin.css` | 管理画面スタイル |
| `tests/Unit/` | 純粋クラスの単体テスト |
| `tests/Integration/` | WordPress 込みの統合テスト |

---

## Task 1: プロジェクト基盤とテスト環境

**Files:**
- Create: `composer.json`
- Create: `.wp-env.json`
- Create: `phpunit-unit.xml.dist`
- Create: `phpunit-integration.xml.dist`
- Create: `tests/bootstrap-unit.php`
- Create: `tests/bootstrap-integration.php`
- Create: `rakuten-link-tracker.php`
- Create: `src/Plugin.php`
- Create: `.gitignore`
- Test: `tests/Unit/SmokeTest.php`

**Interfaces:**
- Consumes: なし
- Produces: `RLT\Plugin::instance(): Plugin`, `RLT\Plugin::VERSION` (string `'1.0.0'`), `RLT\Plugin::path(string $relative = ''): string`, `RLT\Plugin::url(string $relative = ''): string`, `RLT\Plugin::boot(): void`

- [ ] **Step 1: `.gitignore` を作る**

```gitignore
/vendor/
/node_modules/
.phpunit.result.cache
phpunit-unit.xml
phpunit-integration.xml
```

- [ ] **Step 2: `composer.json` を作る**

```json
{
    "name": "yusaku/rakuten-link-tracker",
    "description": "Rakuten affiliate link shortener with click and pageview analytics for WordPress.",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.6",
        "yoast/phpunit-polyfills": "^2.0"
    },
    "autoload": {
        "psr-4": {
            "RLT\\": "src/"
        }
    },
    "config": {
        "platform": {
            "php": "8.2.0"
        },
        "allow-plugins": {
            "dealerdirect/phpcodesniffer-composer-installer": false
        }
    }
}
```

- [ ] **Step 3: `.wp-env.json` を作る**

`mappings` を使ってプラグインディレクトリ名を `rakuten-link-tracker` に固定する（リポジトリ名が `wp-plugin` のため、`plugins` 指定だとディレクトリ名がずれる）。

```json
{
    "phpVersion": "8.2",
    "mappings": {
        "wp-content/plugins/rakuten-link-tracker": "."
    },
    "config": {
        "WP_DEBUG": true,
        "WP_DEBUG_LOG": true,
        "WP_DEBUG_DISPLAY": false
    },
    "env": {
        "tests": {
            "mappings": {
                "wp-content/plugins/rakuten-link-tracker": "."
            }
        }
    }
}
```

- [ ] **Step 4: PHPUnit 設定を2本作る**

単体テストと統合テストで bootstrap が違うため、設定ファイルを分ける。

`phpunit-unit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    bootstrap="tests/bootstrap-unit.php"
    colors="true"
    beStrictAboutTestsThatDoNotTestAnything="true"
    failOnWarning="true">
    <testsuites>
        <testsuite name="unit">
            <directory suffix="Test.php">tests/Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

`phpunit-integration.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    bootstrap="tests/bootstrap-integration.php"
    colors="true"
    beStrictAboutTestsThatDoNotTestAnything="true">
    <testsuites>
        <testsuite name="integration">
            <directory suffix="Test.php">tests/Integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 5: bootstrap を2本作る**

`tests/bootstrap-unit.php`:

```php
<?php
/**
 * Bootstrap for pure unit tests. No WordPress.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
```

`tests/bootstrap-integration.php`:

```php
<?php
/**
 * Bootstrap for WordPress integration tests (run inside wp-env's tests-cli).
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$rlt_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $rlt_tests_dir ) {
	$rlt_tests_dir = '/wordpress-phpunit';
}

require_once $rlt_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/rakuten-link-tracker.php';
	}
);

require $rlt_tests_dir . '/includes/bootstrap.php';
```

- [ ] **Step 6: 失敗するスモークテストを書く**

`tests/Unit/SmokeTest.php`:

```php
<?php

namespace RLT\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RLT\Plugin;

final class SmokeTest extends TestCase {

	public function test_version_is_defined(): void {
		$this->assertSame( '1.0.0', Plugin::VERSION );
	}
}
```

- [ ] **Step 7: 依存をインストールしてテストを走らせ、失敗を確認する**

```powershell
docker run --rm -v "${PWD}:/app" -w /app composer:2 install
npx wp-env start
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-unit.xml.dist"
```

Expected: FAIL — `Class "RLT\Plugin" not found`

- [ ] **Step 8: プラグイン本体ファイルを作る**

`rakuten-link-tracker.php`:

```php
<?php
/**
 * Plugin Name:       Rakuten Link Tracker
 * Description:       楽天アフィリエイトURLを短縮URLに置き換え、クリックとPVを計測します。
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            yusaku
 * License:           GPL-2.0-or-later
 * Text Domain:       rakuten-link-tracker
 *
 * @package RLT
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RLT_PLUGIN_FILE', __FILE__ );

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

\RLT\Plugin::instance()->boot();
```

- [ ] **Step 9: `src/Plugin.php` を作る**

この時点ではフック登録は空。以降のタスクで `boot()` に追記していく。

```php
<?php

declare(strict_types=1);

namespace RLT;

/**
 * Plugin bootstrap. Holds paths and the single place where hooks get registered.
 */
final class Plugin {

	public const VERSION = '1.0.0';

	private static ?Plugin $instance = null;

	private function __construct() {}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Absolute path to a file inside the plugin directory.
	 */
	public static function path( string $relative = '' ): string {
		return plugin_dir_path( RLT_PLUGIN_FILE ) . ltrim( $relative, '/' );
	}

	/**
	 * Public URL to a file inside the plugin directory.
	 */
	public static function url( string $relative = '' ): string {
		return plugin_dir_url( RLT_PLUGIN_FILE ) . ltrim( $relative, '/' );
	}

	/**
	 * Register every hook the plugin uses.
	 */
	public function boot(): void {
		// Hooks are registered by later tasks.
	}
}
```

- [ ] **Step 10: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-unit.xml.dist"
```

Expected: PASS — `OK (1 test, 1 assertion)`

- [ ] **Step 11: 統合テストの土台が動くことを確認する**

`tests/Integration/BootstrapTest.php`:

```php
<?php

namespace RLT\Tests\Integration;

use WP_UnitTestCase;

final class BootstrapTest extends WP_UnitTestCase {

	public function test_plugin_file_is_loaded(): void {
		$this->assertTrue( defined( 'RLT_PLUGIN_FILE' ) );
	}
}
```

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist"
```

Expected: PASS — `OK (1 test, 1 assertion)`

- [ ] **Step 12: コミット**

```bash
git add .gitignore composer.json composer.lock .wp-env.json phpunit-unit.xml.dist phpunit-integration.xml.dist tests rakuten-link-tracker.php src
git commit -m "chore: プロジェクト基盤とテスト環境をセットアップ"
```

---

## Task 2: CodeGenerator（短縮コード生成）

**Files:**
- Create: `src/Support/CodeGenerator.php`
- Test: `tests/Unit/Support/CodeGeneratorTest.php`

**Interfaces:**
- Consumes: なし
- Produces:
  - `RLT\Support\CodeGenerator::ALPHABET` (string)
  - `RLT\Support\CodeGenerator::LENGTH` (int, 6)
  - `RLT\Support\CodeGenerator::generate(): string`
  - `RLT\Support\CodeGenerator::isValid(string $code): bool`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Support/CodeGeneratorTest.php`:

```php
<?php

namespace RLT\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RLT\Support\CodeGenerator;

final class CodeGeneratorTest extends TestCase {

	public function test_generates_code_of_expected_length(): void {
		$this->assertSame( 6, strlen( CodeGenerator::generate() ) );
	}

	public function test_alphabet_excludes_ambiguous_characters(): void {
		foreach ( array( '0', 'o', '1', 'l', 'i' ) as $ambiguous ) {
			$this->assertStringNotContainsString(
				$ambiguous,
				CodeGenerator::ALPHABET,
				"Alphabet must not contain the ambiguous character '{$ambiguous}'."
			);
		}
	}

	public function test_generated_code_uses_only_alphabet_characters(): void {
		for ( $i = 0; $i < 200; $i++ ) {
			$code = CodeGenerator::generate();
			$this->assertSame(
				'',
				trim( $code, CodeGenerator::ALPHABET ),
				"Code '{$code}' contains characters outside the alphabet."
			);
		}
	}

	public function test_generates_different_codes(): void {
		$codes = array();
		for ( $i = 0; $i < 100; $i++ ) {
			$codes[] = CodeGenerator::generate();
		}

		// 31^6 の空間から100件引いて全部同じになることは実質ありえない。
		$this->assertGreaterThan( 90, count( array_unique( $codes ) ) );
	}

	public function test_is_valid_accepts_generated_codes(): void {
		$this->assertTrue( CodeGenerator::isValid( CodeGenerator::generate() ) );
	}

	/**
	 * @dataProvider invalidCodes
	 */
	public function test_is_valid_rejects_bad_input( string $code ): void {
		$this->assertFalse( CodeGenerator::isValid( $code ) );
	}

	public static function invalidCodes(): array {
		return array(
			'empty'         => array( '' ),
			'too short'     => array( 'abc' ),
			'too long'      => array( 'abcdefghijklmnopq' ),
			'has slash'     => array( 'abc/de' ),
			'has uppercase' => array( 'ABCDEF' ),
			'has dot'       => array( 'abcd.f' ),
		);
	}
}
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-unit.xml.dist --filter CodeGeneratorTest"
```

Expected: FAIL — `Class "RLT\Support\CodeGenerator" not found`

- [ ] **Step 3: 実装する**

`src/Support/CodeGenerator.php`:

```php
<?php

declare(strict_types=1);

namespace RLT\Support;

/**
 * Generates short codes for tracked links.
 *
 * Pure: no WordPress dependency.
 */
final class CodeGenerator {

	/**
	 * Lowercase alphanumerics minus the characters people misread: 0/o, 1/l/i.
	 */
	public const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

	public const LENGTH = 6;

	/**
	 * Codes accepted by the rewrite rule. Kept wider than LENGTH so codes issued
	 * by a future version with a different length still resolve.
	 */
	private const VALID_PATTERN = '/^[' . self::ALPHABET . ']{4,16}$/';

	public static function generate(): string {
		$max  = strlen( self::ALPHABET ) - 1;
		$code = '';

		for ( $i = 0; $i < self::LENGTH; $i++ ) {
			$code .= self::ALPHABET[ random_int( 0, $max ) ];
		}

		return $code;
	}

	public static function isValid( string $code ): bool {
		return 1 === preg_match( self::VALID_PATTERN, $code );
	}
}
```

- [ ] **Step 4: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-unit.xml.dist --filter CodeGeneratorTest"
```

Expected: PASS — `OK (11 tests, ...)`

- [ ] **Step 5: コミット**

```bash
git add src/Support/CodeGenerator.php tests/Unit/Support/CodeGeneratorTest.php
git commit -m "feat: 短縮コード生成（紛らわしい文字を除いた6文字）を追加"
```

---

## Task 3: BotFilter と DeviceDetector（UA判定）

**Files:**
- Create: `src/Support/BotFilter.php`
- Create: `src/Support/DeviceDetector.php`
- Test: `tests/Unit/Support/BotFilterTest.php`
- Test: `tests/Unit/Support/DeviceDetectorTest.php`

**Interfaces:**
- Consumes: なし
- Produces:
  - `RLT\Support\BotFilter::isBot(string $userAgent): bool`
  - `RLT\Support\DeviceDetector::UNKNOWN` (int 0), `::DESKTOP` (1), `::MOBILE` (2), `::TABLET` (3)
  - `RLT\Support\DeviceDetector::detect(string $userAgent): int`
  - `RLT\Support\DeviceDetector::label(int $device): string`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Support/BotFilterTest.php`:

```php
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
```

`tests/Unit/Support/DeviceDetectorTest.php`:

```php
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
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-unit.xml.dist --filter 'BotFilterTest|DeviceDetectorTest'"
```

Expected: FAIL — `Class "RLT\Support\BotFilter" not found`

- [ ] **Step 3: BotFilter を実装する**

`src/Support/BotFilter.php`:

```php
<?php

declare(strict_types=1);

namespace RLT\Support;

/**
 * Classifies a user agent as bot or human.
 *
 * Callers record the verdict rather than dropping the row, so the rule set can
 * be revised later without losing past data.
 *
 * Pure: no WordPress dependency.
 */
final class BotFilter {

	private const PATTERNS = array(
		'bot',
		'crawl',
		'spider',
		'slurp',
		'facebookexternalhit',
		'headlesschrome',
		'phantomjs',
		'preview',
		'fetcher',
		'monitor',
		'scraper',
		'curl/',
		'wget',
		'python-requests',
		'go-http-client',
		'java/',
		'okhttp',
		'axios',
		'libwww',
		'httpclient',
		'feedly',
		'pingdom',
		'uptimerobot',
		'lighthouse',
		'gtmetrix',
	);

	public static function isBot( string $userAgent ): bool {
		$userAgent = trim( $userAgent );

		// No user agent at all is never a real browser.
		if ( '' === $userAgent ) {
			return true;
		}

		$haystack = strtolower( $userAgent );

		foreach ( self::PATTERNS as $needle ) {
			if ( str_contains( $haystack, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
```

- [ ] **Step 4: DeviceDetector を実装する**

`src/Support/DeviceDetector.php`:

```php
<?php

declare(strict_types=1);

namespace RLT\Support;

/**
 * Coarse device classification from a user agent string.
 *
 * Deliberately simple: the goal is a three-way split for reporting, not
 * accurate device identification.
 *
 * Pure: no WordPress dependency.
 */
final class DeviceDetector {

	public const UNKNOWN = 0;
	public const DESKTOP = 1;
	public const MOBILE  = 2;
	public const TABLET  = 3;

	public static function detect( string $userAgent ): int {
		$ua = strtolower( trim( $userAgent ) );

		if ( '' === $ua ) {
			return self::UNKNOWN;
		}

		if ( str_contains( $ua, 'ipad' ) || str_contains( $ua, 'tablet' ) || str_contains( $ua, 'kindle' ) || str_contains( $ua, 'playbook' ) ) {
			return self::TABLET;
		}

		// Android without the "mobile" token is conventionally a tablet.
		if ( str_contains( $ua, 'android' ) ) {
			return str_contains( $ua, 'mobile' ) ? self::MOBILE : self::TABLET;
		}

		if ( str_contains( $ua, 'iphone' ) || str_contains( $ua, 'ipod' ) || str_contains( $ua, 'mobile' ) || str_contains( $ua, 'windows phone' ) ) {
			return self::MOBILE;
		}

		return self::DESKTOP;
	}

	public static function label( int $device ): string {
		return match ( $device ) {
			self::DESKTOP => 'desktop',
			self::MOBILE  => 'mobile',
			self::TABLET  => 'tablet',
			default       => 'unknown',
		};
	}
}
```

- [ ] **Step 5: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-unit.xml.dist --filter 'BotFilterTest|DeviceDetectorTest'"
```

Expected: PASS

- [ ] **Step 6: コミット**

```bash
git add src/Support/BotFilter.php src/Support/DeviceDetector.php tests/Unit/Support/BotFilterTest.php tests/Unit/Support/DeviceDetectorTest.php
git commit -m "feat: ボット判定とデバイス判定を追加"
```

---

## Task 4: VisitorHash（IPを保存しない訪問者識別）

**Files:**
- Create: `src/Support/VisitorHash.php`
- Test: `tests/Unit/Support/VisitorHashTest.php`

**Interfaces:**
- Consumes: なし
- Produces:
  - `RLT\Support\VisitorHash::make(string $ip, string $userAgent, string $salt): string` — 64文字の16進文字列
  - `RLT\Support\VisitorHash::newSalt(): string` — 64文字の16進文字列

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Support/VisitorHashTest.php`:

```php
<?php

namespace RLT\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RLT\Support\VisitorHash;

final class VisitorHashTest extends TestCase {

	public function test_returns_64_character_hex(): void {
		$hash = VisitorHash::make( '203.0.113.5', 'Mozilla/5.0', 'salt' );

		$this->assertSame( 64, strlen( $hash ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash );
	}

	public function test_is_deterministic_for_same_inputs(): void {
		$a = VisitorHash::make( '203.0.113.5', 'Mozilla/5.0', 'salt' );
		$b = VisitorHash::make( '203.0.113.5', 'Mozilla/5.0', 'salt' );

		$this->assertSame( $a, $b );
	}

	public function test_differs_when_ip_differs(): void {
		$a = VisitorHash::make( '203.0.113.5', 'Mozilla/5.0', 'salt' );
		$b = VisitorHash::make( '203.0.113.6', 'Mozilla/5.0', 'salt' );

		$this->assertNotSame( $a, $b );
	}

	public function test_differs_when_user_agent_differs(): void {
		$a = VisitorHash::make( '203.0.113.5', 'Mozilla/5.0', 'salt' );
		$b = VisitorHash::make( '203.0.113.5', 'Mozilla/4.0', 'salt' );

		$this->assertNotSame( $a, $b );
	}

	public function test_differs_when_salt_rotates(): void {
		$today     = VisitorHash::make( '203.0.113.5', 'Mozilla/5.0', 'salt-day-1' );
		$tomorrow  = VisitorHash::make( '203.0.113.5', 'Mozilla/5.0', 'salt-day-2' );

		// ソルトが変わると同一訪問者を追跡できなくなる。これが設計上の狙い。
		$this->assertNotSame( $today, $tomorrow );
	}

	public function test_hash_does_not_contain_the_raw_ip(): void {
		$hash = VisitorHash::make( '203.0.113.5', 'Mozilla/5.0', 'salt' );

		$this->assertStringNotContainsString( '203.0.113.5', $hash );
	}

	public function test_new_salt_is_random_hex(): void {
		$a = VisitorHash::newSalt();
		$b = VisitorHash::newSalt();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $a );
		$this->assertNotSame( $a, $b );
	}
}
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-unit.xml.dist --filter VisitorHashTest"
```

Expected: FAIL — `Class "RLT\Support\VisitorHash" not found`

- [ ] **Step 3: 実装する**

`src/Support/VisitorHash.php`:

```php
<?php

declare(strict_types=1);

namespace RLT\Support;

/**
 * Derives a non-reversible visitor identifier.
 *
 * The raw IP address is never stored anywhere. Because the salt rotates daily,
 * the same visitor produces a different hash tomorrow, so "unique" counts are
 * per-day only. That is an intentional privacy trade-off, not a bug.
 *
 * Pure: no WordPress dependency.
 */
final class VisitorHash {

	public static function make( string $ip, string $userAgent, string $salt ): string {
		return hash( 'sha256', $ip . '|' . $userAgent . '|' . $salt );
	}

	public static function newSalt(): string {
		return bin2hex( random_bytes( 32 ) );
	}
}
```

- [ ] **Step 4: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-unit.xml.dist --filter VisitorHashTest"
```

Expected: PASS — `OK (7 tests, ...)`

- [ ] **Step 5: コミット**

```bash
git add src/Support/VisitorHash.php tests/Unit/Support/VisitorHashTest.php
git commit -m "feat: IPを保存しない訪問者ハッシュを追加"
```

---

## Task 5: LinkExtractor（本文から楽天URLを抽出）

これがプラグインで最も壊れやすい部分。テストを厚くする。

**Files:**
- Create: `src/Support/LinkExtractor.php`
- Test: `tests/Unit/Support/LinkExtractorTest.php`

**Interfaces:**
- Consumes: なし
- Produces:
  - `RLT\Support\LinkExtractor::__construct(array $hosts, string $shortBase)` — `$hosts` は `['hb.afl.rakuten.co.jp', ...]`、`$shortBase` は `https://example.com/go/`
  - `RLT\Support\LinkExtractor::extract(string $html): array` — `array<int, array{url: string, label: string}>` を、本文中の初出順・URLでユニーク化して返す

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Support/LinkExtractorTest.php`:

```php
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
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-unit.xml.dist --filter LinkExtractorTest"
```

Expected: FAIL — `Class "RLT\Support\LinkExtractor" not found`

- [ ] **Step 3: 実装する**

`src/Support/LinkExtractor.php`:

```php
<?php

declare(strict_types=1);

namespace RLT\Support;

/**
 * Finds affiliate URLs inside post content.
 *
 * Only anchor `href` attributes are considered. `<img src>` is deliberately
 * out of scope: Rakuten banner markup embeds a 1x1 impression pixel on the same
 * host, and rewriting it would break Rakuten's own tracking.
 *
 * Pure: no WordPress dependency.
 */
final class LinkExtractor {

	private const MAX_LABEL_BYTES = 255;

	/** @var string[] */
	private array $hosts;

	private string $shortBase;

	/**
	 * @param string[] $hosts     Hostnames to treat as affiliate links.
	 * @param string   $shortBase Absolute base of already-shortened URLs, e.g. https://example.com/go/
	 */
	public function __construct( array $hosts, string $shortBase ) {
		$this->hosts     = array_values( array_filter( array_map( 'strtolower', array_map( 'trim', $hosts ) ) ) );
		$this->shortBase = $shortBase;
	}

	/**
	 * @return array<int, array{url: string, label: string}> Unique, in first-appearance order.
	 */
	public function extract( string $html ): array {
		if ( '' === trim( $html ) ) {
			return array();
		}

		// Capture the whole anchor so the inner markup is available for labelling.
		$pattern = '/<a\b([^>]*?)>(.*?)<\/a\s*>/is';

		if ( ! preg_match_all( $pattern, $html, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$found = array();

		foreach ( $matches as $match ) {
			$url = $this->hrefFrom( $match[1] );

			if ( null === $url || ! $this->isTrackable( $url ) ) {
				continue;
			}

			if ( isset( $found[ $url ] ) ) {
				continue; // Keep the first label we saw.
			}

			$found[ $url ] = array(
				'url'   => $url,
				'label' => $this->labelFrom( $match[2], $url ),
			);
		}

		return array_values( $found );
	}

	/**
	 * Pull the href value out of an anchor's attribute string.
	 */
	private function hrefFrom( string $attributes ): ?string {
		if ( ! preg_match( '/\bhref\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $attributes, $m ) ) {
			return null;
		}

		$raw = '' !== $m[2] ? $m[2] : ( $m[3] ?? '' );
		$raw = html_entity_decode( trim( $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return '' === $raw ? null : $raw;
	}

	/**
	 * True when the URL points at a configured affiliate host and has not already
	 * been shortened.
	 */
	private function isTrackable( string $url ): bool {
		if ( str_starts_with( $url, $this->shortBase ) ) {
			return false;
		}

		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );

		if ( '' === $host ) {
			return false;
		}

		// Exact host match only. A substring match would accept
		// hb.afl.rakuten.co.jp.evil.example as legitimate.
		return in_array( $host, $this->hosts, true );
	}

	/**
	 * Best available human-readable name for the link.
	 *
	 * Anchor text, then the inner image's alt, then the URL itself.
	 */
	private function labelFrom( string $inner, string $url ): string {
		$text = $this->normalise( strip_tags( $inner ) );

		if ( '' === $text && preg_match( '/<img\b[^>]*\balt\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $inner, $m ) ) {
			$alt  = '' !== $m[2] ? $m[2] : ( $m[3] ?? '' );
			$text = $this->normalise( $alt );
		}

		if ( '' === $text ) {
			$text = (string) parse_url( $url, PHP_URL_HOST ) . (string) parse_url( $url, PHP_URL_PATH );
		}

		return $this->truncate( $text );
	}

	private function normalise( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );

		return trim( $text );
	}

	/**
	 * Keep the label inside the VARCHAR(255) column without splitting a
	 * multibyte character in half.
	 */
	private function truncate( string $text ): string {
		if ( strlen( $text ) <= self::MAX_LABEL_BYTES ) {
			return $text;
		}

		$cut = substr( $text, 0, self::MAX_LABEL_BYTES );

		return (string) preg_replace( '/[\x80-\xBF]*$|[\xC0-\xFF]$/', '', $cut );
	}
}
```

- [ ] **Step 4: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-unit.xml.dist --filter LinkExtractorTest"
```

Expected: PASS — `OK (19 tests, ...)`

- [ ] **Step 5: コミット**

```bash
git add src/Support/LinkExtractor.php tests/Unit/Support/LinkExtractorTest.php
git commit -m "feat: 本文から楽天アフィリエイトURLを抽出（imgのsrcは対象外）"
```

---

## Task 6: ContentRewriter（本文の置換と復元）

**Files:**
- Create: `src/Support/ContentRewriter.php`
- Test: `tests/Unit/Support/ContentRewriterTest.php`

**Interfaces:**
- Consumes: なし
- Produces:
  - `RLT\Support\ContentRewriter::rewrite(string $html, array $map): string` — `$map` は `元URL => 短縮URL`
  - `RLT\Support\ContentRewriter::restore(string $html, array $map): string` — `$map` は `短縮URL => 元URL`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Support/ContentRewriterTest.php`:

```php
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
}
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-unit.xml.dist --filter ContentRewriterTest"
```

Expected: FAIL — `Class "RLT\Support\ContentRewriter" not found`

- [ ] **Step 3: 実装する**

`href` 属性の中身だけを対象にした置換を行う。本文全体に対する素朴な `str_replace` は `<img src>` まで巻き込むため使わない。

```php
<?php

declare(strict_types=1);

namespace RLT\Support;

/**
 * Swaps URLs inside anchor `href` attributes.
 *
 * A plain str_replace over the whole document would also hit `<img src>`, which
 * must stay untouched, so every replacement goes through an anchor-scoped
 * regex instead.
 *
 * Pure: no WordPress dependency.
 */
final class ContentRewriter {

	/**
	 * @param array<string, string> $map Original URL => short URL.
	 */
	public function rewrite( string $html, array $map ): string {
		return $this->swap( $html, $map );
	}

	/**
	 * @param array<string, string> $map Short URL => original URL.
	 */
	public function restore( string $html, array $map ): string {
		return $this->swap( $html, $map );
	}

	/**
	 * @param array<string, string> $map
	 */
	private function swap( string $html, array $map ): string {
		if ( array() === $map || '' === $html ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'/(<a\b[^>]*?\bhref\s*=\s*)("([^"]*)"|\'([^\']*)\')/i',
			static function ( array $m ) use ( $map ): string {
				$quote = str_starts_with( $m[2], '"' ) ? '"' : "'";
				$value = '"' === $quote ? $m[3] : $m[4];

				// WordPress may store & as &amp;. Compare on the decoded form.
				$decoded = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

				if ( ! isset( $map[ $decoded ] ) ) {
					return $m[0];
				}

				return $m[1] . $quote . $map[ $decoded ] . $quote;
			},
			$html
		);
	}
}
```

- [ ] **Step 4: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-unit.xml.dist --filter ContentRewriterTest"
```

Expected: PASS — `OK (14 tests, ...)`

- [ ] **Step 5: コミット**

```bash
git add src/Support/ContentRewriter.php tests/Unit/Support/ContentRewriterTest.php
git commit -m "feat: href属性のみを対象にした本文の置換・復元を追加"
```

---

## Task 7: Settings（設定の既定値と読み書き）

**Files:**
- Create: `src/Settings.php`
- Test: `tests/Integration/SettingsTest.php`

**Interfaces:**
- Consumes: `RLT\Support\VisitorHash::newSalt()`
- Produces:
  - `RLT\Settings::OPTION` (string `'rlt_settings'`)
  - `RLT\Settings::SALT_OPTION` (string `'rlt_visitor_salt'`)
  - `RLT\Settings::defaults(): array`
  - `RLT\Settings::all(): array`
  - `RLT\Settings::get(string $key): mixed`
  - `RLT\Settings::update(array $values): void`
  - `RLT\Settings::prefix(): string`
  - `RLT\Settings::shortBase(): string` — 末尾スラッシュ付きの絶対URL
  - `RLT\Settings::shortUrl(string $code): string`
  - `RLT\Settings::hosts(): array`
  - `RLT\Settings::salt(): string`
  - `RLT\Settings::rotateSalt(): void`

設定キーと既定値:

| キー | 既定値 | 意味 |
|---|---|---|
| `prefix` | `'go'` | 短縮URLのパス接頭辞 |
| `hosts` | `['hb.afl.rakuten.co.jp', 'af.rakuten.co.jp']` | 対象ホスト |
| `exclude_logged_in` | `1` | ログイン中ユーザーを計測から除外 |
| `unknown_code` | `'home'` | 未知コードの扱い。`'home'` または `'404'` |
| `retention_days` | `365` | 生ログ保持日数。`0` で無期限 |
| `click_dedup_seconds` | `5` | クリック重複排除の秒数 |
| `view_dedup_seconds` | `1800` | PV重複排除の秒数 |

- [ ] **Step 1: 失敗するテストを書く**

`tests/Integration/SettingsTest.php`:

```php
<?php

namespace RLT\Tests\Integration;

use RLT\Settings;
use WP_UnitTestCase;

final class SettingsTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		delete_option( Settings::OPTION );
		delete_option( Settings::SALT_OPTION );
	}

	public function test_defaults_are_returned_when_nothing_is_saved(): void {
		$this->assertSame( 'go', Settings::get( 'prefix' ) );
		$this->assertSame( 365, Settings::get( 'retention_days' ) );
		$this->assertSame( 1, Settings::get( 'exclude_logged_in' ) );
		$this->assertSame( 'home', Settings::get( 'unknown_code' ) );
		$this->assertContains( 'hb.afl.rakuten.co.jp', Settings::get( 'hosts' ) );
	}

	public function test_update_persists_and_merges_with_defaults(): void {
		Settings::update( array( 'prefix' => 'out' ) );

		$this->assertSame( 'out', Settings::get( 'prefix' ) );
		// 触っていないキーは既定値のまま残る。
		$this->assertSame( 365, Settings::get( 'retention_days' ) );
	}

	public function test_prefix_is_sanitised(): void {
		Settings::update( array( 'prefix' => ' /Go Link/ ' ) );

		$this->assertSame( 'go-link', Settings::prefix() );
	}

	public function test_empty_prefix_falls_back_to_default(): void {
		Settings::update( array( 'prefix' => '' ) );

		$this->assertSame( 'go', Settings::prefix() );
	}

	public function test_retention_days_is_coerced_to_non_negative_int(): void {
		Settings::update( array( 'retention_days' => '-5' ) );

		$this->assertSame( 0, Settings::get( 'retention_days' ) );
	}

	public function test_hosts_are_lowercased_and_deduplicated(): void {
		Settings::update( array( 'hosts' => array( 'HB.AFL.Rakuten.co.JP', 'hb.afl.rakuten.co.jp', ' af.rakuten.co.jp ' ) ) );

		$this->assertSame( array( 'hb.afl.rakuten.co.jp', 'af.rakuten.co.jp' ), Settings::hosts() );
	}

	public function test_unknown_code_only_accepts_known_values(): void {
		Settings::update( array( 'unknown_code' => 'explode' ) );

		$this->assertSame( 'home', Settings::get( 'unknown_code' ) );
	}

	public function test_short_base_ends_with_a_slash(): void {
		$base = Settings::shortBase();

		$this->assertStringEndsWith( '/go/', $base );
		$this->assertStringStartsWith( home_url(), $base );
	}

	public function test_short_url_appends_the_code(): void {
		$this->assertSame( Settings::shortBase() . 'abc123', Settings::shortUrl( 'abc123' ) );
	}

	public function test_salt_is_created_on_first_access_and_then_stable(): void {
		$first  = Settings::salt();
		$second = Settings::salt();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $first );
		$this->assertSame( $first, $second );
	}

	public function test_rotate_salt_changes_it(): void {
		$before = Settings::salt();
		Settings::rotateSalt();

		$this->assertNotSame( $before, Settings::salt() );
	}
}
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter SettingsTest"
```

Expected: FAIL — `Class "RLT\Settings" not found`

- [ ] **Step 3: 実装する**

`src/Settings.php`:

```php
<?php

declare(strict_types=1);

namespace RLT;

use RLT\Support\VisitorHash;

/**
 * Reads and writes plugin options, applying defaults and sanitisation in one place.
 */
final class Settings {

	public const OPTION      = 'rlt_settings';
	public const SALT_OPTION = 'rlt_visitor_salt';

	public const UNKNOWN_CODE_HOME = 'home';
	public const UNKNOWN_CODE_404  = '404';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'prefix'              => 'go',
			'hosts'               => array( 'hb.afl.rakuten.co.jp', 'af.rakuten.co.jp' ),
			'exclude_logged_in'   => 1,
			'unknown_code'        => self::UNKNOWN_CODE_HOME,
			'retention_days'      => 365,
			'click_dedup_seconds' => 5,
			'view_dedup_seconds'  => 1800,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return self::sanitise( array_merge( self::defaults(), $stored ) );
	}

	/**
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();

		return $all[ $key ] ?? null;
	}

	/**
	 * Merge the given values over what is stored. Keys not passed keep their value.
	 *
	 * @param array<string, mixed> $values
	 */
	public static function update( array $values ): void {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		update_option( self::OPTION, self::sanitise( array_merge( self::defaults(), $stored, $values ) ) );
	}

	public static function prefix(): string {
		return (string) self::get( 'prefix' );
	}

	/**
	 * Absolute base for short URLs, always with a trailing slash.
	 */
	public static function shortBase(): string {
		return home_url( '/' . self::prefix() . '/' );
	}

	public static function shortUrl( string $code ): string {
		return self::shortBase() . $code;
	}

	/**
	 * @return string[]
	 */
	public static function hosts(): array {
		return (array) self::get( 'hosts' );
	}

	/**
	 * Current visitor-hash salt, created on first use.
	 */
	public static function salt(): string {
		$salt = get_option( self::SALT_OPTION, '' );

		if ( ! is_string( $salt ) || 64 !== strlen( $salt ) ) {
			$salt = VisitorHash::newSalt();
			update_option( self::SALT_OPTION, $salt, false );
		}

		return $salt;
	}

	/**
	 * Replace the salt. Past visitor hashes become unlinkable to new ones,
	 * which is the point: it caps how long a visitor stays identifiable.
	 */
	public static function rotateSalt(): void {
		update_option( self::SALT_OPTION, VisitorHash::newSalt(), false );
	}

	/**
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private static function sanitise( array $values ): array {
		$defaults = self::defaults();

		$prefix = sanitize_title( (string) ( $values['prefix'] ?? '' ) );
		if ( '' === $prefix ) {
			$prefix = $defaults['prefix'];
		}

		$hosts = array();
		foreach ( (array) ( $values['hosts'] ?? array() ) as $host ) {
			$host = strtolower( trim( (string) $host ) );
			if ( '' !== $host && ! in_array( $host, $hosts, true ) ) {
				$hosts[] = $host;
			}
		}
		if ( array() === $hosts ) {
			$hosts = $defaults['hosts'];
		}

		$unknown = (string) ( $values['unknown_code'] ?? '' );
		if ( ! in_array( $unknown, array( self::UNKNOWN_CODE_HOME, self::UNKNOWN_CODE_404 ), true ) ) {
			$unknown = $defaults['unknown_code'];
		}

		return array(
			'prefix'              => $prefix,
			'hosts'               => $hosts,
			'exclude_logged_in'   => empty( $values['exclude_logged_in'] ) ? 0 : 1,
			'unknown_code'        => $unknown,
			'retention_days'      => max( 0, (int) ( $values['retention_days'] ?? $defaults['retention_days'] ) ),
			'click_dedup_seconds' => max( 0, (int) ( $values['click_dedup_seconds'] ?? $defaults['click_dedup_seconds'] ) ),
			'view_dedup_seconds'  => max( 0, (int) ( $values['view_dedup_seconds'] ?? $defaults['view_dedup_seconds'] ) ),
		);
	}
}
```

- [ ] **Step 4: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter SettingsTest"
```

Expected: PASS — `OK (11 tests, ...)`

- [ ] **Step 5: コミット**

```bash
git add src/Settings.php tests/Integration/SettingsTest.php
git commit -m "feat: 設定の既定値・サニタイズ・訪問者ソルト管理を追加"
```

---

## Task 8: Installer（テーブル作成とDBバージョン管理）

**Files:**
- Create: `src/Installer.php`
- Modify: `rakuten-link-tracker.php`（有効化フックの登録）
- Modify: `src/Plugin.php`（`boot()` に `maybeUpgrade` を追加）
- Modify: `tests/bootstrap-integration.php`（テーブル作成）
- Test: `tests/Integration/InstallerTest.php`

**Interfaces:**
- Consumes: なし
- Produces:
  - `RLT\Installer::DB_VERSION` (string `'1.0.0'`)
  - `RLT\Installer::VERSION_OPTION` (string `'rlt_db_version'`)
  - `RLT\Installer::CAPABILITY` (string `'rlt_view_stats'`)
  - `RLT\Installer::linksTable(): string`
  - `RLT\Installer::clicksTable(): string`
  - `RLT\Installer::viewsTable(): string`
  - `RLT\Installer::activate(): void`
  - `RLT\Installer::maybeUpgrade(): void`
  - `RLT\Installer::createTables(): void`
  - `RLT\Installer::dropTables(): void`
  - `RLT\Installer::addCapabilities(): void`
  - `RLT\Installer::removeCapabilities(): void`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Integration/InstallerTest.php`:

```php
<?php

namespace RLT\Tests\Integration;

use RLT\Installer;
use WP_UnitTestCase;

final class InstallerTest extends WP_UnitTestCase {

	public function test_tables_exist_after_activation(): void {
		global $wpdb;

		foreach ( array( Installer::linksTable(), Installer::clicksTable(), Installer::viewsTable() ) as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->assertSame( $table, $found, "Table {$table} is missing." );
		}
	}

	public function test_table_names_use_the_wordpress_prefix(): void {
		global $wpdb;

		$this->assertSame( $wpdb->prefix . 'rlt_links', Installer::linksTable() );
		$this->assertSame( $wpdb->prefix . 'rlt_clicks', Installer::clicksTable() );
		$this->assertSame( $wpdb->prefix . 'rlt_views', Installer::viewsTable() );
	}

	public function test_links_table_has_the_expected_columns(): void {
		global $wpdb;

		$columns = $wpdb->get_col( 'DESC ' . Installer::linksTable(), 0 );

		foreach ( array( 'id', 'code', 'target_url', 'url_hash', 'post_id', 'label', 'status', 'created_at', 'updated_at' ) as $column ) {
			$this->assertContains( $column, $columns, "Column {$column} is missing from the links table." );
		}
	}

	public function test_code_is_unique(): void {
		global $wpdb;

		$table = Installer::linksTable();
		$now   = current_time( 'mysql', true );

		$wpdb->insert(
			$table,
			array(
				'code'       => 'dupdup',
				'target_url' => 'https://hb.afl.rakuten.co.jp/a',
				'url_hash'   => sha1( 'https://hb.afl.rakuten.co.jp/a' ),
				'post_id'    => 1,
				'label'      => 'A',
				'status'     => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		$wpdb->suppress_errors( true );
		$second = $wpdb->insert(
			$table,
			array(
				'code'       => 'dupdup',
				'target_url' => 'https://hb.afl.rakuten.co.jp/b',
				'url_hash'   => sha1( 'https://hb.afl.rakuten.co.jp/b' ),
				'post_id'    => 2,
				'label'      => 'B',
				'status'     => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$wpdb->suppress_errors( false );

		$this->assertFalse( $second, 'Duplicate code should be rejected by the unique index.' );
	}

	public function test_same_url_in_two_posts_is_allowed(): void {
		global $wpdb;

		$table = Installer::linksTable();
		$url   = 'https://hb.afl.rakuten.co.jp/shared';
		$now   = current_time( 'mysql', true );

		$row = static function ( string $code, int $postId ) use ( $url, $now ): array {
			return array(
				'code'       => $code,
				'target_url' => $url,
				'url_hash'   => sha1( $url ),
				'post_id'    => $postId,
				'label'      => 'Shared',
				'status'     => 1,
				'created_at' => $now,
				'updated_at' => $now,
			);
		};

		$this->assertSame( 1, $wpdb->insert( $table, $row( 'aaaaaa', 11 ) ) );
		$this->assertSame( 1, $wpdb->insert( $table, $row( 'bbbbbb', 12 ) ) );
	}

	public function test_same_url_in_the_same_post_is_rejected(): void {
		global $wpdb;

		$table = Installer::linksTable();
		$url   = 'https://hb.afl.rakuten.co.jp/once';
		$now   = current_time( 'mysql', true );

		$row = static function ( string $code ) use ( $url, $now ): array {
			return array(
				'code'       => $code,
				'target_url' => $url,
				'url_hash'   => sha1( $url ),
				'post_id'    => 21,
				'label'      => 'Once',
				'status'     => 1,
				'created_at' => $now,
				'updated_at' => $now,
			);
		};

		$wpdb->insert( $table, $row( 'cccccc' ) );

		$wpdb->suppress_errors( true );
		$second = $wpdb->insert( $table, $row( 'dddddd' ) );
		$wpdb->suppress_errors( false );

		$this->assertFalse( $second, 'The (url_hash, post_id) unique index should reject this.' );
	}

	public function test_activation_stores_the_db_version(): void {
		update_option( Installer::VERSION_OPTION, '0.0.1' );

		Installer::maybeUpgrade();

		$this->assertSame( Installer::DB_VERSION, get_option( Installer::VERSION_OPTION ) );
	}

	public function test_administrator_gets_the_capability(): void {
		Installer::addCapabilities();

		$role = get_role( 'administrator' );
		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( Installer::CAPABILITY ) );
	}
}
```

- [ ] **Step 2: 統合テストの bootstrap でテーブルを作るようにする**

`tests/bootstrap-integration.php` の末尾（`require $rlt_tests_dir . '/includes/bootstrap.php';` の**後ろ**）に追記する。DDL は暗黙コミットを起こすため、各テストのトランザクション開始前に一度だけ実行する必要がある。

```php
// Create the plugin's tables once, before any test transaction starts.
\RLT\Installer::createTables();
\RLT\Installer::addCapabilities();
```

- [ ] **Step 3: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter InstallerTest"
```

Expected: FAIL — `Class "RLT\Installer" not found`

- [ ] **Step 4: 実装する**

`src/Installer.php`:

```php
<?php

declare(strict_types=1);

namespace RLT;

/**
 * Owns the database schema and its versioning.
 */
final class Installer {

	public const DB_VERSION     = '1.0.0';
	public const VERSION_OPTION = 'rlt_db_version';
	public const CAPABILITY     = 'rlt_view_stats';

	/**
	 * Roles that can read the plugin's statistics.
	 */
	private const ROLES = array( 'administrator', 'editor' );

	public static function linksTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'rlt_links';
	}

	public static function clicksTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'rlt_clicks';
	}

	public static function viewsTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'rlt_views';
	}

	/**
	 * Runs on plugin activation.
	 */
	public static function activate(): void {
		self::createTables();
		self::addCapabilities();
		update_option( self::VERSION_OPTION, self::DB_VERSION );

		// The /go/{code} rule is registered on init; flush so it takes effect now.
		flush_rewrite_rules();
	}

	/**
	 * Runs on every load. Cheap when the version already matches.
	 */
	public static function maybeUpgrade(): void {
		if ( get_option( self::VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::createTables();
		self::addCapabilities();
		update_option( self::VERSION_OPTION, self::DB_VERSION );
	}

	public static function createTables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$links   = self::linksTable();
		$clicks  = self::clicksTable();
		$views   = self::viewsTable();

		dbDelta(
			"CREATE TABLE {$links} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				code VARCHAR(16) NOT NULL,
				target_url TEXT NOT NULL,
				url_hash CHAR(40) NOT NULL,
				post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				label VARCHAR(255) NOT NULL DEFAULT '',
				status TINYINT NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY code (code),
				UNIQUE KEY url_post (url_hash, post_id),
				KEY post_id (post_id),
				KEY status (status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$clicks} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				link_id BIGINT UNSIGNED NOT NULL,
				post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				clicked_at DATETIME NOT NULL,
				visitor_hash CHAR(64) NOT NULL DEFAULT '',
				referer VARCHAR(255) NOT NULL DEFAULT '',
				device TINYINT NOT NULL DEFAULT 0,
				is_bot TINYINT NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY link_time (link_id, clicked_at),
				KEY post_time (post_id, clicked_at),
				KEY dedup (visitor_hash, link_id, clicked_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$views} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				post_id BIGINT UNSIGNED NOT NULL,
				viewed_at DATETIME NOT NULL,
				visitor_hash CHAR(64) NOT NULL DEFAULT '',
				referer VARCHAR(255) NOT NULL DEFAULT '',
				device TINYINT NOT NULL DEFAULT 0,
				is_bot TINYINT NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY post_time (post_id, viewed_at),
				KEY dedup (visitor_hash, post_id, viewed_at)
			) {$charset};"
		);
	}

	public static function dropTables(): void {
		global $wpdb;

		foreach ( array( self::clicksTable(), self::viewsTable(), self::linksTable() ) as $table ) {
			// Table names come from $wpdb->prefix, never from user input.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}

	public static function addCapabilities(): void {
		foreach ( self::ROLES as $roleName ) {
			$role = get_role( $roleName );

			if ( $role instanceof \WP_Role ) {
				$role->add_cap( self::CAPABILITY );
			}
		}
	}

	public static function removeCapabilities(): void {
		foreach ( self::ROLES as $roleName ) {
			$role = get_role( $roleName );

			if ( $role instanceof \WP_Role ) {
				$role->remove_cap( self::CAPABILITY );
			}
		}
	}
}
```

- [ ] **Step 5: 有効化フックを登録する**

`rakuten-link-tracker.php` の `\RLT\Plugin::instance()->boot();` の**直前**に挿入する。

```php
register_activation_hook( __FILE__, array( \RLT\Installer::class, 'activate' ) );
register_deactivation_hook(
	__FILE__,
	static function (): void {
		flush_rewrite_rules();
	}
);
```

- [ ] **Step 6: `Plugin::boot()` にマイグレーションを繋ぐ**

`src/Plugin.php` の `boot()` の中身を差し替える。

```php
	public function boot(): void {
		add_action( 'plugins_loaded', array( Installer::class, 'maybeUpgrade' ) );
	}
```

`src/Plugin.php` のクラス宣言の上に `use RLT\Installer;` は不要（同一名前空間）。ただしファイル冒頭の `namespace RLT;` の下に何も追加しないこと。

- [ ] **Step 7: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter InstallerTest"
```

Expected: PASS — `OK (8 tests, ...)`

- [ ] **Step 8: コミット**

```bash
git add src/Installer.php src/Plugin.php rakuten-link-tracker.php tests/bootstrap-integration.php tests/Integration/InstallerTest.php
git commit -m "feat: DBスキーマ・バージョン管理・権限付与を追加"
```

---

## Task 9: LinkRepository（リンクの CRUD）

**Files:**
- Create: `src/Data/LinkRepository.php`
- Test: `tests/Integration/Data/LinkRepositoryTest.php`

**Interfaces:**
- Consumes: `RLT\Installer::linksTable()`, `RLT\Support\CodeGenerator::generate()`, `RLT\Settings::shortUrl()`
- Produces:
  - `RLT\Data\LinkRepository::__construct()`
  - `findByCode(string $code): ?array`
  - `findById(int $id): ?array`
  - `findByUrlAndPost(string $url, int $postId): ?array`
  - `findOrCreate(string $url, int $postId, string $label): ?array`
  - `findByPost(int $postId, bool $activeOnly = true): array`
  - `countActiveForPost(int $postId): int`
  - `archiveOthers(int $postId, array $keepIds): int`
  - `update(int $id, array $fields): bool`
  - `restoreMap(): array` — `短縮URL => 元URL`
  - `postIdsWithLinks(): array`

行の形は `array{id:int, code:string, target_url:string, url_hash:string, post_id:int, label:string, status:int, created_at:string, updated_at:string}`。以降のタスクはこの形に依存する。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Integration/Data/LinkRepositoryTest.php`:

```php
<?php

namespace RLT\Tests\Integration\Data;

use RLT\Data\LinkRepository;
use RLT\Installer;
use RLT\Settings;
use WP_UnitTestCase;

final class LinkRepositoryTest extends WP_UnitTestCase {

	private LinkRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->repo = new LinkRepository();
	}

	public function test_find_or_create_inserts_a_new_row(): void {
		$link = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'ホテルA' );

		$this->assertIsArray( $link );
		$this->assertSame( 10, $link['post_id'] );
		$this->assertSame( 'ホテルA', $link['label'] );
		$this->assertSame( 1, $link['status'] );
		$this->assertSame( 6, strlen( $link['code'] ) );
	}

	public function test_find_or_create_is_idempotent(): void {
		$first  = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'ホテルA' );
		$second = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'ホテルA' );

		$this->assertSame( $first['id'], $second['id'] );
		$this->assertSame( $first['code'], $second['code'] );
	}

	public function test_same_url_in_a_different_post_gets_its_own_code(): void {
		$a = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'ホテルA' );
		$b = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 11, 'ホテルA' );

		$this->assertNotSame( $a['code'], $b['code'] );
	}

	public function test_reactivates_and_relabels_an_archived_row(): void {
		$link = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, '古いラベル' );
		$this->repo->update( $link['id'], array( 'status' => 0 ) );

		$again = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, '新しいラベル' );

		$this->assertSame( $link['id'], $again['id'] );
		$this->assertSame( 1, $again['status'] );
		$this->assertSame( '新しいラベル', $again['label'] );
	}

	public function test_find_by_code_returns_typed_row(): void {
		$created = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'ホテルA' );

		$found = $this->repo->findByCode( $created['code'] );

		$this->assertIsInt( $found['id'] );
		$this->assertIsInt( $found['post_id'] );
		$this->assertIsInt( $found['status'] );
		$this->assertSame( 'https://hb.afl.rakuten.co.jp/hgc/a', $found['target_url'] );
	}

	public function test_find_by_code_returns_null_when_missing(): void {
		$this->assertNull( $this->repo->findByCode( 'zzzzzz' ) );
	}

	public function test_find_by_post_filters_archived_by_default(): void {
		$a = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'A' );
		$b = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/b', 10, 'B' );
		$this->repo->update( $b['id'], array( 'status' => 0 ) );

		$active = $this->repo->findByPost( 10 );
		$all    = $this->repo->findByPost( 10, false );

		$this->assertCount( 1, $active );
		$this->assertSame( $a['id'], $active[0]['id'] );
		$this->assertCount( 2, $all );
	}

	public function test_count_active_for_post(): void {
		$this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'A' );
		$this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/b', 10, 'B' );

		$this->assertSame( 2, $this->repo->countActiveForPost( 10 ) );
		$this->assertSame( 0, $this->repo->countActiveForPost( 99 ) );
	}

	public function test_archive_others_archives_only_the_ones_not_kept(): void {
		$keep = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'A' );
		$drop = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/b', 10, 'B' );
		$other = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/c', 11, 'C' );

		$archived = $this->repo->archiveOthers( 10, array( $keep['id'] ) );

		$this->assertSame( 1, $archived );
		$this->assertSame( 1, $this->repo->findById( $keep['id'] )['status'] );
		$this->assertSame( 0, $this->repo->findById( $drop['id'] )['status'] );
		// 別の記事のリンクには手を出さない。
		$this->assertSame( 1, $this->repo->findById( $other['id'] )['status'] );
	}

	public function test_archive_others_with_empty_keep_list_archives_all_for_the_post(): void {
		$a = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'A' );

		$this->assertSame( 1, $this->repo->archiveOthers( 10, array() ) );
		$this->assertSame( 0, $this->repo->findById( $a['id'] )['status'] );
	}

	public function test_update_changes_target_url_and_label(): void {
		$link = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'A' );

		$this->assertTrue(
			$this->repo->update(
				$link['id'],
				array(
					'target_url' => 'https://hb.afl.rakuten.co.jp/hgc/new',
					'label'      => '新ラベル',
				)
			)
		);

		$updated = $this->repo->findById( $link['id'] );
		$this->assertSame( 'https://hb.afl.rakuten.co.jp/hgc/new', $updated['target_url'] );
		$this->assertSame( '新ラベル', $updated['label'] );
		// url_hash も追随していないと findOrCreate が重複行を作ってしまう。
		$this->assertSame( sha1( 'https://hb.afl.rakuten.co.jp/hgc/new' ), $updated['url_hash'] );
	}

	public function test_update_ignores_unknown_fields(): void {
		$link = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'A' );

		$this->repo->update( $link['id'], array( 'id' => 999, 'nonsense' => 'x' ) );

		$this->assertNotNull( $this->repo->findById( $link['id'] ) );
	}

	public function test_restore_map_maps_short_urls_back_to_originals(): void {
		$link = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'A' );

		$map = $this->repo->restoreMap();

		$this->assertArrayHasKey( Settings::shortUrl( $link['code'] ), $map );
		$this->assertSame( 'https://hb.afl.rakuten.co.jp/hgc/a', $map[ Settings::shortUrl( $link['code'] ) ] );
	}

	public function test_restore_map_includes_archived_links(): void {
		$link = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'A' );
		$this->repo->update( $link['id'], array( 'status' => 0 ) );

		// アーカイブ済みでも本文には短縮URLが残っているため、復元対象に含める必要がある。
		$this->assertArrayHasKey( Settings::shortUrl( $link['code'] ), $this->repo->restoreMap() );
	}

	public function test_post_ids_with_links(): void {
		$this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'A' );
		$this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/b', 10, 'B' );
		$this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/c', 11, 'C' );

		$ids = $this->repo->postIdsWithLinks();

		sort( $ids );
		$this->assertSame( array( 10, 11 ), $ids );
	}
}
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter LinkRepositoryTest"
```

Expected: FAIL — `Class "RLT\Data\LinkRepository" not found`

- [ ] **Step 3: 実装する**

`src/Data/LinkRepository.php`:

```php
<?php

declare(strict_types=1);

namespace RLT\Data;

use RLT\Installer;
use RLT\Settings;
use RLT\Support\CodeGenerator;

/**
 * Reads and writes tracked links.
 */
final class LinkRepository {

	/**
	 * How many times to retry when a generated code collides with an existing one.
	 */
	private const CODE_ATTEMPTS = 10;

	private const WRITABLE_FIELDS = array( 'target_url', 'label', 'status', 'post_id' );

	private \wpdb $db;

	public function __construct() {
		global $wpdb;

		$this->db = $wpdb;
	}

	public function findByCode( string $code ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM ' . Installer::linksTable() . ' WHERE code = %s', $code ),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	public function findById( int $id ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM ' . Installer::linksTable() . ' WHERE id = %d', $id ),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	public function findByUrlAndPost( string $url, int $postId ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . Installer::linksTable() . ' WHERE url_hash = %s AND post_id = %d',
				sha1( $url ),
				$postId
			),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Return the link for this URL/post pair, creating it if needed.
	 *
	 * An existing row is reactivated and relabelled rather than duplicated, so a
	 * link that was removed from the post and later added back keeps its code and
	 * its click history.
	 */
	public function findOrCreate( string $url, int $postId, string $label ): ?array {
		$existing = $this->findByUrlAndPost( $url, $postId );

		if ( null !== $existing ) {
			if ( 1 !== $existing['status'] || $existing['label'] !== $label ) {
				$this->update(
					$existing['id'],
					array(
						'status' => 1,
						'label'  => $label,
					)
				);

				return $this->findById( $existing['id'] );
			}

			return $existing;
		}

		$now = current_time( 'mysql', true );

		for ( $attempt = 0; $attempt < self::CODE_ATTEMPTS; $attempt++ ) {
			$this->db->suppress_errors( true );
			$inserted = $this->db->insert(
				Installer::linksTable(),
				array(
					'code'       => CodeGenerator::generate(),
					'target_url' => $url,
					'url_hash'   => sha1( $url ),
					'post_id'    => $postId,
					'label'      => $label,
					'status'     => 1,
					'created_at' => $now,
					'updated_at' => $now,
				),
				array( '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
			);
			$this->db->suppress_errors( false );

			if ( false !== $inserted ) {
				return $this->findById( (int) $this->db->insert_id );
			}

			// Another request may have inserted the same URL/post pair meanwhile.
			$raced = $this->findByUrlAndPost( $url, $postId );
			if ( null !== $raced ) {
				return $raced;
			}
		}

		return null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function findByPost( int $postId, bool $activeOnly = true ): array {
		$sql = 'SELECT * FROM ' . Installer::linksTable() . ' WHERE post_id = %d';

		if ( $activeOnly ) {
			$sql .= ' AND status = 1';
		}

		$sql .= ' ORDER BY id ASC';

		$rows = $this->db->get_results( $this->db->prepare( $sql, $postId ), ARRAY_A ) ?: array();

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	public function countActiveForPost( int $postId ): int {
		return (int) $this->db->get_var(
			$this->db->prepare(
				'SELECT COUNT(*) FROM ' . Installer::linksTable() . ' WHERE post_id = %d AND status = 1',
				$postId
			)
		);
	}

	/**
	 * Archive every active link of a post except the given ids.
	 *
	 * Rows are archived rather than deleted: their click history must survive, and
	 * the short URL may already be shared somewhere outside the post.
	 *
	 * @param int[] $keepIds
	 * @return int Number of rows archived.
	 */
	public function archiveOthers( int $postId, array $keepIds ): int {
		$table = Installer::linksTable();
		$now   = current_time( 'mysql', true );

		$keepIds = array_values( array_unique( array_map( 'intval', $keepIds ) ) );

		if ( array() === $keepIds ) {
			$sql = $this->db->prepare(
				"UPDATE {$table} SET status = 0, updated_at = %s WHERE post_id = %d AND status = 1",
				$now,
				$postId
			);
		} else {
			$placeholders = implode( ',', array_fill( 0, count( $keepIds ), '%d' ) );
			$sql          = $this->db->prepare(
				"UPDATE {$table} SET status = 0, updated_at = %s WHERE post_id = %d AND status = 1 AND id NOT IN ({$placeholders})",
				array_merge( array( $now, $postId ), $keepIds )
			);
		}

		return (int) $this->db->query( $sql );
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	public function update( int $id, array $fields ): bool {
		$data = array();

		foreach ( self::WRITABLE_FIELDS as $field ) {
			if ( array_key_exists( $field, $fields ) ) {
				$data[ $field ] = $fields[ $field ];
			}
		}

		if ( array() === $data ) {
			return false;
		}

		// url_hash must follow target_url or findOrCreate would start creating duplicates.
		if ( isset( $data['target_url'] ) ) {
			$data['url_hash'] = sha1( (string) $data['target_url'] );
		}

		$data['updated_at'] = current_time( 'mysql', true );

		return false !== $this->db->update( Installer::linksTable(), $data, array( 'id' => $id ) );
	}

	/**
	 * Short URL => original URL, for every link including archived ones.
	 *
	 * Archived links must be included: their short URLs may still sit in post content.
	 *
	 * @return array<string, string>
	 */
	public function restoreMap(): array {
		$rows = $this->db->get_results( 'SELECT code, target_url FROM ' . Installer::linksTable(), ARRAY_A ) ?: array();

		$map = array();
		foreach ( $rows as $row ) {
			$map[ Settings::shortUrl( (string) $row['code'] ) ] = (string) $row['target_url'];
		}

		return $map;
	}

	/**
	 * @return int[]
	 */
	public function postIdsWithLinks(): array {
		$ids = $this->db->get_col( 'SELECT DISTINCT post_id FROM ' . Installer::linksTable() . ' WHERE post_id > 0' ) ?: array();

		return array_map( 'intval', $ids );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		$row['id']      = (int) $row['id'];
		$row['post_id'] = (int) $row['post_id'];
		$row['status']  = (int) $row['status'];

		return $row;
	}
}
```

- [ ] **Step 4: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter LinkRepositoryTest"
```

Expected: PASS — `OK (15 tests, ...)`

- [ ] **Step 5: コミット**

```bash
git add src/Data/LinkRepository.php tests/Integration/Data/LinkRepositoryTest.php
git commit -m "feat: リンクのCRUDとコード発行リトライを追加"
```

---

## Task 10: RequestContext と EventRepository の記録

**Files:**
- Create: `src/Support/RequestContext.php`
- Create: `src/Data/EventRepository.php`
- Test: `tests/Integration/Support/RequestContextTest.php`
- Test: `tests/Integration/Data/EventRepositoryRecordTest.php`

**Interfaces:**
- Consumes: `RLT\Support\VisitorHash`, `RLT\Support\BotFilter`, `RLT\Support\DeviceDetector`, `RLT\Settings::salt()`, `RLT\Installer`
- Produces:
  - `RLT\Support\RequestContext::ip(): string`
  - `RLT\Support\RequestContext::userAgent(): string`
  - `RLT\Support\RequestContext::referer(): string` — 255文字に切り詰め済み
  - `RLT\Support\RequestContext::visitorHash(): string`
  - `RLT\Support\RequestContext::device(): int`
  - `RLT\Support\RequestContext::isBot(): bool`
  - `RLT\Data\EventRepository::__construct()`
  - `recordClick(int $linkId, int $postId): bool`
  - `recordView(int $postId): bool`
  - `hasRecentClick(int $linkId, string $visitorHash, int $seconds): bool`
  - `hasRecentView(int $postId, string $visitorHash, int $seconds): bool`

`recordClick` / `recordView` は `RequestContext` から訪問者情報を自分で取得する。呼び出し側が個人情報を扱わずに済むようにするため。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Integration/Support/RequestContextTest.php`:

```php
<?php

namespace RLT\Tests\Integration\Support;

use RLT\Support\DeviceDetector;
use RLT\Support\RequestContext;
use WP_UnitTestCase;

final class RequestContextTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		$_SERVER['REMOTE_ADDR']     = '203.0.113.5';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36';
		$_SERVER['HTTP_REFERER']    = 'https://example.com/hotel-article/';
	}

	public function test_reads_ip_from_remote_addr(): void {
		$this->assertSame( '203.0.113.5', RequestContext::ip() );
	}

	public function test_ip_can_be_overridden_by_filter_for_proxied_sites(): void {
		add_filter( 'rlt_client_ip', static fn (): string => '198.51.100.9' );

		$this->assertSame( '198.51.100.9', RequestContext::ip() );
	}

	public function test_missing_ip_is_empty_string(): void {
		unset( $_SERVER['REMOTE_ADDR'] );

		$this->assertSame( '', RequestContext::ip() );
	}

	public function test_referer_is_truncated_to_255_characters(): void {
		$_SERVER['HTTP_REFERER'] = 'https://example.com/' . str_repeat( 'a', 400 );

		$this->assertLessThanOrEqual( 255, strlen( RequestContext::referer() ) );
	}

	public function test_visitor_hash_is_hex_and_stable_within_a_request(): void {
		$hash = RequestContext::visitorHash();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash );
		$this->assertSame( $hash, RequestContext::visitorHash() );
	}

	public function test_visitor_hash_does_not_leak_the_ip(): void {
		$this->assertStringNotContainsString( '203.0.113.5', RequestContext::visitorHash() );
	}

	public function test_device_and_bot_come_from_the_user_agent(): void {
		$this->assertSame( DeviceDetector::DESKTOP, RequestContext::device() );
		$this->assertFalse( RequestContext::isBot() );

		$_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1';
		RequestContext::reset();

		$this->assertTrue( RequestContext::isBot() );
	}
}
```

`tests/Integration/Data/EventRepositoryRecordTest.php`:

```php
<?php

namespace RLT\Tests\Integration\Data;

use RLT\Data\EventRepository;
use RLT\Installer;
use RLT\Support\RequestContext;
use WP_UnitTestCase;

final class EventRepositoryRecordTest extends WP_UnitTestCase {

	private EventRepository $events;

	protected function setUp(): void {
		parent::setUp();

		$_SERVER['REMOTE_ADDR']     = '203.0.113.5';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36';
		$_SERVER['HTTP_REFERER']    = 'https://example.com/hotel-article/';
		RequestContext::reset();

		$this->events = new EventRepository();
	}

	public function test_record_click_inserts_a_row(): void {
		global $wpdb;

		$this->assertTrue( $this->events->recordClick( 7, 42 ) );

		$row = $wpdb->get_row( 'SELECT * FROM ' . Installer::clicksTable() . ' ORDER BY id DESC LIMIT 1', ARRAY_A );

		$this->assertSame( '7', $row['link_id'] );
		$this->assertSame( '42', $row['post_id'] );
		$this->assertSame( 'https://example.com/hotel-article/', $row['referer'] );
		$this->assertSame( '0', $row['is_bot'] );
	}

	public function test_click_row_never_stores_the_raw_ip(): void {
		global $wpdb;

		$this->events->recordClick( 7, 42 );
		$row = $wpdb->get_row( 'SELECT * FROM ' . Installer::clicksTable() . ' ORDER BY id DESC LIMIT 1', ARRAY_A );

		foreach ( $row as $value ) {
			$this->assertStringNotContainsString( '203.0.113.5', (string) $value );
		}
	}

	public function test_bot_clicks_are_recorded_and_flagged(): void {
		global $wpdb;

		$_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1';
		RequestContext::reset();

		$this->assertTrue( $this->events->recordClick( 7, 42 ) );

		$row = $wpdb->get_row( 'SELECT * FROM ' . Installer::clicksTable() . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
		$this->assertSame( '1', $row['is_bot'] );
	}

	public function test_record_view_inserts_a_row(): void {
		global $wpdb;

		$this->assertTrue( $this->events->recordView( 42 ) );

		$row = $wpdb->get_row( 'SELECT * FROM ' . Installer::viewsTable() . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
		$this->assertSame( '42', $row['post_id'] );
	}

	public function test_has_recent_click_is_true_right_after_recording(): void {
		$this->events->recordClick( 7, 42 );

		$this->assertTrue( $this->events->hasRecentClick( 7, RequestContext::visitorHash(), 5 ) );
	}

	public function test_has_recent_click_is_false_for_a_different_link(): void {
		$this->events->recordClick( 7, 42 );

		$this->assertFalse( $this->events->hasRecentClick( 8, RequestContext::visitorHash(), 5 ) );
	}

	public function test_has_recent_click_is_false_for_a_different_visitor(): void {
		$this->events->recordClick( 7, 42 );

		$this->assertFalse( $this->events->hasRecentClick( 7, str_repeat( 'f', 64 ), 5 ) );
	}

	public function test_has_recent_click_is_false_outside_the_window(): void {
		global $wpdb;

		$this->events->recordClick( 7, 42 );
		$wpdb->query( 'UPDATE ' . Installer::clicksTable() . ' SET clicked_at = DATE_SUB(clicked_at, INTERVAL 60 SECOND)' );

		$this->assertFalse( $this->events->hasRecentClick( 7, RequestContext::visitorHash(), 5 ) );
	}

	public function test_has_recent_click_is_false_when_window_is_zero(): void {
		$this->events->recordClick( 7, 42 );

		$this->assertFalse( $this->events->hasRecentClick( 7, RequestContext::visitorHash(), 0 ) );
	}

	public function test_has_recent_view_behaves_the_same(): void {
		$this->events->recordView( 42 );

		$this->assertTrue( $this->events->hasRecentView( 42, RequestContext::visitorHash(), 1800 ) );
		$this->assertFalse( $this->events->hasRecentView( 43, RequestContext::visitorHash(), 1800 ) );
	}
}
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter 'RequestContextTest|EventRepositoryRecordTest'"
```

Expected: FAIL — `Class "RLT\Support\RequestContext" not found`

- [ ] **Step 3: RequestContext を実装する**

`src/Support/RequestContext.php`:

```php
<?php

declare(strict_types=1);

namespace RLT\Support;

use RLT\Settings;

/**
 * The single place that touches $_SERVER.
 *
 * Callers get a visitor hash, a device class and a bot verdict, and never see
 * the IP address at all.
 */
final class RequestContext {

	private const MAX_REFERER_LENGTH = 255;

	private static ?string $visitorHash = null;
	private static ?int $device         = null;
	private static ?bool $isBot         = null;

	/**
	 * Clear the per-request memo. Only useful in tests.
	 */
	public static function reset(): void {
		self::$visitorHash = null;
		self::$device      = null;
		self::$isBot       = null;
	}

	/**
	 * Client IP, straight from REMOTE_ADDR.
	 *
	 * Sites behind Cloudflare or another proxy should override this via the
	 * `rlt_client_ip` filter. Trusting a forwarded header by default would let
	 * anyone spoof their identity and defeat deduplication.
	 */
	public static function ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

		return (string) apply_filters( 'rlt_client_ip', $ip );
	}

	public static function userAgent(): string {
		return isset( $_SERVER['HTTP_USER_AGENT'] )
			? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] )
			: '';
	}

	public static function referer(): string {
		$referer = isset( $_SERVER['HTTP_REFERER'] )
			? esc_url_raw( (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) )
			: '';

		return substr( $referer, 0, self::MAX_REFERER_LENGTH );
	}

	public static function visitorHash(): string {
		if ( null === self::$visitorHash ) {
			self::$visitorHash = VisitorHash::make( self::ip(), self::userAgent(), Settings::salt() );
		}

		return self::$visitorHash;
	}

	public static function device(): int {
		if ( null === self::$device ) {
			self::$device = DeviceDetector::detect( self::userAgent() );
		}

		return self::$device;
	}

	public static function isBot(): bool {
		if ( null === self::$isBot ) {
			self::$isBot = BotFilter::isBot( self::userAgent() );
		}

		return self::$isBot;
	}
}
```

- [ ] **Step 4: EventRepository の記録部分を実装する**

`src/Data/EventRepository.php`:

```php
<?php

declare(strict_types=1);

namespace RLT\Data;

use RLT\Installer;
use RLT\Support\RequestContext;

/**
 * Records click and view events, and answers questions about them.
 *
 * Bot traffic is stored with is_bot = 1 rather than dropped, so the bot rules
 * can be revised later without losing history.
 */
final class EventRepository {

	private \wpdb $db;

	public function __construct() {
		global $wpdb;

		$this->db = $wpdb;
	}

	public function recordClick( int $linkId, int $postId ): bool {
		return false !== $this->db->insert(
			Installer::clicksTable(),
			array(
				'link_id'      => $linkId,
				'post_id'      => $postId,
				'clicked_at'   => current_time( 'mysql', true ),
				'visitor_hash' => RequestContext::visitorHash(),
				'referer'      => RequestContext::referer(),
				'device'       => RequestContext::device(),
				'is_bot'       => RequestContext::isBot() ? 1 : 0,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%d' )
		);
	}

	public function recordView( int $postId ): bool {
		return false !== $this->db->insert(
			Installer::viewsTable(),
			array(
				'post_id'      => $postId,
				'viewed_at'    => current_time( 'mysql', true ),
				'visitor_hash' => RequestContext::visitorHash(),
				'referer'      => RequestContext::referer(),
				'device'       => RequestContext::device(),
				'is_bot'       => RequestContext::isBot() ? 1 : 0,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d' )
		);
	}

	public function hasRecentClick( int $linkId, string $visitorHash, int $seconds ): bool {
		if ( $seconds <= 0 || '' === $visitorHash ) {
			return false;
		}

		return (bool) $this->db->get_var(
			$this->db->prepare(
				'SELECT 1 FROM ' . Installer::clicksTable() . '
				 WHERE link_id = %d AND visitor_hash = %s AND clicked_at > DATE_SUB(%s, INTERVAL %d SECOND)
				 LIMIT 1',
				$linkId,
				$visitorHash,
				current_time( 'mysql', true ),
				$seconds
			)
		);
	}

	public function hasRecentView( int $postId, string $visitorHash, int $seconds ): bool {
		if ( $seconds <= 0 || '' === $visitorHash ) {
			return false;
		}

		return (bool) $this->db->get_var(
			$this->db->prepare(
				'SELECT 1 FROM ' . Installer::viewsTable() . '
				 WHERE post_id = %d AND visitor_hash = %s AND viewed_at > DATE_SUB(%s, INTERVAL %d SECOND)
				 LIMIT 1',
				$postId,
				$visitorHash,
				current_time( 'mysql', true ),
				$seconds
			)
		);
	}
}
```

- [ ] **Step 5: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter 'RequestContextTest|EventRepositoryRecordTest'"
```

Expected: PASS — `OK (17 tests, ...)`

- [ ] **Step 6: コミット**

```bash
git add src/Support/RequestContext.php src/Data/EventRepository.php tests/Integration/Support/RequestContextTest.php tests/Integration/Data/EventRepositoryRecordTest.php
git commit -m "feat: リクエスト情報の集約とクリック・PVの記録を追加"
```

---

## Task 11: PostSync（保存時の自動置換）

**Files:**
- Create: `src/Frontend/PostSync.php`
- Modify: `src/Plugin.php`
- Test: `tests/Integration/Frontend/PostSyncTest.php`

**Interfaces:**
- Consumes: `LinkRepository`, `LinkExtractor`, `ContentRewriter`, `Settings`
- Produces:
  - `RLT\Frontend\PostSync::META_ORIGINAL` (string `'_rlt_original_content'`)
  - `RLT\Frontend\PostSync::__construct(?LinkRepository $links = null)`
  - `register(): void`
  - `syncPost(int $postId): bool` — 本文を書き換えたら true
  - `restorePost(int $postId): bool` — 本文を元に戻したら true
  - `syncedPostTypes(): array`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Integration/Frontend/PostSyncTest.php`:

```php
<?php

namespace RLT\Tests\Integration\Frontend;

use RLT\Data\LinkRepository;
use RLT\Frontend\PostSync;
use RLT\Settings;
use WP_UnitTestCase;

final class PostSyncTest extends WP_UnitTestCase {

	private const AFFILIATE = 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=x';

	private PostSync $sync;
	private LinkRepository $links;

	protected function setUp(): void {
		parent::setUp();
		$this->links = new LinkRepository();
		$this->sync  = new PostSync( $this->links );
		$this->sync->register();
	}

	private function createPostWithLink( string $content ): int {
		return self::factory()->post->create(
			array(
				'post_content' => $content,
				'post_status'  => 'publish',
			)
		);
	}

	public function test_saving_a_post_replaces_the_affiliate_url(): void {
		$postId = $this->createPostWithLink( '<a href="' . self::AFFILIATE . '">ホテル</a>' );

		$content = get_post_field( 'post_content', $postId );

		$this->assertStringNotContainsString( self::AFFILIATE, $content );
		$this->assertStringContainsString( Settings::shortBase(), $content );
	}

	public function test_a_link_row_is_created_for_the_post(): void {
		$postId = $this->createPostWithLink( '<a href="' . self::AFFILIATE . '">ホテル</a>' );

		$links = $this->links->findByPost( $postId );

		$this->assertCount( 1, $links );
		$this->assertSame( self::AFFILIATE, $links[0]['target_url'] );
		$this->assertSame( 'ホテル', $links[0]['label'] );
	}

	public function test_the_short_url_in_the_content_matches_the_stored_code(): void {
		$postId = $this->createPostWithLink( '<a href="' . self::AFFILIATE . '">ホテル</a>' );

		$link    = $this->links->findByPost( $postId )[0];
		$content = get_post_field( 'post_content', $postId );

		$this->assertStringContainsString( 'href="' . Settings::shortUrl( $link['code'] ) . '"', $content );
	}

	public function test_original_content_is_backed_up(): void {
		$original = '<a href="' . self::AFFILIATE . '">ホテル</a>';
		$postId   = $this->createPostWithLink( $original );

		$this->assertSame( $original, get_post_meta( $postId, PostSync::META_ORIGINAL, true ) );
	}

	public function test_resaving_keeps_the_same_code(): void {
		$postId = $this->createPostWithLink( '<a href="' . self::AFFILIATE . '">ホテル</a>' );
		$first  = $this->links->findByPost( $postId )[0]['code'];

		wp_update_post(
			array(
				'ID'           => $postId,
				'post_content' => get_post_field( 'post_content', $postId ) . '<p>追記</p>',
			)
		);

		$links = $this->links->findByPost( $postId );

		$this->assertCount( 1, $links );
		$this->assertSame( $first, $links[0]['code'] );
	}

	public function test_saving_is_idempotent_on_already_converted_content(): void {
		$postId  = $this->createPostWithLink( '<a href="' . self::AFFILIATE . '">ホテル</a>' );
		$after1  = get_post_field( 'post_content', $postId );

		wp_update_post( array( 'ID' => $postId ) );
		$after2 = get_post_field( 'post_content', $postId );

		$this->assertSame( $after1, $after2 );
	}

	public function test_img_src_is_left_alone(): void {
		$pixel  = 'https://hb.afl.rakuten.co.jp/hsc/abc123/?me_id=1';
		$postId = $this->createPostWithLink(
			'<a href="' . self::AFFILIATE . '">ホテル</a><img src="' . $pixel . '" width="1" height="1">'
		);

		$content = get_post_field( 'post_content', $postId );

		$this->assertStringContainsString( 'src="' . $pixel . '"', $content );
	}

	public function test_removing_a_link_archives_its_row_but_keeps_it(): void {
		$postId = $this->createPostWithLink( '<a href="' . self::AFFILIATE . '">ホテル</a>' );
		$linkId = $this->links->findByPost( $postId )[0]['id'];

		wp_update_post(
			array(
				'ID'           => $postId,
				'post_content' => '<p>リンクを消しました</p>',
			)
		);

		$this->assertSame( array(), $this->links->findByPost( $postId ) );
		$this->assertSame( 0, $this->links->findById( $linkId )['status'] );
	}

	public function test_revisions_are_skipped(): void {
		$postId = $this->createPostWithLink( '<a href="' . self::AFFILIATE . '">ホテル</a>' );

		$before = count( $this->links->findByPost( $postId, false ) );
		wp_save_post_revision( $postId );
		$after  = count( $this->links->findByPost( $postId, false ) );

		$this->assertSame( $before, $after );
	}

	public function test_auto_draft_is_skipped(): void {
		$postId = self::factory()->post->create(
			array(
				'post_content' => '<a href="' . self::AFFILIATE . '">ホテル</a>',
				'post_status'  => 'auto-draft',
			)
		);

		$this->assertSame( array(), $this->links->findByPost( $postId, false ) );
	}

	public function test_posts_without_affiliate_links_are_untouched(): void {
		$content = '<p>ただの記事です。<a href="https://example.org/">よそ</a></p>';
		$postId  = $this->createPostWithLink( $content );

		$this->assertSame( $content, get_post_field( 'post_content', $postId ) );
		$this->assertSame( '', get_post_meta( $postId, PostSync::META_ORIGINAL, true ) );
	}

	public function test_restore_post_puts_the_original_urls_back(): void {
		$original = '<p><a rel="nofollow" href="' . self::AFFILIATE . '">ホテル</a></p>';
		$postId   = $this->createPostWithLink( $original );

		$this->assertTrue( $this->sync->restorePost( $postId ) );
		$this->assertSame( $original, get_post_field( 'post_content', $postId ) );
	}

	public function test_restore_post_returns_false_when_there_is_nothing_to_restore(): void {
		$postId = $this->createPostWithLink( '<p>リンクなし</p>' );

		$this->assertFalse( $this->sync->restorePost( $postId ) );
	}

	public function test_two_posts_sharing_a_url_get_different_codes(): void {
		$a = $this->createPostWithLink( '<a href="' . self::AFFILIATE . '">ホテル</a>' );
		$b = $this->createPostWithLink( '<a href="' . self::AFFILIATE . '">ホテル</a>' );

		$codeA = $this->links->findByPost( $a )[0]['code'];
		$codeB = $this->links->findByPost( $b )[0]['code'];

		$this->assertNotSame( $codeA, $codeB );
	}
}
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PostSyncTest"
```

Expected: FAIL — `Class "RLT\Frontend\PostSync" not found`

- [ ] **Step 3: 実装する**

`src/Frontend/PostSync.php`:

```php
<?php

declare(strict_types=1);

namespace RLT\Frontend;

use RLT\Data\LinkRepository;
use RLT\Settings;
use RLT\Support\ContentRewriter;
use RLT\Support\LinkExtractor;

/**
 * Detects affiliate links when a post is saved, issues short codes for them and
 * rewrites the stored content.
 */
final class PostSync {

	public const META_ORIGINAL = '_rlt_original_content';

	private LinkRepository $links;
	private ContentRewriter $rewriter;

	/**
	 * Guards against re-entering the sync for a post already being processed.
	 */
	private bool $running = false;

	public function __construct( ?LinkRepository $links = null ) {
		$this->links    = $links ?? new LinkRepository();
		$this->rewriter = new ContentRewriter();
	}

	public function register(): void {
		add_action( 'save_post', array( $this, 'onSavePost' ), 20, 2 );
	}

	/**
	 * @param \WP_Post $post
	 */
	public function onSavePost( int $postId, $post ): void {
		if ( ! $post instanceof \WP_Post || ! $this->shouldSync( $post ) ) {
			return;
		}

		$this->syncPost( $postId );
	}

	/**
	 * @return string[]
	 */
	public function syncedPostTypes(): array {
		return (array) apply_filters( 'rlt_synced_post_types', array( 'post', 'page' ) );
	}

	/**
	 * Extract, issue codes, rewrite. Returns true when the content changed.
	 */
	public function syncPost( int $postId ): bool {
		if ( $this->running ) {
			return false;
		}

		$post = get_post( $postId );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		$this->running = true;

		try {
			$content   = (string) $post->post_content;
			$extractor = new LinkExtractor( Settings::hosts(), Settings::shortBase() );
			$found     = $extractor->extract( $content );

			$map     = array();
			$keepIds = array();

			foreach ( $found as $item ) {
				$link = $this->links->findOrCreate( $item['url'], $postId, $item['label'] );

				if ( null === $link ) {
					continue;
				}

				$keepIds[]           = $link['id'];
				$map[ $item['url'] ] = Settings::shortUrl( $link['code'] );
			}

			// Links no longer present in the content are archived, never deleted:
			// their click history must survive and the short URL may be shared elsewhere.
			$this->links->archiveOthers( $postId, $keepIds );

			if ( array() === $map ) {
				return false;
			}

			$rewritten = $this->rewriter->rewrite( $content, $map );

			if ( $rewritten === $content ) {
				return false;
			}

			update_post_meta( $postId, self::META_ORIGINAL, $content );
			$this->writeContent( $postId, $rewritten );

			return true;
		} finally {
			$this->running = false;
		}
	}

	/**
	 * Put the original affiliate URLs back into the post content.
	 *
	 * The map comes from the links table rather than the post meta backup: the
	 * meta only holds the most recent save, so it is unreliable for posts that
	 * have been saved more than once.
	 */
	public function restorePost( int $postId ): bool {
		$post = get_post( $postId );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		$map = array();
		foreach ( $this->links->findByPost( $postId, false ) as $link ) {
			$map[ Settings::shortUrl( $link['code'] ) ] = $link['target_url'];
		}

		if ( array() === $map ) {
			return false;
		}

		$content  = (string) $post->post_content;
		$restored = $this->rewriter->restore( $content, $map );

		if ( $restored === $content ) {
			return false;
		}

		$this->writeContent( $postId, $restored );
		delete_post_meta( $postId, self::META_ORIGINAL );

		return true;
	}

	private function shouldSync( \WP_Post $post ): bool {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return false;
		}

		if ( in_array( $post->post_status, array( 'auto-draft', 'trash', 'inherit' ), true ) ) {
			return false;
		}

		return in_array( $post->post_type, $this->syncedPostTypes(), true );
	}

	/**
	 * Write post_content directly.
	 *
	 * wp_update_post() would re-fire save_post (recursion) and pile up revisions.
	 * A direct UPDATE plus a cache flush avoids both.
	 */
	private function writeContent( int $postId, string $content ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->posts,
			array( 'post_content' => $content ),
			array( 'ID' => $postId ),
			array( '%s' ),
			array( '%d' )
		);

		clean_post_cache( $postId );
	}
}
```

- [ ] **Step 4: `Plugin::boot()` に繋ぐ**

`src/Plugin.php` の `boot()` を差し替える。

```php
	public function boot(): void {
		add_action( 'plugins_loaded', array( Installer::class, 'maybeUpgrade' ) );

		( new \RLT\Frontend\PostSync() )->register();
	}
```

- [ ] **Step 5: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PostSyncTest"
```

Expected: PASS — `OK (14 tests, ...)`

- [ ] **Step 6: コミット**

```bash
git add src/Frontend/PostSync.php src/Plugin.php tests/Integration/Frontend/PostSyncTest.php
git commit -m "feat: 投稿保存時に楽天URLを短縮URLへ自動置換"
```

---

## Task 12: RedirectHandler（/go/{code} のリダイレクトと計測）

**Files:**
- Create: `src/Frontend/RedirectHandler.php`
- Modify: `src/Plugin.php`
- Test: `tests/Integration/Frontend/RedirectHandlerTest.php`

**Interfaces:**
- Consumes: `LinkRepository`, `EventRepository`, `RequestContext`, `CodeGenerator`, `Settings`
- Produces:
  - `RLT\Frontend\RedirectHandler::QUERY_VAR` (string `'rlt_code'`)
  - `RLT\Frontend\RedirectHandler::__construct(?LinkRepository $links = null, ?EventRepository $events = null)`
  - `register(): void`
  - `addRewriteRule(): void`
  - `addQueryVar(array $vars): array`
  - `resolve(string $code): ?array`
  - `trackClick(array $link): bool`
  - `handle(): void`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Integration/Frontend/RedirectHandlerTest.php`:

```php
<?php

namespace RLT\Tests\Integration\Frontend;

use RLT\Data\EventRepository;
use RLT\Data\LinkRepository;
use RLT\Frontend\RedirectHandler;
use RLT\Installer;
use RLT\Settings;
use RLT\Support\RequestContext;
use WP_UnitTestCase;

/**
 * Thrown from a wp_redirect filter so tests can observe the redirect without
 * the process exiting.
 */
final class RedirectCaught extends \Exception {

	public function __construct( public readonly string $location, public readonly int $status ) {
		parent::__construct( $location );
	}
}

final class RedirectHandlerTest extends WP_UnitTestCase {

	private const AFFILIATE = 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=x';

	private RedirectHandler $handler;
	private LinkRepository $links;

	protected function setUp(): void {
		parent::setUp();

		$_SERVER['REMOTE_ADDR']        = '203.0.113.5';
		$_SERVER['HTTP_USER_AGENT']    = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36';
		$_SERVER['HTTP_REFERER']       = 'https://example.com/hotel-article/';
		$_SERVER['REQUEST_METHOD']     = 'GET';
		RequestContext::reset();

		$this->links   = new LinkRepository();
		$this->handler = new RedirectHandler( $this->links, new EventRepository() );

		add_filter(
			'wp_redirect',
			static function ( $location, $status ) {
				throw new RedirectCaught( (string) $location, (int) $status );
			},
			10,
			2
		);
	}

	private function makeLink(): array {
		return $this->links->findOrCreate( self::AFFILIATE, 42, 'ホテル' );
	}

	public function test_resolve_finds_a_link_by_code(): void {
		$link = $this->makeLink();

		$this->assertSame( $link['id'], $this->handler->resolve( $link['code'] )['id'] );
	}

	public function test_resolve_returns_null_for_an_unknown_code(): void {
		$this->assertNull( $this->handler->resolve( 'zzzzzz' ) );
	}

	public function test_resolve_rejects_a_malformed_code_without_querying(): void {
		$this->assertNull( $this->handler->resolve( '../../etc/passwd' ) );
	}

	public function test_resolve_still_finds_archived_links(): void {
		$link = $this->makeLink();
		$this->links->update( $link['id'], array( 'status' => 0 ) );

		// アーカイブ済みの短縮URLが外部に共有されている可能性があるため、
		// リダイレクト自体は生かしておく。
		$this->assertNotNull( $this->handler->resolve( $link['code'] ) );
	}

	public function test_track_click_records_a_row(): void {
		global $wpdb;

		$link = $this->makeLink();

		$this->assertTrue( $this->handler->trackClick( $link ) );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Installer::clicksTable() . ' WHERE link_id = %d', $link['id'] )
		);
		$this->assertSame( 1, $count );
	}

	public function test_track_click_deduplicates_rapid_repeats(): void {
		global $wpdb;

		$link = $this->makeLink();

		$this->handler->trackClick( $link );
		$this->assertFalse( $this->handler->trackClick( $link ) );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Installer::clicksTable() . ' WHERE link_id = %d', $link['id'] )
		);
		$this->assertSame( 1, $count );
	}

	public function test_track_click_skips_logged_in_users_when_configured(): void {
		$link = $this->makeLink();

		Settings::update( array( 'exclude_logged_in' => 1 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertFalse( $this->handler->trackClick( $link ) );
	}

	public function test_handle_redirects_to_the_target_with_302(): void {
		$link = $this->makeLink();
		set_query_var( RedirectHandler::QUERY_VAR, $link['code'] );

		try {
			$this->handler->handle();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCaught $caught ) {
			$this->assertSame( self::AFFILIATE, $caught->location );
			// 301 だとブラウザにキャッシュされ2回目以降が計測できない。
			$this->assertSame( 302, $caught->status );
		}
	}

	public function test_handle_redirects_even_when_recording_throws(): void {
		$link = $this->makeLink();
		set_query_var( RedirectHandler::QUERY_VAR, $link['code'] );

		// 計測が壊れてもアフィリエイト収益を落としてはならない。
		add_action(
			'rlt_before_track_click',
			static function (): void {
				throw new \RuntimeException( 'database is on fire' );
			}
		);

		$this->expectException( RedirectCaught::class );
		$this->handler->handle();
	}

	public function test_handle_sends_the_visitor_home_for_an_unknown_code(): void {
		Settings::update( array( 'unknown_code' => 'home' ) );
		set_query_var( RedirectHandler::QUERY_VAR, 'zzzzzz' );

		try {
			$this->handler->handle();
			$this->fail( 'Expected a redirect.' );
		} catch ( RedirectCaught $caught ) {
			$this->assertSame( home_url( '/' ), $caught->location );
		}
	}

	public function test_handle_does_nothing_without_a_code(): void {
		global $wpdb;

		set_query_var( RedirectHandler::QUERY_VAR, '' );

		// リダイレクトしていれば wp_redirect フィルタが RedirectCaught を投げ、
		// このテストは例外で落ちる。落ちないこと自体が「素通りした」証拠。
		$this->handler->handle();

		$this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::clicksTable() ) );
	}

	public function test_head_requests_are_not_recorded(): void {
		global $wpdb;

		$link                      = $this->makeLink();
		$_SERVER['REQUEST_METHOD'] = 'HEAD';
		set_query_var( RedirectHandler::QUERY_VAR, $link['code'] );

		try {
			$this->handler->handle();
		} catch ( RedirectCaught $caught ) {
			$this->assertSame( self::AFFILIATE, $caught->location );
		}

		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::clicksTable() );
		$this->assertSame( 0, $count );
	}

	public function test_rewrite_rule_is_registered_for_the_configured_prefix(): void {
		Settings::update( array( 'prefix' => 'out' ) );

		$this->handler->addRewriteRule();

		global $wp_rewrite;
		$rules = $wp_rewrite->extra_rules_top;

		$this->assertArrayHasKey( '^out/([a-z0-9]{4,16})/?$', $rules );
	}

	public function test_query_var_is_registered(): void {
		$this->assertContains( RedirectHandler::QUERY_VAR, $this->handler->addQueryVar( array() ) );
	}
}
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RedirectHandlerTest"
```

Expected: FAIL — `Class "RLT\Frontend\RedirectHandler" not found`

- [ ] **Step 3: 実装する**

`src/Frontend/RedirectHandler.php`:

```php
<?php

declare(strict_types=1);

namespace RLT\Frontend;

use RLT\Data\EventRepository;
use RLT\Data\LinkRepository;
use RLT\Settings;
use RLT\Support\CodeGenerator;
use RLT\Support\RequestContext;

/**
 * Resolves /{prefix}/{code}, records the click and sends the visitor on.
 *
 * The governing rule here: the redirect must happen even if tracking fails.
 * Losing a click record is an inconvenience; losing the affiliate click is
 * lost revenue.
 */
final class RedirectHandler {

	public const QUERY_VAR = 'rlt_code';

	private LinkRepository $links;
	private EventRepository $events;

	public function __construct( ?LinkRepository $links = null, ?EventRepository $events = null ) {
		$this->links  = $links ?? new LinkRepository();
		$this->events = $events ?? new EventRepository();
	}

	public function register(): void {
		add_action( 'init', array( $this, 'addRewriteRule' ) );
		add_filter( 'query_vars', array( $this, 'addQueryVar' ) );
		add_action( 'template_redirect', array( $this, 'handle' ), 0 );
	}

	public function addRewriteRule(): void {
		$prefix = Settings::prefix();

		add_rewrite_rule(
			'^' . $prefix . '/([a-z0-9]{4,16})/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public function addQueryVar( array $vars ): array {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	public function resolve( string $code ): ?array {
		if ( ! CodeGenerator::isValid( $code ) ) {
			return null;
		}

		return $this->links->findByCode( $code );
	}

	/**
	 * @param array<string, mixed> $link
	 * @return bool True when a row was written.
	 */
	public function trackClick( array $link ): bool {
		do_action( 'rlt_before_track_click', $link );

		if ( Settings::get( 'exclude_logged_in' ) && is_user_logged_in() ) {
			return false;
		}

		$window = (int) Settings::get( 'click_dedup_seconds' );

		if ( $this->events->hasRecentClick( (int) $link['id'], RequestContext::visitorHash(), $window ) ) {
			return false;
		}

		return $this->events->recordClick( (int) $link['id'], (int) $link['post_id'] );
	}

	public function handle(): void {
		$code = (string) get_query_var( self::QUERY_VAR );

		if ( '' === $code ) {
			return;
		}

		$this->sendNoCacheHeaders();

		$link = $this->resolve( $code );

		if ( null === $link ) {
			$this->handleUnknownCode();

			return;
		}

		$isHead = isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] );

		if ( ! $isHead ) {
			// Tracking is isolated: a failure here must never stop the redirect.
			try {
				$this->trackClick( $link );
			} catch ( \Throwable $e ) {
				error_log( '[rakuten-link-tracker] click tracking failed: ' . $e->getMessage() );
			}
		}

		wp_redirect( $link['target_url'], 302 );
		exit;
	}

	private function handleUnknownCode(): void {
		if ( Settings::UNKNOWN_CODE_404 === Settings::get( 'unknown_code' ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();

			return;
		}

		wp_redirect( home_url( '/' ), 302 );
		exit;
	}

	/**
	 * Keep the redirect out of every cache layer.
	 *
	 * A cached /go/ response would serve the redirect without ever reaching PHP,
	 * and the click would go uncounted.
	 */
	private function sendNoCacheHeaders(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		if ( headers_sent() ) {
			return;
		}

		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		header( 'Pragma: no-cache' );
		header( 'X-Robots-Tag: noindex, nofollow', true );
	}
}
```

- [ ] **Step 4: `Plugin::boot()` に繋ぐ**

`src/Plugin.php` の `boot()` に1行追加する。

```php
	public function boot(): void {
		add_action( 'plugins_loaded', array( Installer::class, 'maybeUpgrade' ) );

		( new \RLT\Frontend\PostSync() )->register();
		( new \RLT\Frontend\RedirectHandler() )->register();
	}
```

- [ ] **Step 5: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RedirectHandlerTest"
```

Expected: PASS — `OK (14 tests, ...)`

- [ ] **Step 6: 手動で動作確認する**

```powershell
npx wp-env run cli "wp plugin activate rakuten-link-tracker"
npx wp-env run cli "wp rewrite flush"
npx wp-env run cli "wp post create --post_title='テスト' --post_status=publish --post_content='<a href=\"https://hb.afl.rakuten.co.jp/hgc/abc/?pc=x\">ホテル</a>' --porcelain"
npx wp-env run cli "wp post get <上で出たID> --field=post_content"
```

Expected: 本文の `href` が `http://localhost:8888/go/xxxxxx` に置き換わっている

- [ ] **Step 7: コミット**

```bash
git add src/Frontend/RedirectHandler.php src/Plugin.php tests/Integration/Frontend/RedirectHandlerTest.php
git commit -m "feat: /go/{code} のリダイレクトとクリック計測を追加"
```

---

## Task 13: BeaconController（PV計測）

**Files:**
- Create: `src/Frontend/BeaconController.php`
- Create: `assets/beacon.js`
- Modify: `src/Plugin.php`
- Test: `tests/Integration/Frontend/BeaconControllerTest.php`

**Interfaces:**
- Consumes: `LinkRepository`, `EventRepository`, `RequestContext`, `Settings`
- Produces:
  - `RLT\Frontend\BeaconController::REST_NAMESPACE` (string `'rlt/v1'`)
  - `RLT\Frontend\BeaconController::__construct(?LinkRepository $links = null, ?EventRepository $events = null)`
  - `register(): void`
  - `registerRoutes(): void`
  - `enqueue(): void`
  - `shouldEnqueue(): bool`
  - `handleView(\WP_REST_Request $request): \WP_REST_Response`
  - `trackView(int $postId): bool`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Integration/Frontend/BeaconControllerTest.php`:

```php
<?php

namespace RLT\Tests\Integration\Frontend;

use RLT\Data\EventRepository;
use RLT\Data\LinkRepository;
use RLT\Frontend\BeaconController;
use RLT\Installer;
use RLT\Settings;
use RLT\Support\RequestContext;
use WP_REST_Request;
use WP_UnitTestCase;

final class BeaconControllerTest extends WP_UnitTestCase {

	private BeaconController $beacon;
	private int $postId;

	protected function setUp(): void {
		parent::setUp();

		$_SERVER['REMOTE_ADDR']     = '203.0.113.5';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36';
		unset( $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_ORIGIN'] );
		RequestContext::reset();

		$this->beacon = new BeaconController();
		$this->postId = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<a href="https://hb.afl.rakuten.co.jp/hgc/abc/?pc=x">ホテル</a>',
			)
		);

		// The plugin's own save_post hook is not registered in this test, so create
		// the link row explicitly.
		( new LinkRepository() )->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/abc/?pc=x', $this->postId, 'ホテル' );

		do_action( 'rest_api_init' );
	}

	private function request( int $postId ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/rlt/v1/view' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'post_id' => $postId ) ) );

		return $request;
	}

	public function test_view_endpoint_is_registered(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/rlt/v1/view', $routes );
	}

	public function test_posting_a_view_records_a_row(): void {
		global $wpdb;

		$response = rest_get_server()->dispatch( $this->request( $this->postId ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['recorded'] );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Installer::viewsTable() . ' WHERE post_id = %d', $this->postId )
		);
		$this->assertSame( 1, $count );
	}

	public function test_unknown_post_id_is_rejected(): void {
		$response = rest_get_server()->dispatch( $this->request( 999999 ) );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_repeat_views_within_the_window_are_deduplicated(): void {
		global $wpdb;

		rest_get_server()->dispatch( $this->request( $this->postId ) );
		$second = rest_get_server()->dispatch( $this->request( $this->postId ) );

		$this->assertFalse( $second->get_data()['recorded'] );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Installer::viewsTable() . ' WHERE post_id = %d', $this->postId )
		);
		$this->assertSame( 1, $count );
	}

	public function test_a_referer_from_another_site_is_rejected(): void {
		$_SERVER['HTTP_REFERER'] = 'https://evil.example/attack';

		$response = rest_get_server()->dispatch( $this->request( $this->postId ) );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_a_referer_from_this_site_is_accepted(): void {
		$_SERVER['HTTP_REFERER'] = home_url( '/some-article/' );

		$response = rest_get_server()->dispatch( $this->request( $this->postId ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_logged_in_users_are_excluded_when_configured(): void {
		Settings::update( array( 'exclude_logged_in' => 1 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertFalse( $this->beacon->trackView( $this->postId ) );
	}

	public function test_should_enqueue_is_true_on_a_singular_post_with_links(): void {
		$this->go_to( get_permalink( $this->postId ) );

		$this->assertTrue( $this->beacon->shouldEnqueue() );
	}

	public function test_should_enqueue_is_false_on_a_post_without_links(): void {
		$plain = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( get_permalink( $plain ) );

		$this->assertFalse( $this->beacon->shouldEnqueue() );
	}

	public function test_should_enqueue_is_false_on_an_archive(): void {
		$this->go_to( home_url( '/' ) );

		$this->assertFalse( $this->beacon->shouldEnqueue() );
	}
}
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter BeaconControllerTest"
```

Expected: FAIL — `Class "RLT\Frontend\BeaconController" not found`

- [ ] **Step 3: 実装する**

`src/Frontend/BeaconController.php`:

```php
<?php

declare(strict_types=1);

namespace RLT\Frontend;

use RLT\Data\EventRepository;
use RLT\Data\LinkRepository;
use RLT\Plugin;
use RLT\Settings;
use RLT\Support\RequestContext;

/**
 * Counts article pageviews via a JavaScript beacon.
 *
 * Counting in PHP would be simpler, but any page cache or CDN would serve most
 * requests without running PHP at all and the numbers would be badly short.
 *
 * There is deliberately no nonce: a cached page hands every visitor the same
 * stale nonce, which breaks the endpoint entirely. Since the endpoint only
 * increments an anonymous counter, origin checking and deduplication are
 * proportionate protection.
 */
final class BeaconController {

	public const REST_NAMESPACE = 'rlt/v1';

	private LinkRepository $links;
	private EventRepository $events;

	public function __construct( ?LinkRepository $links = null, ?EventRepository $events = null ) {
		$this->links  = $links ?? new LinkRepository();
		$this->events = $events ?? new EventRepository();
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function registerRoutes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/view',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handleView' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	public function handleView( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->originLooksLocal() ) {
			return new \WP_REST_Response( array( 'recorded' => false ), 403 );
		}

		$postId = (int) $request->get_param( 'post_id' );
		$post   = get_post( $postId );

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return new \WP_REST_Response( array( 'recorded' => false ), 404 );
		}

		try {
			$recorded = $this->trackView( $postId );
		} catch ( \Throwable $e ) {
			error_log( '[rakuten-link-tracker] view tracking failed: ' . $e->getMessage() );
			$recorded = false;
		}

		return new \WP_REST_Response( array( 'recorded' => $recorded ), 200 );
	}

	public function trackView( int $postId ): bool {
		if ( Settings::get( 'exclude_logged_in' ) && is_user_logged_in() ) {
			return false;
		}

		$window = (int) Settings::get( 'view_dedup_seconds' );

		if ( $this->events->hasRecentView( $postId, RequestContext::visitorHash(), $window ) ) {
			return false;
		}

		return $this->events->recordView( $postId );
	}

	public function shouldEnqueue(): bool {
		if ( ! is_singular() ) {
			return false;
		}

		if ( Settings::get( 'exclude_logged_in' ) && is_user_logged_in() ) {
			return false;
		}

		$postId = get_queried_object_id();

		return $postId > 0 && $this->links->countActiveForPost( $postId ) > 0;
	}

	public function enqueue(): void {
		if ( ! $this->shouldEnqueue() ) {
			return;
		}

		wp_enqueue_script(
			'rlt-beacon',
			Plugin::url( 'assets/beacon.js' ),
			array(),
			Plugin::VERSION,
			true
		);

		wp_add_inline_script(
			'rlt-beacon',
			'window.rltBeacon = ' . wp_json_encode(
				array(
					'endpoint' => rest_url( self::REST_NAMESPACE . '/view' ),
					'postId'   => get_queried_object_id(),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Reject only an explicit cross-site origin.
	 *
	 * Some privacy settings strip both Origin and Referer; treating that as an
	 * attack would silently lose real pageviews.
	 */
	private function originLooksLocal(): bool {
		$homeHost = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		foreach ( array( 'HTTP_ORIGIN', 'HTTP_REFERER' ) as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			$host = (string) wp_parse_url( (string) wp_unslash( $_SERVER[ $key ] ), PHP_URL_HOST );

			if ( '' !== $host && $host !== $homeHost ) {
				return false;
			}
		}

		return true;
	}
}
```

- [ ] **Step 4: `assets/beacon.js` を作る**

```js
( function () {
	'use strict';

	var config = window.rltBeacon;

	if ( ! config || ! config.endpoint || ! config.postId ) {
		return;
	}

	var payload = JSON.stringify( { post_id: config.postId } );

	// sendBeacon survives the page being closed mid-flight, which a plain fetch
	// does not.
	if ( navigator.sendBeacon ) {
		navigator.sendBeacon(
			config.endpoint,
			new Blob( [ payload ], { type: 'application/json' } )
		);
		return;
	}

	fetch( config.endpoint, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: payload,
		keepalive: true,
		credentials: 'same-origin'
	} ).catch( function () {
		// A failed pageview count is not worth surfacing to the visitor.
	} );
}() );
```

- [ ] **Step 5: `Plugin::boot()` に繋ぐ**

```php
	public function boot(): void {
		add_action( 'plugins_loaded', array( Installer::class, 'maybeUpgrade' ) );

		( new \RLT\Frontend\PostSync() )->register();
		( new \RLT\Frontend\RedirectHandler() )->register();
		( new \RLT\Frontend\BeaconController() )->register();
	}
```

- [ ] **Step 6: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter BeaconControllerTest"
```

Expected: PASS — `OK (11 tests, ...)`

- [ ] **Step 7: コミット**

```bash
git add src/Frontend/BeaconController.php assets/beacon.js src/Plugin.php tests/Integration/Frontend/BeaconControllerTest.php
git commit -m "feat: JSビーコンによる記事PV計測を追加"
```

---

## Task 14: DateRange（期間の扱い）

集計クエリの前に、期間指定とタイムゾーン変換を1か所にまとめる。ここを各クエリに散らすと必ずズレる。

**Files:**
- Create: `src/Support/DateRange.php`
- Test: `tests/Integration/Support/DateRangeTest.php`

**Interfaces:**
- Consumes: `wp_timezone()`
- Produces:
  - `RLT\Support\DateRange::__construct(string $from, string $to)` — 両方 `Y-m-d`、サイトのタイムゾーン基準
  - `RLT\Support\DateRange::lastDays(int $days): self`
  - `RLT\Support\DateRange::fromRequest(?string $from, ?string $to, int $defaultDays = 28): self`
  - `fromDate(): string` / `toDate(): string`
  - `startUtc(): string` / `endUtc(): string` — `Y-m-d H:i:s`
  - `days(): array` — `['2026-09-01', ...]` の全日リスト
  - `dayCount(): int`
  - `previous(): self` — 同じ長さの直前期間
  - `offsetSeconds(): int` — サイトのUTCオフセット秒数

- [ ] **Step 1: 失敗するテストを書く**

`tests/Integration/Support/DateRangeTest.php`:

```php
<?php

namespace RLT\Tests\Integration\Support;

use RLT\Support\DateRange;
use WP_UnitTestCase;

final class DateRangeTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		update_option( 'timezone_string', 'Asia/Tokyo' );
	}

	public function test_utc_bounds_cover_the_whole_local_days(): void {
		$range = new DateRange( '2026-09-01', '2026-09-02' );

		// JST は UTC+9 なので、9/1 00:00 JST は 8/31 15:00 UTC。
		$this->assertSame( '2026-08-31 15:00:00', $range->startUtc() );
		$this->assertSame( '2026-09-02 14:59:59', $range->endUtc() );
	}

	public function test_offset_seconds_matches_the_site_timezone(): void {
		$this->assertSame( 32400, ( new DateRange( '2026-09-01', '2026-09-02' ) )->offsetSeconds() );
	}

	public function test_days_lists_every_local_day_inclusive(): void {
		$range = new DateRange( '2026-09-01', '2026-09-03' );

		$this->assertSame( array( '2026-09-01', '2026-09-02', '2026-09-03' ), $range->days() );
		$this->assertSame( 3, $range->dayCount() );
	}

	public function test_reversed_dates_are_swapped(): void {
		$range = new DateRange( '2026-09-05', '2026-09-01' );

		$this->assertSame( '2026-09-01', $range->fromDate() );
		$this->assertSame( '2026-09-05', $range->toDate() );
	}

	public function test_previous_returns_an_equally_long_earlier_window(): void {
		$previous = ( new DateRange( '2026-09-08', '2026-09-14' ) )->previous();

		$this->assertSame( '2026-09-01', $previous->fromDate() );
		$this->assertSame( '2026-09-07', $previous->toDate() );
		$this->assertSame( 7, $previous->dayCount() );
	}

	public function test_last_days_ends_today(): void {
		$range = DateRange::lastDays( 7 );

		$this->assertSame( 7, $range->dayCount() );
		$this->assertSame( current_time( 'Y-m-d' ), $range->toDate() );
	}

	public function test_from_request_falls_back_to_the_default_window(): void {
		$range = DateRange::fromRequest( null, null, 28 );

		$this->assertSame( 28, $range->dayCount() );
	}

	public function test_from_request_rejects_malformed_dates(): void {
		$range = DateRange::fromRequest( 'not-a-date', '2026-13-45', 7 );

		$this->assertSame( 7, $range->dayCount() );
	}

	public function test_from_request_accepts_valid_dates(): void {
		$range = DateRange::fromRequest( '2026-09-01', '2026-09-03', 28 );

		$this->assertSame( '2026-09-01', $range->fromDate() );
		$this->assertSame( '2026-09-03', $range->toDate() );
	}
}
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter DateRangeTest"
```

Expected: FAIL — `Class "RLT\Support\DateRange" not found`

- [ ] **Step 3: 実装する**

`src/Support/DateRange.php`:

```php
<?php

declare(strict_types=1);

namespace RLT\Support;

/**
 * A closed range of site-local days, with the UTC bounds needed to query the
 * event tables.
 *
 * Every timestamp in the database is UTC while every report is read in site
 * time, so the conversion lives here and nowhere else.
 */
final class DateRange {

	private const FORMAT = 'Y-m-d';

	private \DateTimeImmutable $from;
	private \DateTimeImmutable $to;

	public function __construct( string $from, string $to ) {
		$tz = wp_timezone();

		$start = new \DateTimeImmutable( $from . ' 00:00:00', $tz );
		$end   = new \DateTimeImmutable( $to . ' 00:00:00', $tz );

		if ( $end < $start ) {
			[ $start, $end ] = array( $end, $start );
		}

		$this->from = $start;
		$this->to   = $end;
	}

	public static function lastDays( int $days ): self {
		$days  = max( 1, $days );
		$today = current_time( self::FORMAT );
		$start = ( new \DateTimeImmutable( $today, wp_timezone() ) )->modify( '-' . ( $days - 1 ) . ' days' );

		return new self( $start->format( self::FORMAT ), $today );
	}

	/**
	 * Build a range from untrusted input, falling back to a default window.
	 */
	public static function fromRequest( ?string $from, ?string $to, int $defaultDays = 28 ): self {
		if ( ! self::isValidDate( $from ) || ! self::isValidDate( $to ) ) {
			return self::lastDays( $defaultDays );
		}

		return new self( (string) $from, (string) $to );
	}

	public function fromDate(): string {
		return $this->from->format( self::FORMAT );
	}

	public function toDate(): string {
		return $this->to->format( self::FORMAT );
	}

	public function startUtc(): string {
		return $this->from->setTime( 0, 0, 0 )
			->setTimezone( new \DateTimeZone( 'UTC' ) )
			->format( 'Y-m-d H:i:s' );
	}

	public function endUtc(): string {
		return $this->to->setTime( 23, 59, 59 )
			->setTimezone( new \DateTimeZone( 'UTC' ) )
			->format( 'Y-m-d H:i:s' );
	}

	/**
	 * @return string[]
	 */
	public function days(): array {
		$days   = array();
		$cursor = $this->from;

		while ( $cursor <= $this->to ) {
			$days[] = $cursor->format( self::FORMAT );
			$cursor = $cursor->modify( '+1 day' );
		}

		return $days;
	}

	public function dayCount(): int {
		return count( $this->days() );
	}

	/**
	 * The equally long window immediately before this one, for period-over-period
	 * comparisons.
	 */
	public function previous(): self {
		$length = $this->dayCount();
		$end    = $this->from->modify( '-1 day' );
		$start  = $end->modify( '-' . ( $length - 1 ) . ' days' );

		return new self( $start->format( self::FORMAT ), $end->format( self::FORMAT ) );
	}

	/**
	 * Seconds to add to a UTC timestamp to get site-local time.
	 */
	public function offsetSeconds(): int {
		return wp_timezone()->getOffset( $this->from );
	}

	private static function isValidDate( ?string $value ): bool {
		if ( null === $value || '' === $value ) {
			return false;
		}

		$parsed = \DateTimeImmutable::createFromFormat( '!' . self::FORMAT, $value, new \DateTimeZone( 'UTC' ) );

		return $parsed instanceof \DateTimeImmutable && $parsed->format( self::FORMAT ) === $value;
	}
}
```

- [ ] **Step 4: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter DateRangeTest"
```

Expected: PASS — `OK (9 tests, ...)`

- [ ] **Step 5: コミット**

```bash
git add src/Support/DateRange.php tests/Integration/Support/DateRangeTest.php
git commit -m "feat: 期間指定とタイムゾーン変換をDateRangeに集約"
```

---

## Task 15: EventRepository の集計クエリ

**Files:**
- Modify: `src/Data/EventRepository.php`
- Test: `tests/Integration/Data/EventRepositoryStatsTest.php`

**Interfaces:**
- Consumes: `RLT\Support\DateRange`, `RLT\Installer`
- Produces（すべて `EventRepository` のメソッド）:
  - `summary(DateRange $range, bool $includeBots = false): array` → `['clicks'=>int,'unique_clicks'=>int,'views'=>int,'unique_views'=>int,'ctr'=>float]`
  - `daily(DateRange $range, bool $includeBots = false): array` → `[['date'=>string,'clicks'=>int,'views'=>int], ...]`（欠損日は0で埋める）
  - `byPost(DateRange $range, bool $includeBots, string $orderby, int $limit): array` → `[['post_id'=>int,'views'=>int,'clicks'=>int,'ctr'=>float], ...]`
  - `byLink(DateRange $range, bool $includeBots, ?int $postId, string $orderby, int $limit): array` → `[['link_id'=>int,'code'=>string,'label'=>string,'post_id'=>int,'target_url'=>string,'status'=>int,'clicks'=>int,'unique_clicks'=>int,'views'=>int,'ctr'=>float,'last_click'=>?string], ...]`
  - `linkDetail(int $linkId, DateRange $range, bool $includeBots): array` → `['daily'=>[['date','clicks']], 'referers'=>[['referer','clicks']], 'devices'=>[['device'=>int,'label'=>string,'clicks'=>int]]]`
  - `clicksForExport(DateRange $range, bool $includeBots): array`
  - `viewsForExport(DateRange $range, bool $includeBots): array`
  - `purgeOlderThan(int $days): int`

`ctr` は「クリック ÷ PV」を小数第4位まで丸めた値。PVが0のときは `0.0`。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Integration/Data/EventRepositoryStatsTest.php`:

```php
<?php

namespace RLT\Tests\Integration\Data;

use RLT\Data\EventRepository;
use RLT\Data\LinkRepository;
use RLT\Installer;
use RLT\Support\DateRange;
use RLT\Support\DeviceDetector;
use WP_UnitTestCase;

final class EventRepositoryStatsTest extends WP_UnitTestCase {

	private EventRepository $events;
	private LinkRepository $links;

	protected function setUp(): void {
		parent::setUp();
		update_option( 'timezone_string', 'Asia/Tokyo' );

		$this->events = new EventRepository();
		$this->links  = new LinkRepository();
	}

	/**
	 * Insert a click directly so the test controls the timestamp and visitor.
	 */
	private function click( int $linkId, int $postId, string $localDate, string $visitor = 'v1', bool $isBot = false, string $referer = '', int $device = DeviceDetector::DESKTOP ): void {
		global $wpdb;

		$wpdb->insert(
			Installer::clicksTable(),
			array(
				'link_id'      => $linkId,
				'post_id'      => $postId,
				// 12:00 JST は必ずその日の中に収まる。
				'clicked_at'   => get_gmt_from_date( $localDate . ' 12:00:00' ),
				'visitor_hash' => str_pad( $visitor, 64, '0' ),
				'referer'      => $referer,
				'device'       => $device,
				'is_bot'       => $isBot ? 1 : 0,
			)
		);
	}

	private function view( int $postId, string $localDate, string $visitor = 'v1', bool $isBot = false ): void {
		global $wpdb;

		$wpdb->insert(
			Installer::viewsTable(),
			array(
				'post_id'      => $postId,
				'viewed_at'    => get_gmt_from_date( $localDate . ' 12:00:00' ),
				'visitor_hash' => str_pad( $visitor, 64, '0' ),
				'referer'      => '',
				'device'       => DeviceDetector::DESKTOP,
				'is_bot'       => $isBot ? 1 : 0,
			)
		);
	}

	public function test_summary_counts_clicks_and_views(): void {
		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );

		$this->click( $link['id'], 42, '2026-09-01', 'v1' );
		$this->click( $link['id'], 42, '2026-09-01', 'v2' );
		$this->view( 42, '2026-09-01', 'v1' );
		$this->view( 42, '2026-09-01', 'v2' );
		$this->view( 42, '2026-09-01', 'v3' );
		$this->view( 42, '2026-09-01', 'v4' );

		$summary = $this->events->summary( new DateRange( '2026-09-01', '2026-09-01' ) );

		$this->assertSame( 2, $summary['clicks'] );
		$this->assertSame( 4, $summary['views'] );
		$this->assertSame( 0.5, $summary['ctr'] );
	}

	public function test_summary_counts_unique_visitors(): void {
		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );

		$this->click( $link['id'], 42, '2026-09-01', 'v1' );
		$this->click( $link['id'], 42, '2026-09-01', 'v1' );
		$this->click( $link['id'], 42, '2026-09-01', 'v2' );

		$summary = $this->events->summary( new DateRange( '2026-09-01', '2026-09-01' ) );

		$this->assertSame( 3, $summary['clicks'] );
		$this->assertSame( 2, $summary['unique_clicks'] );
	}

	public function test_summary_excludes_bots_by_default(): void {
		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );

		$this->click( $link['id'], 42, '2026-09-01', 'v1' );
		$this->click( $link['id'], 42, '2026-09-01', 'bot', true );

		$range = new DateRange( '2026-09-01', '2026-09-01' );

		$this->assertSame( 1, $this->events->summary( $range )['clicks'] );
		$this->assertSame( 2, $this->events->summary( $range, true )['clicks'] );
	}

	public function test_summary_ignores_events_outside_the_range(): void {
		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );

		$this->click( $link['id'], 42, '2026-08-31' );
		$this->click( $link['id'], 42, '2026-09-01' );
		$this->click( $link['id'], 42, '2026-09-03' );

		$this->assertSame( 1, $this->events->summary( new DateRange( '2026-09-01', '2026-09-02' ) )['clicks'] );
	}

	public function test_summary_ctr_is_zero_when_there_are_no_views(): void {
		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );
		$this->click( $link['id'], 42, '2026-09-01' );

		$this->assertSame( 0.0, $this->events->summary( new DateRange( '2026-09-01', '2026-09-01' ) )['ctr'] );
	}

	public function test_daily_fills_gaps_with_zeros(): void {
		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );

		$this->click( $link['id'], 42, '2026-09-01' );
		$this->click( $link['id'], 42, '2026-09-03' );

		$daily = $this->events->daily( new DateRange( '2026-09-01', '2026-09-03' ) );

		$this->assertCount( 3, $daily );
		$this->assertSame( array( '2026-09-01', '2026-09-02', '2026-09-03' ), array_column( $daily, 'date' ) );
		$this->assertSame( array( 1, 0, 1 ), array_column( $daily, 'clicks' ) );
	}

	public function test_daily_groups_by_site_local_day(): void {
		global $wpdb;

		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );

		// 2026-09-01 23:30 JST = 2026-09-01 14:30 UTC。JST の 9/1 に入るべき。
		$wpdb->insert(
			Installer::clicksTable(),
			array(
				'link_id'      => $link['id'],
				'post_id'      => 42,
				'clicked_at'   => '2026-09-01 14:30:00',
				'visitor_hash' => str_pad( 'v1', 64, '0' ),
				'referer'      => '',
				'device'       => 1,
				'is_bot'       => 0,
			)
		);

		$daily = $this->events->daily( new DateRange( '2026-09-01', '2026-09-02' ) );

		$this->assertSame( 1, $daily[0]['clicks'] );
		$this->assertSame( 0, $daily[1]['clicks'] );
	}

	public function test_by_post_returns_views_clicks_and_ctr(): void {
		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );

		$this->click( $link['id'], 42, '2026-09-01', 'v1' );
		$this->view( 42, '2026-09-01', 'v1' );
		$this->view( 42, '2026-09-01', 'v2' );
		$this->view( 43, '2026-09-01', 'v3' );

		$rows = $this->events->byPost( new DateRange( '2026-09-01', '2026-09-01' ), false, 'clicks', 50 );
		$byId = array_column( $rows, null, 'post_id' );

		$this->assertSame( 2, $byId[42]['views'] );
		$this->assertSame( 1, $byId[42]['clicks'] );
		$this->assertSame( 0.5, $byId[42]['ctr'] );
		// クリックのない記事も PV があれば出す（改善候補として見たいため）。
		$this->assertSame( 0, $byId[43]['clicks'] );
	}

	public function test_by_post_can_order_by_ctr_ascending(): void {
		$linkA = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );
		$linkB = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/b', 43, 'B' );

		$this->click( $linkA['id'], 42, '2026-09-01', 'v1' );
		$this->view( 42, '2026-09-01', 'v1' );

		$this->view( 43, '2026-09-01', 'v2' );
		$this->view( 43, '2026-09-01', 'v3' );

		$rows = $this->events->byPost( new DateRange( '2026-09-01', '2026-09-01' ), false, 'ctr_asc', 50 );

		$this->assertSame( 43, $rows[0]['post_id'] );
	}

	public function test_by_link_joins_link_metadata(): void {
		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'ホテルA' );

		$this->click( $link['id'], 42, '2026-09-01', 'v1' );
		$this->click( $link['id'], 42, '2026-09-01', 'v1' );
		$this->view( 42, '2026-09-01', 'v1' );
		$this->view( 42, '2026-09-01', 'v2' );

		$rows = $this->events->byLink( new DateRange( '2026-09-01', '2026-09-01' ), false, null, 'clicks', 50 );

		$this->assertCount( 1, $rows );
		$this->assertSame( $link['id'], $rows[0]['link_id'] );
		$this->assertSame( 'ホテルA', $rows[0]['label'] );
		$this->assertSame( $link['code'], $rows[0]['code'] );
		$this->assertSame( 2, $rows[0]['clicks'] );
		$this->assertSame( 1, $rows[0]['unique_clicks'] );
		$this->assertSame( 2, $rows[0]['views'] );
		$this->assertSame( 1.0, $rows[0]['ctr'] );
		$this->assertNotNull( $rows[0]['last_click'] );
	}

	public function test_by_link_includes_links_with_no_clicks(): void {
		$this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'クリックゼロ' );

		$rows = $this->events->byLink( new DateRange( '2026-09-01', '2026-09-01' ), false, null, 'clicks', 50 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 0, $rows[0]['clicks'] );
		$this->assertNull( $rows[0]['last_click'] );
	}

	public function test_by_link_can_filter_to_one_post(): void {
		$this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );
		$this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/b', 43, 'B' );

		$rows = $this->events->byLink( new DateRange( '2026-09-01', '2026-09-01' ), false, 42, 'clicks', 50 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 42, $rows[0]['post_id'] );
	}

	public function test_link_detail_breaks_down_by_day_referer_and_device(): void {
		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );

		$this->click( $link['id'], 42, '2026-09-01', 'v1', false, 'https://www.google.com/', DeviceDetector::MOBILE );
		$this->click( $link['id'], 42, '2026-09-01', 'v2', false, 'https://www.google.com/', DeviceDetector::DESKTOP );
		$this->click( $link['id'], 42, '2026-09-02', 'v3', false, '', DeviceDetector::MOBILE );

		$detail = $this->events->linkDetail( $link['id'], new DateRange( '2026-09-01', '2026-09-02' ), false );

		$this->assertSame( array( 2, 1 ), array_column( $detail['daily'], 'clicks' ) );

		$referers = array_column( $detail['referers'], 'clicks', 'referer' );
		$this->assertSame( 2, $referers['https://www.google.com/'] );

		$devices = array_column( $detail['devices'], 'clicks', 'label' );
		$this->assertSame( 2, $devices['mobile'] );
		$this->assertSame( 1, $devices['desktop'] );
	}

	public function test_purge_deletes_only_rows_older_than_the_cutoff(): void {
		global $wpdb;

		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );

		$this->click( $link['id'], 42, gmdate( 'Y-m-d' ) );
		$this->click( $link['id'], 42, gmdate( 'Y-m-d', strtotime( '-400 days' ) ) );
		$this->view( 42, gmdate( 'Y-m-d', strtotime( '-400 days' ) ) );

		$deleted = $this->events->purgeOlderThan( 365 );

		$this->assertSame( 2, $deleted );
		$this->assertSame( '1', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::clicksTable() ) );
		$this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::viewsTable() ) );
	}

	public function test_purge_with_zero_days_keeps_everything(): void {
		global $wpdb;

		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );
		$this->click( $link['id'], 42, gmdate( 'Y-m-d', strtotime( '-1000 days' ) ) );

		$this->assertSame( 0, $this->events->purgeOlderThan( 0 ) );
		$this->assertSame( '1', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::clicksTable() ) );
	}

	public function test_clicks_for_export_returns_flat_rows(): void {
		$link = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'ホテルA' );
		$this->click( $link['id'], 42, '2026-09-01' );

		$rows = $this->events->clicksForExport( new DateRange( '2026-09-01', '2026-09-01' ), false );

		$this->assertCount( 1, $rows );
		$this->assertSame( $link['code'], $rows[0]['code'] );
		$this->assertSame( 'ホテルA', $rows[0]['label'] );
		$this->assertSame( 'desktop', $rows[0]['device'] );
		$this->assertArrayNotHasKey( 'visitor_hash', $rows[0] );
	}
}
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EventRepositoryStatsTest"
```

Expected: FAIL — `Call to undefined method RLT\Data\EventRepository::summary()`

- [ ] **Step 3: 集計メソッドを実装する**

`src/Data/EventRepository.php` の `hasRecentView()` の**後ろ**に、以下をすべて追加する。`use` に `RLT\Support\DateRange;` と `RLT\Support\DeviceDetector;` を足すこと。

```php
	/**
	 * @return array{clicks:int, unique_clicks:int, views:int, unique_views:int, ctr:float}
	 */
	public function summary( DateRange $range, bool $includeBots = false ): array {
		$clicks = $this->db->get_row(
			$this->db->prepare(
				'SELECT COUNT(*) AS total, COUNT(DISTINCT visitor_hash) AS uniques
				 FROM ' . Installer::clicksTable() . '
				 WHERE clicked_at BETWEEN %s AND %s' . $this->botClause( $includeBots ),
				$range->startUtc(),
				$range->endUtc()
			),
			ARRAY_A
		);

		$views = $this->db->get_row(
			$this->db->prepare(
				'SELECT COUNT(*) AS total, COUNT(DISTINCT visitor_hash) AS uniques
				 FROM ' . Installer::viewsTable() . '
				 WHERE viewed_at BETWEEN %s AND %s' . $this->botClause( $includeBots ),
				$range->startUtc(),
				$range->endUtc()
			),
			ARRAY_A
		);

		$clickTotal = (int) ( $clicks['total'] ?? 0 );
		$viewTotal  = (int) ( $views['total'] ?? 0 );

		return array(
			'clicks'        => $clickTotal,
			'unique_clicks' => (int) ( $clicks['uniques'] ?? 0 ),
			'views'         => $viewTotal,
			'unique_views'  => (int) ( $views['uniques'] ?? 0 ),
			'ctr'           => self::ctr( $clickTotal, $viewTotal ),
		);
	}

	/**
	 * @return array<int, array{date:string, clicks:int, views:int}>
	 */
	public function daily( DateRange $range, bool $includeBots = false ): array {
		$offset = $range->offsetSeconds();

		$clicks = $this->dailyCounts( Installer::clicksTable(), 'clicked_at', $range, $includeBots, $offset );
		$views  = $this->dailyCounts( Installer::viewsTable(), 'viewed_at', $range, $includeBots, $offset );

		$out = array();
		foreach ( $range->days() as $day ) {
			$out[] = array(
				'date'   => $day,
				'clicks' => $clicks[ $day ] ?? 0,
				'views'  => $views[ $day ] ?? 0,
			);
		}

		return $out;
	}

	/**
	 * @return array<int, array{post_id:int, views:int, clicks:int, ctr:float}>
	 */
	public function byPost( DateRange $range, bool $includeBots, string $orderby, int $limit ): array {
		$clickRows = $this->db->get_results(
			$this->db->prepare(
				'SELECT post_id, COUNT(*) AS clicks
				 FROM ' . Installer::clicksTable() . '
				 WHERE clicked_at BETWEEN %s AND %s' . $this->botClause( $includeBots ) . '
				 GROUP BY post_id',
				$range->startUtc(),
				$range->endUtc()
			),
			ARRAY_A
		) ?: array();

		$viewRows = $this->db->get_results(
			$this->db->prepare(
				'SELECT post_id, COUNT(*) AS views
				 FROM ' . Installer::viewsTable() . '
				 WHERE viewed_at BETWEEN %s AND %s' . $this->botClause( $includeBots ) . '
				 GROUP BY post_id',
				$range->startUtc(),
				$range->endUtc()
			),
			ARRAY_A
		) ?: array();

		$merged = array();

		foreach ( $viewRows as $row ) {
			$merged[ (int) $row['post_id'] ] = array(
				'post_id' => (int) $row['post_id'],
				'views'   => (int) $row['views'],
				'clicks'  => 0,
			);
		}

		foreach ( $clickRows as $row ) {
			$postId = (int) $row['post_id'];

			if ( ! isset( $merged[ $postId ] ) ) {
				$merged[ $postId ] = array(
					'post_id' => $postId,
					'views'   => 0,
					'clicks'  => 0,
				);
			}

			$merged[ $postId ]['clicks'] = (int) $row['clicks'];
		}

		foreach ( $merged as &$row ) {
			$row['ctr'] = self::ctr( $row['clicks'], $row['views'] );
		}
		unset( $row );

		$rows = array_values( $merged );
		self::sortRows( $rows, $orderby );

		return array_slice( $rows, 0, max( 1, $limit ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function byLink( DateRange $range, bool $includeBots, ?int $postId, string $orderby, int $limit ): array {
		$links  = Installer::linksTable();
		$clicks = Installer::clicksTable();
		$views  = Installer::viewsTable();

		$botClicks = $includeBots ? '' : ' AND c.is_bot = 0';
		$botViews  = $includeBots ? '' : ' AND is_bot = 0';
		$where     = null === $postId ? '' : ' WHERE l.post_id = %d';

		$sql = "SELECT l.id AS link_id, l.code, l.label, l.post_id, l.target_url, l.status,
					COUNT(c.id) AS clicks,
					COUNT(DISTINCT c.visitor_hash) AS unique_clicks,
					MAX(c.clicked_at) AS last_click,
					COALESCE(v.views, 0) AS views
				FROM {$links} l
				LEFT JOIN {$clicks} c
					ON c.link_id = l.id AND c.clicked_at BETWEEN %s AND %s{$botClicks}
				LEFT JOIN (
					SELECT post_id, COUNT(*) AS views
					FROM {$views}
					WHERE viewed_at BETWEEN %s AND %s{$botViews}
					GROUP BY post_id
				) v ON v.post_id = l.post_id
				{$where}
				GROUP BY l.id";

		$params = array( $range->startUtc(), $range->endUtc(), $range->startUtc(), $range->endUtc() );

		if ( null !== $postId ) {
			$params[] = $postId;
		}

		$rows = $this->db->get_results( $this->db->prepare( $sql, $params ), ARRAY_A ) ?: array();

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'link_id'       => (int) $row['link_id'],
				'code'          => (string) $row['code'],
				'label'         => (string) $row['label'],
				'post_id'       => (int) $row['post_id'],
				'target_url'    => (string) $row['target_url'],
				'status'        => (int) $row['status'],
				'clicks'        => (int) $row['clicks'],
				'unique_clicks' => (int) $row['unique_clicks'],
				'views'         => (int) $row['views'],
				'ctr'           => self::ctr( (int) $row['clicks'], (int) $row['views'] ),
				'last_click'    => null === $row['last_click'] ? null : (string) $row['last_click'],
			);
		}

		self::sortRows( $out, $orderby );

		return array_slice( $out, 0, max( 1, $limit ) );
	}

	/**
	 * @return array{daily:array, referers:array, devices:array}
	 */
	public function linkDetail( int $linkId, DateRange $range, bool $includeBots ): array {
		$table  = Installer::clicksTable();
		$offset = $range->offsetSeconds();
		$bots   = $this->botClause( $includeBots );

		$dailyRows = $this->db->get_results(
			$this->db->prepare(
				"SELECT DATE(clicked_at + INTERVAL %d SECOND) AS day, COUNT(*) AS clicks
				 FROM {$table}
				 WHERE link_id = %d AND clicked_at BETWEEN %s AND %s{$bots}
				 GROUP BY day",
				$offset,
				$linkId,
				$range->startUtc(),
				$range->endUtc()
			),
			ARRAY_A
		) ?: array();

		$byDay = array();
		foreach ( $dailyRows as $row ) {
			$byDay[ (string) $row['day'] ] = (int) $row['clicks'];
		}

		$daily = array();
		foreach ( $range->days() as $day ) {
			$daily[] = array(
				'date'   => $day,
				'clicks' => $byDay[ $day ] ?? 0,
			);
		}

		$refererRows = $this->db->get_results(
			$this->db->prepare(
				"SELECT referer, COUNT(*) AS clicks
				 FROM {$table}
				 WHERE link_id = %d AND clicked_at BETWEEN %s AND %s{$bots}
				 GROUP BY referer
				 ORDER BY clicks DESC
				 LIMIT 20",
				$linkId,
				$range->startUtc(),
				$range->endUtc()
			),
			ARRAY_A
		) ?: array();

		$referers = array();
		foreach ( $refererRows as $row ) {
			$referers[] = array(
				'referer' => (string) $row['referer'],
				'clicks'  => (int) $row['clicks'],
			);
		}

		$deviceRows = $this->db->get_results(
			$this->db->prepare(
				"SELECT device, COUNT(*) AS clicks
				 FROM {$table}
				 WHERE link_id = %d AND clicked_at BETWEEN %s AND %s{$bots}
				 GROUP BY device
				 ORDER BY clicks DESC",
				$linkId,
				$range->startUtc(),
				$range->endUtc()
			),
			ARRAY_A
		) ?: array();

		$devices = array();
		foreach ( $deviceRows as $row ) {
			$devices[] = array(
				'device' => (int) $row['device'],
				'label'  => DeviceDetector::label( (int) $row['device'] ),
				'clicks' => (int) $row['clicks'],
			);
		}

		return array(
			'daily'    => $daily,
			'referers' => $referers,
			'devices'  => $devices,
		);
	}

	/**
	 * Flat click rows for CSV export. The visitor hash is deliberately omitted.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function clicksForExport( DateRange $range, bool $includeBots ): array {
		$clicks = Installer::clicksTable();
		$links  = Installer::linksTable();

		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT c.clicked_at, l.code, l.label, c.post_id, l.target_url, c.referer, c.device, c.is_bot
				 FROM ' . $clicks . ' c
				 LEFT JOIN ' . $links . ' l ON l.id = c.link_id
				 WHERE c.clicked_at BETWEEN %s AND %s' . ( $includeBots ? '' : ' AND c.is_bot = 0' ) . '
				 ORDER BY c.clicked_at ASC',
				$range->startUtc(),
				$range->endUtc()
			),
			ARRAY_A
		) ?: array();

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'clicked_at' => get_date_from_gmt( (string) $row['clicked_at'], 'c' ),
				'code'       => (string) $row['code'],
				'label'      => (string) $row['label'],
				'post_id'    => (int) $row['post_id'],
				'target_url' => (string) $row['target_url'],
				'referer'    => (string) $row['referer'],
				'device'     => DeviceDetector::label( (int) $row['device'] ),
				'is_bot'     => (int) $row['is_bot'],
			);
		}

		return $out;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function viewsForExport( DateRange $range, bool $includeBots ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT viewed_at, post_id, referer, device, is_bot
				 FROM ' . Installer::viewsTable() . '
				 WHERE viewed_at BETWEEN %s AND %s' . $this->botClause( $includeBots ) . '
				 ORDER BY viewed_at ASC',
				$range->startUtc(),
				$range->endUtc()
			),
			ARRAY_A
		) ?: array();

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'viewed_at' => get_date_from_gmt( (string) $row['viewed_at'], 'c' ),
				'post_id'   => (int) $row['post_id'],
				'referer'   => (string) $row['referer'],
				'device'    => DeviceDetector::label( (int) $row['device'] ),
				'is_bot'    => (int) $row['is_bot'],
			);
		}

		return $out;
	}

	/**
	 * Delete raw log rows older than the retention window.
	 *
	 * @param int $days 0 keeps everything.
	 * @return int Rows deleted across both tables.
	 */
	public function purgeOlderThan( int $days ): int {
		if ( $days <= 0 ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$deleted = (int) $this->db->query(
			$this->db->prepare( 'DELETE FROM ' . Installer::clicksTable() . ' WHERE clicked_at < %s', $cutoff )
		);

		$deleted += (int) $this->db->query(
			$this->db->prepare( 'DELETE FROM ' . Installer::viewsTable() . ' WHERE viewed_at < %s', $cutoff )
		);

		return $deleted;
	}

	/**
	 * @return array<string, int> Local date => count.
	 */
	private function dailyCounts( string $table, string $column, DateRange $range, bool $includeBots, int $offset ): array {
		$bots = $this->botClause( $includeBots );

		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT DATE({$column} + INTERVAL %d SECOND) AS day, COUNT(*) AS total
				 FROM {$table}
				 WHERE {$column} BETWEEN %s AND %s{$bots}
				 GROUP BY day",
				$offset,
				$range->startUtc(),
				$range->endUtc()
			),
			ARRAY_A
		) ?: array();

		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['day'] ] = (int) $row['total'];
		}

		return $out;
	}

	/**
	 * Bots are stored, not dropped, so every read has to decide whether to include them.
	 */
	private function botClause( bool $includeBots ): string {
		return $includeBots ? '' : ' AND is_bot = 0';
	}

	private static function ctr( int $clicks, int $views ): float {
		if ( $views <= 0 ) {
			return 0.0;
		}

		return round( $clicks / $views, 4 );
	}

	/**
	 * Sorting happens in PHP because the result sets are small and the merge in
	 * byPost() has no single SQL statement to order.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 */
	private static function sortRows( array &$rows, string $orderby ): void {
		$comparators = array(
			'clicks'        => static fn ( $a, $b ) => $b['clicks'] <=> $a['clicks'],
			'views'         => static fn ( $a, $b ) => ( $b['views'] ?? 0 ) <=> ( $a['views'] ?? 0 ),
			'unique_clicks' => static fn ( $a, $b ) => ( $b['unique_clicks'] ?? 0 ) <=> ( $a['unique_clicks'] ?? 0 ),
			'ctr'           => static fn ( $a, $b ) => $b['ctr'] <=> $a['ctr'],
			// CTR の低い順。改善余地のある記事を先頭に持ってくるための並び。
			'ctr_asc'       => static fn ( $a, $b ) => $a['ctr'] <=> $b['ctr'],
			'last_click'    => static fn ( $a, $b ) => (string) ( $b['last_click'] ?? '' ) <=> (string) ( $a['last_click'] ?? '' ),
		);

		usort( $rows, $comparators[ $orderby ] ?? $comparators['clicks'] );
	}
```

- [ ] **Step 4: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EventRepositoryStatsTest"
```

Expected: PASS — `OK (16 tests, ...)`

- [ ] **Step 5: コミット**

```bash
git add src/Data/EventRepository.php tests/Integration/Data/EventRepositoryStatsTest.php
git commit -m "feat: サマリー・日別・記事別・リンク別の集計クエリを追加"
```

---

## Task 16: ApiKeyManager（読み取り専用APIキー）

**Files:**
- Create: `src/Data/ApiKeyManager.php`
- Test: `tests/Integration/Data/ApiKeyManagerTest.php`

**Interfaces:**
- Consumes: なし
- Produces:
  - `RLT\Data\ApiKeyManager::OPTION` (string `'rlt_api_keys'`)
  - `RLT\Data\ApiKeyManager::HEADER` (string `'X-RLT-Key'`)
  - `create(string $label): array` → `['id','label','key','created_at']`。`key` は平文で、この一度しか返らない
  - `all(): array` → `[['id','label','created_at','last_used_at'], ...]`（平文キーもハッシュも含まない）
  - `verify(string $key): ?array`
  - `revoke(string $id): bool`
  - `deleteAll(): void`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Integration/Data/ApiKeyManagerTest.php`:

```php
<?php

namespace RLT\Tests\Integration\Data;

use RLT\Data\ApiKeyManager;
use WP_UnitTestCase;

final class ApiKeyManagerTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		ApiKeyManager::deleteAll();
	}

	public function test_create_returns_a_plaintext_key_once(): void {
		$created = ApiKeyManager::create( 'Looker Studio' );

		$this->assertStringStartsWith( 'rlt_', $created['key'] );
		$this->assertSame( 'Looker Studio', $created['label'] );

		// 一覧には平文キーもハッシュも出さない。
		$listed = ApiKeyManager::all()[0];
		$this->assertArrayNotHasKey( 'key', $listed );
		$this->assertArrayNotHasKey( 'hash', $listed );
	}

	public function test_verify_accepts_a_created_key(): void {
		$created = ApiKeyManager::create( 'BI' );

		$record = ApiKeyManager::verify( $created['key'] );

		$this->assertIsArray( $record );
		$this->assertSame( $created['id'], $record['id'] );
	}

	public function test_verify_rejects_an_unknown_key(): void {
		ApiKeyManager::create( 'BI' );

		$this->assertNull( ApiKeyManager::verify( 'rlt_deadbeef' ) );
	}

	public function test_verify_rejects_an_empty_key(): void {
		$this->assertNull( ApiKeyManager::verify( '' ) );
	}

	public function test_plaintext_key_is_never_stored(): void {
		$created = ApiKeyManager::create( 'BI' );

		$raw = wp_json_encode( get_option( ApiKeyManager::OPTION ) );

		$this->assertStringNotContainsString( $created['key'], (string) $raw );
	}

	public function test_verify_records_last_used(): void {
		$created = ApiKeyManager::create( 'BI' );

		$this->assertNull( ApiKeyManager::all()[0]['last_used_at'] );

		ApiKeyManager::verify( $created['key'] );

		$this->assertNotNull( ApiKeyManager::all()[0]['last_used_at'] );
	}

	public function test_revoke_removes_the_key(): void {
		$created = ApiKeyManager::create( 'BI' );

		$this->assertTrue( ApiKeyManager::revoke( $created['id'] ) );
		$this->assertSame( array(), ApiKeyManager::all() );
		$this->assertNull( ApiKeyManager::verify( $created['key'] ) );
	}

	public function test_revoke_returns_false_for_an_unknown_id(): void {
		$this->assertFalse( ApiKeyManager::revoke( 'nope' ) );
	}

	public function test_keys_are_unique(): void {
		$a = ApiKeyManager::create( 'A' );
		$b = ApiKeyManager::create( 'B' );

		$this->assertNotSame( $a['key'], $b['key'] );
		$this->assertNotSame( $a['id'], $b['id'] );
		$this->assertCount( 2, ApiKeyManager::all() );
	}

	public function test_label_is_sanitised(): void {
		$created = ApiKeyManager::create( '<script>alert(1)</script>BI' );

		$this->assertStringNotContainsString( '<script>', $created['label'] );
	}
}
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ApiKeyManagerTest"
```

Expected: FAIL — `Class "RLT\Data\ApiKeyManager" not found`

- [ ] **Step 3: 実装する**

`src/Data/ApiKeyManager.php`:

```php
<?php

declare(strict_types=1);

namespace RLT\Data;

/**
 * Read-only API keys.
 *
 * These exist so statistics can be handed to Looker Studio, a spreadsheet or an
 * LLM without giving out a WordPress account. Only the hash is stored, so a
 * leaked options table does not leak working credentials.
 */
final class ApiKeyManager {

	public const OPTION = 'rlt_api_keys';
	public const HEADER = 'X-RLT-Key';

	private const PREFIX = 'rlt_';

	/**
	 * @return array{id:string, label:string, key:string, created_at:string}
	 */
	public static function create( string $label ): array {
		$key   = self::PREFIX . bin2hex( random_bytes( 24 ) );
		$id    = bin2hex( random_bytes( 4 ) );
		$now   = current_time( 'mysql', true );
		$label = sanitize_text_field( $label );

		$keys       = self::stored();
		$keys[ $id ] = array(
			'id'           => $id,
			'label'        => $label,
			'hash'         => hash( 'sha256', $key ),
			'created_at'   => $now,
			'last_used_at' => null,
		);

		update_option( self::OPTION, $keys, false );

		return array(
			'id'         => $id,
			'label'      => $label,
			'key'        => $key,
			'created_at' => $now,
		);
	}

	/**
	 * Keys as safe to display: no plaintext, no hash.
	 *
	 * @return array<int, array{id:string, label:string, created_at:string, last_used_at:?string}>
	 */
	public static function all(): array {
		$out = array();

		foreach ( self::stored() as $record ) {
			$out[] = array(
				'id'           => (string) $record['id'],
				'label'        => (string) $record['label'],
				'created_at'   => (string) $record['created_at'],
				'last_used_at' => $record['last_used_at'] ?? null,
			);
		}

		return $out;
	}

	/**
	 * @return array{id:string, label:string}|null
	 */
	public static function verify( string $key ): ?array {
		if ( '' === $key ) {
			return null;
		}

		$hash = hash( 'sha256', $key );
		$keys = self::stored();

		foreach ( $keys as $id => $record ) {
			if ( ! hash_equals( (string) $record['hash'], $hash ) ) {
				continue;
			}

			$keys[ $id ]['last_used_at'] = current_time( 'mysql', true );
			update_option( self::OPTION, $keys, false );

			return array(
				'id'    => (string) $record['id'],
				'label' => (string) $record['label'],
			);
		}

		return null;
	}

	public static function revoke( string $id ): bool {
		$keys = self::stored();

		if ( ! isset( $keys[ $id ] ) ) {
			return false;
		}

		unset( $keys[ $id ] );
		update_option( self::OPTION, $keys, false );

		return true;
	}

	public static function deleteAll(): void {
		delete_option( self::OPTION );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function stored(): array {
		$keys = get_option( self::OPTION, array() );

		return is_array( $keys ) ? $keys : array();
	}
}
```

- [ ] **Step 4: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ApiKeyManagerTest"
```

Expected: PASS — `OK (10 tests, ...)`

- [ ] **Step 5: コミット**

```bash
git add src/Data/ApiKeyManager.php tests/Integration/Data/ApiKeyManagerTest.php
git commit -m "feat: 読み取り専用APIキーの発行・検証・失効を追加"
```

---

## Task 17: StatsController（読み取りAPI）

**Files:**
- Create: `src/Api/StatsController.php`
- Modify: `src/Plugin.php`
- Test: `tests/Integration/Api/StatsControllerTest.php`

**Interfaces:**
- Consumes: `EventRepository`, `LinkRepository`, `ApiKeyManager`, `DateRange`, `Installer::CAPABILITY`
- Produces:
  - `RLT\Api\StatsController::REST_NAMESPACE` (string `'rlt/v1'`)
  - `register(): void` / `registerRoutes(): void`
  - `readPermission(\WP_REST_Request $request): bool|\WP_Error`
  - `writePermission(): bool|\WP_Error`
  - `getSummary/getDaily/getPosts/getLinks/getLink/getReport/updateLink(\WP_REST_Request $request): \WP_REST_Response|\WP_Error`

ルート一覧:

| メソッド | パス | 権限 |
|---|---|---|
| GET | `/rlt/v1/stats/summary` | 読み取り |
| GET | `/rlt/v1/stats/daily` | 読み取り |
| GET | `/rlt/v1/stats/posts` | 読み取り |
| GET | `/rlt/v1/stats/links` | 読み取り |
| GET | `/rlt/v1/stats/links/(?P<code>[a-z0-9]{4,16})` | 読み取り |
| GET | `/rlt/v1/stats/report` | 読み取り |
| GET | `/rlt/v1/links` | 読み取り |
| PATCH | `/rlt/v1/links/(?P<code>[a-z0-9]{4,16})` | **書き込み（APIキー不可）** |

共通クエリパラメータ: `from`, `to`（`Y-m-d`）, `include_bots`（bool）, `limit`（1〜500、既定50）, `orderby`, `post_id`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Integration/Api/StatsControllerTest.php`:

```php
<?php

namespace RLT\Tests\Integration\Api;

use RLT\Api\StatsController;
use RLT\Data\ApiKeyManager;
use RLT\Data\LinkRepository;
use RLT\Installer;
use WP_REST_Request;
use WP_UnitTestCase;

final class StatsControllerTest extends WP_UnitTestCase {

	private LinkRepository $links;
	private array $link;

	protected function setUp(): void {
		parent::setUp();
		update_option( 'timezone_string', 'Asia/Tokyo' );
		ApiKeyManager::deleteAll();

		$this->links = new LinkRepository();
		$this->link  = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 42, 'ホテルA' );

		( new StatsController() )->registerRoutes();
		do_action( 'rest_api_init' );
	}

	private function asAdmin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function get( string $route, array $params = array() ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	public function test_all_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();

		foreach ( array( '/rlt/v1/stats/summary', '/rlt/v1/stats/daily', '/rlt/v1/stats/posts', '/rlt/v1/stats/links', '/rlt/v1/stats/report', '/rlt/v1/links' ) as $route ) {
			$this->assertArrayHasKey( $route, $routes, "Route {$route} is missing." );
		}
	}

	public function test_anonymous_requests_are_rejected(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->get( '/rlt/v1/stats/summary' )->get_status() );
	}

	public function test_a_subscriber_is_rejected(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( 403, $this->get( '/rlt/v1/stats/summary' )->get_status() );
	}

	public function test_an_administrator_is_allowed(): void {
		$this->asAdmin();

		$this->assertSame( 200, $this->get( '/rlt/v1/stats/summary' )->get_status() );
	}

	public function test_a_read_only_api_key_is_allowed(): void {
		wp_set_current_user( 0 );
		$key = ApiKeyManager::create( 'BI' )['key'];

		$request = new WP_REST_Request( 'GET', '/rlt/v1/stats/summary' );
		$request->set_header( ApiKeyManager::HEADER, $key );

		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_summary_response_declares_its_range_and_timezone(): void {
		$this->asAdmin();

		$data = $this->get( '/rlt/v1/stats/summary', array( 'from' => '2026-09-01', 'to' => '2026-09-07' ) )->get_data();

		$this->assertSame( '2026-09-01', $data['range']['from'] );
		$this->assertSame( '2026-09-07', $data['range']['to'] );
		$this->assertSame( 'Asia/Tokyo', $data['timezone'] );
		$this->assertArrayHasKey( 'clicks', $data['totals'] );
		$this->assertArrayHasKey( 'ctr', $data['totals'] );
	}

	public function test_summary_includes_the_previous_period_for_comparison(): void {
		$this->asAdmin();

		$data = $this->get( '/rlt/v1/stats/summary' )->get_data();

		$this->assertArrayHasKey( 'previous', $data );
		$this->assertArrayHasKey( 'clicks', $data['previous'] );
	}

	public function test_daily_returns_one_entry_per_day(): void {
		$this->asAdmin();

		$data = $this->get( '/rlt/v1/stats/daily', array( 'from' => '2026-09-01', 'to' => '2026-09-03' ) )->get_data();

		$this->assertCount( 3, $data['days'] );
	}

	public function test_links_endpoint_returns_the_short_url(): void {
		$this->asAdmin();

		$data = $this->get( '/rlt/v1/stats/links' )->get_data();

		$this->assertSame( $this->link['code'], $data['links'][0]['code'] );
		$this->assertStringContainsString( '/go/' . $this->link['code'], $data['links'][0]['short_url'] );
	}

	public function test_single_link_endpoint_returns_breakdowns(): void {
		$this->asAdmin();

		$data = $this->get( '/rlt/v1/stats/links/' . $this->link['code'] )->get_data();

		$this->assertSame( $this->link['code'], $data['link']['code'] );
		$this->assertArrayHasKey( 'daily', $data );
		$this->assertArrayHasKey( 'referers', $data );
		$this->assertArrayHasKey( 'devices', $data );
	}

	public function test_single_link_endpoint_404s_for_an_unknown_code(): void {
		$this->asAdmin();

		$this->assertSame( 404, $this->get( '/rlt/v1/stats/links/zzzzzz' )->get_status() );
	}

	public function test_posts_endpoint_resolves_post_titles(): void {
		$this->asAdmin();

		$postId = self::factory()->post->create( array( 'post_title' => 'テスト記事', 'post_status' => 'publish' ) );
		$this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/b', $postId, 'B' );

		global $wpdb;
		$wpdb->insert(
			Installer::viewsTable(),
			array(
				'post_id'      => $postId,
				'viewed_at'    => gmdate( 'Y-m-d H:i:s' ),
				'visitor_hash' => str_pad( 'v', 64, '0' ),
				'referer'      => '',
				'device'       => 1,
				'is_bot'       => 0,
			)
		);

		$data = $this->get( '/rlt/v1/stats/posts' )->get_data();
		$row  = array_column( $data['posts'], null, 'post_id' )[ $postId ];

		$this->assertSame( 'テスト記事', $row['title'] );
		$this->assertArrayHasKey( 'permalink', $row );
	}

	public function test_report_endpoint_returns_notes(): void {
		$this->asAdmin();

		$data = $this->get( '/rlt/v1/stats/report' )->get_data();

		$this->assertArrayHasKey( 'totals', $data );
		$this->assertArrayHasKey( 'top_links', $data );
		$this->assertArrayHasKey( 'top_posts', $data );
		$this->assertIsArray( $data['notes'] );
	}

	public function test_patch_updates_the_target_url(): void {
		$this->asAdmin();

		$request = new WP_REST_Request( 'PATCH', '/rlt/v1/links/' . $this->link['code'] );
		$request->set_param( 'target_url', 'https://hb.afl.rakuten.co.jp/hgc/new' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'https://hb.afl.rakuten.co.jp/hgc/new', $this->links->findById( $this->link['id'] )['target_url'] );
	}

	public function test_patch_is_refused_for_a_read_only_api_key(): void {
		wp_set_current_user( 0 );
		$key = ApiKeyManager::create( 'BI' )['key'];

		$request = new WP_REST_Request( 'PATCH', '/rlt/v1/links/' . $this->link['code'] );
		$request->set_header( ApiKeyManager::HEADER, $key );
		$request->set_param( 'label', '乗っ取り' );

		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertSame( 'ホテルA', $this->links->findById( $this->link['id'] )['label'] );
	}

	public function test_patch_rejects_a_non_http_target_url(): void {
		$this->asAdmin();

		$request = new WP_REST_Request( 'PATCH', '/rlt/v1/links/' . $this->link['code'] );
		$request->set_param( 'target_url', 'javascript:alert(1)' );

		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_limit_is_clamped(): void {
		$this->asAdmin();

		$response = $this->get( '/rlt/v1/stats/links', array( 'limit' => 99999 ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_bad_dates_fall_back_to_the_default_window(): void {
		$this->asAdmin();

		$data = $this->get( '/rlt/v1/stats/daily', array( 'from' => 'garbage', 'to' => 'worse' ) )->get_data();

		$this->assertCount( 28, $data['days'] );
	}
}
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter StatsControllerTest"
```

Expected: FAIL — `Class "RLT\Api\StatsController" not found`

- [ ] **Step 3: 実装する**

`src/Api/StatsController.php`:

```php
<?php

declare(strict_types=1);

namespace RLT\Api;

use RLT\Data\ApiKeyManager;
use RLT\Data\EventRepository;
use RLT\Data\LinkRepository;
use RLT\Installer;
use RLT\Settings;
use RLT\Support\DateRange;

/**
 * Read API for the collected statistics.
 *
 * Two ways in: a WordPress user holding the rlt_view_stats capability, or a
 * read-only API key. The key can never write, so it is safe to paste into a
 * dashboard tool or hand to an LLM.
 */
final class StatsController {

	public const REST_NAMESPACE = 'rlt/v1';

	private const DEFAULT_DAYS  = 28;
	private const DEFAULT_LIMIT = 50;
	private const MAX_LIMIT     = 500;

	private LinkRepository $links;
	private EventRepository $events;

	public function __construct( ?LinkRepository $links = null, ?EventRepository $events = null ) {
		$this->links  = $links ?? new LinkRepository();
		$this->events = $events ?? new EventRepository();
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	public function registerRoutes(): void {
		$read = array(
			'methods'             => 'GET',
			'permission_callback' => array( $this, 'readPermission' ),
			'args'                => $this->commonArgs(),
		);

		register_rest_route( self::REST_NAMESPACE, '/stats/summary', array_merge( $read, array( 'callback' => array( $this, 'getSummary' ) ) ) );
		register_rest_route( self::REST_NAMESPACE, '/stats/daily', array_merge( $read, array( 'callback' => array( $this, 'getDaily' ) ) ) );
		register_rest_route( self::REST_NAMESPACE, '/stats/posts', array_merge( $read, array( 'callback' => array( $this, 'getPosts' ) ) ) );
		register_rest_route( self::REST_NAMESPACE, '/stats/links', array_merge( $read, array( 'callback' => array( $this, 'getLinks' ) ) ) );
		register_rest_route( self::REST_NAMESPACE, '/stats/report', array_merge( $read, array( 'callback' => array( $this, 'getReport' ) ) ) );
		register_rest_route( self::REST_NAMESPACE, '/links', array_merge( $read, array( 'callback' => array( $this, 'getLinks' ) ) ) );

		register_rest_route(
			self::REST_NAMESPACE,
			'/stats/links/(?P<code>[a-z0-9]{4,16})',
			array_merge( $read, array( 'callback' => array( $this, 'getLink' ) ) )
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/links/(?P<code>[a-z0-9]{4,16})',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'updateLink' ),
				'permission_callback' => array( $this, 'writePermission' ),
				'args'                => array(
					'target_url' => array( 'type' => 'string' ),
					'label'      => array( 'type' => 'string' ),
					'status'     => array( 'type' => 'integer' ),
				),
			)
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function commonArgs(): array {
		return array(
			'from'         => array( 'type' => 'string' ),
			'to'           => array( 'type' => 'string' ),
			'include_bots' => array( 'type' => 'boolean', 'default' => false ),
			'limit'        => array( 'type' => 'integer', 'default' => self::DEFAULT_LIMIT ),
			'orderby'      => array( 'type' => 'string', 'default' => 'clicks' ),
			'post_id'      => array( 'type' => 'integer' ),
		);
	}

	public function readPermission( \WP_REST_Request $request ): bool|\WP_Error {
		if ( current_user_can( Installer::CAPABILITY ) ) {
			return true;
		}

		$key = (string) $request->get_header( ApiKeyManager::HEADER );

		if ( '' !== $key && null !== ApiKeyManager::verify( $key ) ) {
			return true;
		}

		return new \WP_Error(
			'rlt_forbidden',
			__( 'この統計を参照する権限がありません。', 'rakuten-link-tracker' ),
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
	}

	/**
	 * Writes require a real WordPress user. A read-only key must never change data.
	 */
	public function writePermission(): bool|\WP_Error {
		if ( current_user_can( Installer::CAPABILITY ) ) {
			return true;
		}

		return new \WP_Error(
			'rlt_forbidden',
			__( 'リンクを変更する権限がありません。', 'rakuten-link-tracker' ),
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
	}

	public function getSummary( \WP_REST_Request $request ): \WP_REST_Response {
		$range = $this->range( $request );
		$bots  = (bool) $request->get_param( 'include_bots' );

		return $this->respond(
			$range,
			array(
				'totals'   => $this->events->summary( $range, $bots ),
				'previous' => $this->events->summary( $range->previous(), $bots ),
			)
		);
	}

	public function getDaily( \WP_REST_Request $request ): \WP_REST_Response {
		$range = $this->range( $request );

		return $this->respond(
			$range,
			array( 'days' => $this->events->daily( $range, (bool) $request->get_param( 'include_bots' ) ) )
		);
	}

	public function getPosts( \WP_REST_Request $request ): \WP_REST_Response {
		$range = $this->range( $request );

		$rows = $this->events->byPost(
			$range,
			(bool) $request->get_param( 'include_bots' ),
			(string) $request->get_param( 'orderby' ),
			$this->limit( $request )
		);

		foreach ( $rows as &$row ) {
			$row['title']     = html_entity_decode( (string) get_the_title( $row['post_id'] ), ENT_QUOTES, 'UTF-8' );
			$row['permalink'] = (string) get_permalink( $row['post_id'] );
		}
		unset( $row );

		return $this->respond( $range, array( 'posts' => $rows ) );
	}

	public function getLinks( \WP_REST_Request $request ): \WP_REST_Response {
		$range  = $this->range( $request );
		$postId = $request->get_param( 'post_id' );

		$rows = $this->events->byLink(
			$range,
			(bool) $request->get_param( 'include_bots' ),
			null === $postId ? null : (int) $postId,
			(string) $request->get_param( 'orderby' ),
			$this->limit( $request )
		);

		foreach ( $rows as &$row ) {
			$row['short_url']  = Settings::shortUrl( $row['code'] );
			$row['post_title'] = html_entity_decode( (string) get_the_title( $row['post_id'] ), ENT_QUOTES, 'UTF-8' );
		}
		unset( $row );

		return $this->respond( $range, array( 'links' => $rows ) );
	}

	public function getLink( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$link = $this->links->findByCode( (string) $request->get_param( 'code' ) );

		if ( null === $link ) {
			return new \WP_Error( 'rlt_not_found', __( 'リンクが見つかりません。', 'rakuten-link-tracker' ), array( 'status' => 404 ) );
		}

		$range  = $this->range( $request );
		$detail = $this->events->linkDetail( $link['id'], $range, (bool) $request->get_param( 'include_bots' ) );

		$link['short_url']  = Settings::shortUrl( $link['code'] );
		$link['post_title'] = html_entity_decode( (string) get_the_title( $link['post_id'] ), ENT_QUOTES, 'UTF-8' );

		return $this->respond( $range, array_merge( array( 'link' => $link ), $detail ) );
	}

	/**
	 * A single response shaped for handing to an LLM: the numbers plus plain
	 * sentences describing what stands out.
	 */
	public function getReport( \WP_REST_Request $request ): \WP_REST_Response {
		$range = $this->range( $request );
		$bots  = (bool) $request->get_param( 'include_bots' );

		$totals   = $this->events->summary( $range, $bots );
		$previous = $this->events->summary( $range->previous(), $bots );
		$topLinks = $this->events->byLink( $range, $bots, null, 'clicks', 10 );
		$topPosts = $this->events->byPost( $range, $bots, 'views', 10 );
		$lowCtr   = $this->events->byPost( $range, $bots, 'ctr_asc', 10 );

		$notes = array();

		$notes[] = sprintf(
			/* translators: 1: page views, 2: clicks, 3: CTR percentage */
			__( '期間中のPVは %1$d、クリックは %2$d、CTRは %3$s%% です。', 'rakuten-link-tracker' ),
			$totals['views'],
			$totals['clicks'],
			number_format( $totals['ctr'] * 100, 2 )
		);

		if ( $previous['clicks'] > 0 ) {
			$change  = ( ( $totals['clicks'] - $previous['clicks'] ) / $previous['clicks'] ) * 100;
			$notes[] = sprintf(
				/* translators: %s: percentage change */
				__( 'クリック数は前期間比 %s%% です。', 'rakuten-link-tracker' ),
				number_format( $change, 1 )
			);
		}

		$dead = array_values(
			array_filter(
				$topLinks,
				static fn ( array $row ): bool => 0 === $row['clicks'] && $row['views'] > 0
			)
		);

		if ( array() !== $dead ) {
			$notes[] = sprintf(
				/* translators: %d: number of links */
				__( 'PVはあるのにクリックが0のリンクが %d 件あります。リンクの配置か訴求文を見直す候補です。', 'rakuten-link-tracker' ),
				count( $dead )
			);
		}

		foreach ( $lowCtr as $row ) {
			if ( $row['views'] >= 20 && $row['ctr'] < ( $totals['ctr'] / 2 ) ) {
				$notes[] = sprintf(
					/* translators: 1: post title, 2: page views, 3: CTR percentage */
					__( '「%1$s」はPV %2$d に対してCTR %3$s%% と平均を大きく下回っています。', 'rakuten-link-tracker' ),
					html_entity_decode( (string) get_the_title( $row['post_id'] ), ENT_QUOTES, 'UTF-8' ),
					$row['views'],
					number_format( $row['ctr'] * 100, 2 )
				);
			}
		}

		return $this->respond(
			$range,
			array(
				'totals'    => $totals,
				'previous'  => $previous,
				'top_links' => $topLinks,
				'top_posts' => $topPosts,
				'low_ctr_posts' => $lowCtr,
				'notes'     => $notes,
			)
		);
	}

	public function updateLink( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$link = $this->links->findByCode( (string) $request->get_param( 'code' ) );

		if ( null === $link ) {
			return new \WP_Error( 'rlt_not_found', __( 'リンクが見つかりません。', 'rakuten-link-tracker' ), array( 'status' => 404 ) );
		}

		$fields = array();

		if ( null !== $request->get_param( 'target_url' ) ) {
			$url = esc_url_raw( (string) $request->get_param( 'target_url' ), array( 'http', 'https' ) );

			if ( '' === $url ) {
				return new \WP_Error(
					'rlt_invalid_url',
					__( '遷移先URLは http または https で始まる必要があります。', 'rakuten-link-tracker' ),
					array( 'status' => 400 )
				);
			}

			$fields['target_url'] = $url;
		}

		if ( null !== $request->get_param( 'label' ) ) {
			$fields['label'] = sanitize_text_field( (string) $request->get_param( 'label' ) );
		}

		if ( null !== $request->get_param( 'status' ) ) {
			$fields['status'] = $request->get_param( 'status' ) ? 1 : 0;
		}

		if ( array() === $fields ) {
			return new \WP_Error( 'rlt_nothing_to_update', __( '変更する項目がありません。', 'rakuten-link-tracker' ), array( 'status' => 400 ) );
		}

		$this->links->update( $link['id'], $fields );

		return new \WP_REST_Response( array( 'link' => $this->links->findById( $link['id'] ) ), 200 );
	}

	private function range( \WP_REST_Request $request ): DateRange {
		return DateRange::fromRequest(
			$request->get_param( 'from' ),
			$request->get_param( 'to' ),
			self::DEFAULT_DAYS
		);
	}

	private function limit( \WP_REST_Request $request ): int {
		return min( self::MAX_LIMIT, max( 1, (int) $request->get_param( 'limit' ) ) );
	}

	/**
	 * Every response states the window and timezone it was computed in, so a
	 * consumer never has to guess.
	 *
	 * @param array<string, mixed> $payload
	 */
	private function respond( DateRange $range, array $payload ): \WP_REST_Response {
		return new \WP_REST_Response(
			array_merge(
				array(
					'range'    => array(
						'from' => $range->fromDate(),
						'to'   => $range->toDate(),
						'days' => $range->dayCount(),
					),
					'timezone' => wp_timezone_string(),
				),
				$payload
			),
			200
		);
	}
}
```

- [ ] **Step 4: `Plugin::boot()` に繋ぐ**

```php
		( new \RLT\Api\StatsController() )->register();
```

- [ ] **Step 5: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter StatsControllerTest"
```

Expected: PASS — `OK (18 tests, ...)`

- [ ] **Step 6: コミット**

```bash
git add src/Api/StatsController.php src/Plugin.php tests/Integration/Api/StatsControllerTest.php
git commit -m "feat: 統計の読み取りREST APIとAPIキー認証を追加"
```

---

## Task 18: ExportController（CSVエクスポート）

**Files:**
- Create: `src/Api/ExportController.php`
- Modify: `src/Plugin.php`
- Test: `tests/Integration/Api/ExportControllerTest.php`

**Interfaces:**
- Consumes: `EventRepository`, `DateRange`, `StatsController::REST_NAMESPACE`, `StatsController::readPermission()`
- Produces:
  - `RLT\Api\ExportController::register(): void` / `registerRoutes(): void`
  - `exportClicks(\WP_REST_Request $request): \WP_REST_Response`
  - `exportViews(\WP_REST_Request $request): \WP_REST_Response`
  - `toCsv(array $rows, array $headers): string`
  - `serve(bool $served, mixed $result, \WP_REST_Request $request, \WP_REST_Server $server): bool` — `$result` は `WP_REST_Response` とは限らないため型宣言を付けない

- [ ] **Step 1: 失敗するテストを書く**

`tests/Integration/Api/ExportControllerTest.php`:

```php
<?php

namespace RLT\Tests\Integration\Api;

use RLT\Api\ExportController;
use RLT\Data\LinkRepository;
use RLT\Installer;
use WP_REST_Request;
use WP_UnitTestCase;

final class ExportControllerTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		update_option( 'timezone_string', 'Asia/Tokyo' );

		$links = new LinkRepository();
		$link  = $links->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 42, 'ホテル,カンマ入り' );

		global $wpdb;
		$wpdb->insert(
			Installer::clicksTable(),
			array(
				'link_id'      => $link['id'],
				'post_id'      => 42,
				'clicked_at'   => gmdate( 'Y-m-d H:i:s' ),
				'visitor_hash' => str_pad( 'v', 64, '0' ),
				'referer'      => 'https://www.google.com/',
				'device'       => 2,
				'is_bot'       => 0,
			)
		);

		( new ExportController() )->registerRoutes();
		do_action( 'rest_api_init' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function dispatch( string $route ): \WP_REST_Response {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', $route ) );
	}

	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/rlt/v1/export/clicks', $routes );
		$this->assertArrayHasKey( '/rlt/v1/export/views', $routes );
	}

	public function test_clicks_export_starts_with_a_header_row(): void {
		$csv = $this->dispatch( '/rlt/v1/export/clicks' )->get_data();

		$this->assertStringStartsWith( 'clicked_at,code,label,post_id,target_url,referer,device,is_bot', $csv );
	}

	public function test_clicks_export_contains_the_row(): void {
		$csv = $this->dispatch( '/rlt/v1/export/clicks' )->get_data();

		$this->assertStringContainsString( 'https://www.google.com/', $csv );
		$this->assertStringContainsString( 'mobile', $csv );
	}

	public function test_values_containing_commas_are_quoted(): void {
		$csv = $this->dispatch( '/rlt/v1/export/clicks' )->get_data();

		$this->assertStringContainsString( '"ホテル,カンマ入り"', $csv );
	}

	public function test_export_never_includes_the_visitor_hash(): void {
		$csv = $this->dispatch( '/rlt/v1/export/clicks' )->get_data();

		$this->assertStringNotContainsString( 'visitor_hash', $csv );
		$this->assertStringNotContainsString( str_pad( 'v', 64, '0' ), $csv );
	}

	public function test_response_declares_csv_content_type_and_a_filename(): void {
		$headers = $this->dispatch( '/rlt/v1/export/clicks' )->get_headers();

		$this->assertSame( 'text/csv; charset=utf-8', $headers['Content-Type'] );
		$this->assertStringContainsString( 'attachment;', $headers['Content-Disposition'] );
		$this->assertStringContainsString( '.csv', $headers['Content-Disposition'] );
	}

	public function test_views_export_has_its_own_header_row(): void {
		$csv = $this->dispatch( '/rlt/v1/export/views' )->get_data();

		$this->assertStringStartsWith( 'viewed_at,post_id,referer,device,is_bot', $csv );
	}

	public function test_anonymous_export_is_rejected(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->dispatch( '/rlt/v1/export/clicks' )->get_status() );
	}
}
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ExportControllerTest"
```

Expected: FAIL — `Class "RLT\Api\ExportController" not found`

- [ ] **Step 3: 実装する**

`src/Api/ExportController.php`:

```php
<?php

declare(strict_types=1);

namespace RLT\Api;

use RLT\Data\EventRepository;
use RLT\Support\DateRange;

/**
 * Serves the raw logs as CSV.
 *
 * The REST server would normally JSON-encode the response, so a marker header
 * tells serve() to emit the body verbatim instead.
 */
final class ExportController {

	private const MARKER = 'X-RLT-Raw-Csv';

	private const CLICK_HEADERS = array( 'clicked_at', 'code', 'label', 'post_id', 'target_url', 'referer', 'device', 'is_bot' );
	private const VIEW_HEADERS  = array( 'viewed_at', 'post_id', 'referer', 'device', 'is_bot' );

	private EventRepository $events;
	private StatsController $stats;

	public function __construct( ?EventRepository $events = null ) {
		$this->events = $events ?? new EventRepository();
		$this->stats  = new StatsController();
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve' ), 10, 4 );
	}

	public function registerRoutes(): void {
		$args = array(
			'from'         => array( 'type' => 'string' ),
			'to'           => array( 'type' => 'string' ),
			'include_bots' => array( 'type' => 'boolean', 'default' => false ),
		);

		register_rest_route(
			StatsController::REST_NAMESPACE,
			'/export/clicks',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'exportClicks' ),
				'permission_callback' => array( $this->stats, 'readPermission' ),
				'args'                => $args,
			)
		);

		register_rest_route(
			StatsController::REST_NAMESPACE,
			'/export/views',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'exportViews' ),
				'permission_callback' => array( $this->stats, 'readPermission' ),
				'args'                => $args,
			)
		);
	}

	public function exportClicks( \WP_REST_Request $request ): \WP_REST_Response {
		$range = DateRange::fromRequest( $request->get_param( 'from' ), $request->get_param( 'to' ), 28 );
		$rows  = $this->events->clicksForExport( $range, (bool) $request->get_param( 'include_bots' ) );

		return $this->csvResponse( $this->toCsv( $rows, self::CLICK_HEADERS ), 'clicks', $range );
	}

	public function exportViews( \WP_REST_Request $request ): \WP_REST_Response {
		$range = DateRange::fromRequest( $request->get_param( 'from' ), $request->get_param( 'to' ), 28 );
		$rows  = $this->events->viewsForExport( $range, (bool) $request->get_param( 'include_bots' ) );

		return $this->csvResponse( $this->toCsv( $rows, self::VIEW_HEADERS ), 'views', $range );
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @param string[]                         $headers
	 */
	public function toCsv( array $rows, array $headers ): string {
		$handle = fopen( 'php://temp', 'r+' );

		fputcsv( $handle, $headers );

		foreach ( $rows as $row ) {
			$line = array();
			foreach ( $headers as $header ) {
				$line[] = $row[ $header ] ?? '';
			}
			fputcsv( $handle, $line );
		}

		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle );

		return $csv;
	}

	/**
	 * Emit the CSV body untouched instead of letting the REST server encode it.
	 *
	 * @param \WP_REST_Response $result
	 */
	public function serve( bool $served, $result, \WP_REST_Request $request, \WP_REST_Server $server ): bool {
		if ( $served || ! $result instanceof \WP_REST_Response ) {
			return $served;
		}

		$headers = $result->get_headers();

		if ( empty( $headers[ self::MARKER ] ) ) {
			return $served;
		}

		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput

		return true;
	}

	private function csvResponse( string $csv, string $kind, DateRange $range ): \WP_REST_Response {
		$filename = sprintf( 'rlt-%s-%s_%s.csv', $kind, $range->fromDate(), $range->toDate() );

		$response = new \WP_REST_Response( $csv, 200 );
		$response->set_headers(
			array(
				'Content-Type'        => 'text/csv; charset=utf-8',
				'Content-Disposition' => 'attachment; filename="' . $filename . '"',
				self::MARKER          => '1',
			)
		);

		return $response;
	}
}
```

- [ ] **Step 4: `Plugin::boot()` に繋ぐ**

```php
		( new \RLT\Api\ExportController() )->register();
```

- [ ] **Step 5: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ExportControllerTest"
```

Expected: PASS — `OK (8 tests, ...)`

- [ ] **Step 6: コミット**

```bash
git add src/Api/ExportController.php src/Plugin.php tests/Integration/Api/ExportControllerTest.php
git commit -m "feat: クリック・PV生ログのCSVエクスポートを追加"
```

---

## Task 19: Cron（ログ削除とソルト更新）

**Files:**
- Create: `src/Cron.php`
- Modify: `src/Installer.php`（有効化時にスケジュール）
- Modify: `rakuten-link-tracker.php`（無効化時に解除）
- Modify: `src/Plugin.php`
- Test: `tests/Integration/CronTest.php`

**Interfaces:**
- Consumes: `EventRepository::purgeOlderThan()`, `Settings::rotateSalt()`
- Produces:
  - `RLT\Cron::HOOK` (string `'rlt_daily_maintenance'`)
  - `RLT\Cron::register(): void`
  - `RLT\Cron::schedule(): void`
  - `RLT\Cron::unschedule(): void`
  - `RLT\Cron::run(): void`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Integration/CronTest.php`:

```php
<?php

namespace RLT\Tests\Integration;

use RLT\Cron;
use RLT\Data\LinkRepository;
use RLT\Installer;
use RLT\Settings;
use WP_UnitTestCase;

final class CronTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Cron::unschedule();
	}

	public function test_schedule_registers_a_daily_event(): void {
		Cron::schedule();

		$this->assertNotFalse( wp_next_scheduled( Cron::HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( Cron::HOOK ) );
	}

	public function test_schedule_is_idempotent(): void {
		Cron::schedule();
		$first = wp_next_scheduled( Cron::HOOK );

		Cron::schedule();

		$this->assertSame( $first, wp_next_scheduled( Cron::HOOK ) );
	}

	public function test_unschedule_clears_the_event(): void {
		Cron::schedule();
		Cron::unschedule();

		$this->assertFalse( wp_next_scheduled( Cron::HOOK ) );
	}

	public function test_run_rotates_the_salt(): void {
		$before = Settings::salt();

		( new Cron() )->run();

		$this->assertNotSame( $before, Settings::salt() );
	}

	public function test_run_purges_logs_older_than_the_retention_window(): void {
		global $wpdb;

		Settings::update( array( 'retention_days' => 30 ) );

		$link = ( new LinkRepository() )->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );

		$wpdb->insert(
			Installer::clicksTable(),
			array(
				'link_id'      => $link['id'],
				'post_id'      => 42,
				'clicked_at'   => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
				'visitor_hash' => str_pad( 'v', 64, '0' ),
				'referer'      => '',
				'device'       => 1,
				'is_bot'       => 0,
			)
		);

		( new Cron() )->run();

		$this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::clicksTable() ) );
	}

	public function test_run_keeps_everything_when_retention_is_zero(): void {
		global $wpdb;

		Settings::update( array( 'retention_days' => 0 ) );

		$link = ( new LinkRepository() )->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'A' );

		$wpdb->insert(
			Installer::clicksTable(),
			array(
				'link_id'      => $link['id'],
				'post_id'      => 42,
				'clicked_at'   => gmdate( 'Y-m-d H:i:s', strtotime( '-1000 days' ) ),
				'visitor_hash' => str_pad( 'v', 64, '0' ),
				'referer'      => '',
				'device'       => 1,
				'is_bot'       => 0,
			)
		);

		( new Cron() )->run();

		$this->assertSame( '1', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::clicksTable() ) );
	}
}
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter CronTest"
```

Expected: FAIL — `Class "RLT\Cron" not found`

- [ ] **Step 3: 実装する**

`src/Cron.php`:

```php
<?php

declare(strict_types=1);

namespace RLT;

use RLT\Data\EventRepository;

/**
 * Daily housekeeping: drop expired raw logs and rotate the visitor salt.
 *
 * Rotating the salt is what makes visitor hashes unlinkable across days, so this
 * job is part of the privacy design, not just cleanup.
 */
final class Cron {

	public const HOOK = 'rlt_daily_maintenance';

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public function run(): void {
		$days = (int) Settings::get( 'retention_days' );

		try {
			( new EventRepository() )->purgeOlderThan( $days );
		} catch ( \Throwable $e ) {
			error_log( '[rakuten-link-tracker] log purge failed: ' . $e->getMessage() );
		}

		Settings::rotateSalt();
	}
}
```

- [ ] **Step 4: 有効化・無効化に繋ぐ**

`src/Installer.php` の `activate()` の `flush_rewrite_rules();` の**前**に1行追加する。

```php
		Cron::schedule();
```

`rakuten-link-tracker.php` の `register_deactivation_hook` のクロージャを差し替える。

```php
register_deactivation_hook(
	__FILE__,
	static function (): void {
		\RLT\Cron::unschedule();
		flush_rewrite_rules();
	}
);
```

`src/Plugin.php` の `boot()` に1行追加する。

```php
		( new Cron() )->register();
```

- [ ] **Step 5: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter CronTest"
```

Expected: PASS — `OK (6 tests, ...)`

- [ ] **Step 6: コミット**

```bash
git add src/Cron.php src/Installer.php src/Plugin.php rakuten-link-tracker.php tests/Integration/CronTest.php
git commit -m "feat: 日次のログ削除とソルト更新を追加"
```

---

## Task 20: SvgChart（依存ライブラリなしのグラフ描画）

チャートライブラリを同梱するとプラグインが肥大化し、CDNから読ませる方式は遮断環境で無言で壊れる。棒グラフ1種類を自前で描く。

**Files:**
- Create: `src/Admin/SvgChart.php`
- Test: `tests/Unit/Admin/SvgChartTest.php`

**Interfaces:**
- Consumes: なし。`tests/Unit` から読むため WordPress 関数を一切使わず、エスケープは `htmlspecialchars` で自前に行う
- Produces:
  - `RLT\Admin\SvgChart::bars(array $points, string $color, string $title, int $height = 160): string`
    - `$points` は `[['label' => '2026-09-01', 'value' => 3], ...]`
  - `RLT\Admin\SvgChart::escape(string $value): string`

**注意:** このクラスは `tests/Unit` から読むため WordPress 関数を使わない。エスケープは `htmlspecialchars` で自前に行う。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Admin/SvgChartTest.php`:

```php
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
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-unit.xml.dist --filter SvgChartTest"
```

Expected: FAIL — `Class "RLT\Admin\SvgChart" not found`

- [ ] **Step 3: 実装する**

`src/Admin/SvgChart.php`:

```php
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
```

- [ ] **Step 4: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-unit.xml.dist --filter SvgChartTest"
```

Expected: PASS — `OK (9 tests, ...)`

- [ ] **Step 5: コミット**

```bash
git add src/Admin/SvgChart.php tests/Unit/Admin/SvgChartTest.php
git commit -m "feat: 依存ライブラリなしのインラインSVG棒グラフを追加"
```

---

## Task 21: AdminMenu とダッシュボード

**Files:**
- Create: `src/Admin/AdminMenu.php`
- Create: `src/Admin/DashboardPage.php`
- Create: `assets/admin.css`
- Modify: `src/Plugin.php`
- Test: `tests/Integration/Admin/DashboardPageTest.php`

**Interfaces:**
- Consumes: `EventRepository`, `DateRange`, `SvgChart`, `Installer::CAPABILITY`
- Produces:
  - `RLT\Admin\AdminMenu::SLUG` (string `'rakuten-link-tracker'`)
  - `RLT\Admin\AdminMenu::SLUG_LINKS` (string `'rlt-links'`)
  - `RLT\Admin\AdminMenu::SLUG_POSTS` (string `'rlt-posts'`)
  - `RLT\Admin\AdminMenu::SLUG_SETTINGS` (string `'rlt-settings'`)
  - `RLT\Admin\AdminMenu::register(): void` / `addPages(): void` / `enqueue(string $hook): void`
  - `RLT\Admin\DashboardPage::render(): void`
  - `RLT\Admin\DashboardPage::rangeFromQuery(): DateRange`
  - `RLT\Admin\DashboardPage::PERIODS` (array `[7, 28, 90]`)

- [ ] **Step 1: 失敗するテストを書く**

`tests/Integration/Admin/DashboardPageTest.php`:

```php
<?php

namespace RLT\Tests\Integration\Admin;

use RLT\Admin\AdminMenu;
use RLT\Admin\DashboardPage;
use RLT\Data\LinkRepository;
use RLT\Installer;
use WP_UnitTestCase;

final class DashboardPageTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		update_option( 'timezone_string', 'Asia/Tokyo' );
		set_current_screen( 'dashboard' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	protected function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	private function render(): string {
		ob_start();
		( new DashboardPage() )->render();

		return (string) ob_get_clean();
	}

	public function test_menu_pages_are_registered(): void {
		global $admin_page_hooks, $submenu;

		( new AdminMenu() )->addPages();

		$this->assertArrayHasKey( AdminMenu::SLUG, $admin_page_hooks );

		$slugs = array_column( $submenu[ AdminMenu::SLUG ] ?? array(), 2 );
		$this->assertContains( AdminMenu::SLUG, $slugs );
		$this->assertContains( AdminMenu::SLUG_LINKS, $slugs );
		$this->assertContains( AdminMenu::SLUG_POSTS, $slugs );
		$this->assertContains( AdminMenu::SLUG_SETTINGS, $slugs );
	}

	public function test_render_outputs_the_summary_cards(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'rlt-dashboard', $html );
		$this->assertStringContainsString( 'PV', $html );
		$this->assertStringContainsString( 'CTR', $html );
	}

	public function test_render_includes_both_charts(): void {
		$html = $this->render();

		$this->assertSame( 2, substr_count( $html, '<svg' ) );
	}

	public function test_render_lists_top_links(): void {
		global $wpdb;

		$link = ( new LinkRepository() )->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, 'ホテルA' );
		$wpdb->insert(
			Installer::clicksTable(),
			array(
				'link_id'      => $link['id'],
				'post_id'      => 42,
				'clicked_at'   => gmdate( 'Y-m-d H:i:s' ),
				'visitor_hash' => str_pad( 'v', 64, '0' ),
				'referer'      => '',
				'device'       => 1,
				'is_bot'       => 0,
			)
		);

		$this->assertStringContainsString( 'ホテルA', $this->render() );
	}

	public function test_period_selector_offers_the_documented_windows(): void {
		$html = $this->render();

		foreach ( DashboardPage::PERIODS as $days ) {
			$this->assertStringContainsString( 'days=' . $days, $html );
		}
	}

	public function test_range_defaults_to_28_days(): void {
		$this->assertSame( 28, ( new DashboardPage() )->rangeFromQuery()->dayCount() );
	}

	public function test_range_honours_the_days_query_parameter(): void {
		$_GET['days'] = '7';

		$this->assertSame( 7, ( new DashboardPage() )->rangeFromQuery()->dayCount() );
	}

	public function test_range_honours_explicit_dates(): void {
		$_GET['from'] = '2026-09-01';
		$_GET['to']   = '2026-09-05';

		$range = ( new DashboardPage() )->rangeFromQuery();

		$this->assertSame( '2026-09-01', $range->fromDate() );
		$this->assertSame( '2026-09-05', $range->toDate() );
	}

	public function test_a_hostile_days_parameter_falls_back_to_the_default(): void {
		$_GET['days'] = '<script>';

		$this->assertSame( 28, ( new DashboardPage() )->rangeFromQuery()->dayCount() );
	}

	public function test_render_escapes_link_labels(): void {
		global $wpdb;

		$link = ( new LinkRepository() )->findOrCreate( 'https://hb.afl.rakuten.co.jp/a', 42, '<script>alert(1)</script>' );
		$wpdb->insert(
			Installer::clicksTable(),
			array(
				'link_id'      => $link['id'],
				'post_id'      => 42,
				'clicked_at'   => gmdate( 'Y-m-d H:i:s' ),
				'visitor_hash' => str_pad( 'v', 64, '0' ),
				'referer'      => '',
				'device'       => 1,
				'is_bot'       => 0,
			)
		);

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $this->render() );
	}
}
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter DashboardPageTest"
```

Expected: FAIL — `Class "RLT\Admin\AdminMenu" not found`

- [ ] **Step 3: AdminMenu を実装する**

`src/Admin/AdminMenu.php`:

```php
<?php

declare(strict_types=1);

namespace RLT\Admin;

use RLT\Installer;
use RLT\Plugin;

/**
 * Registers the admin menu and routes each page to its renderer.
 */
final class AdminMenu {

	public const SLUG          = 'rakuten-link-tracker';
	public const SLUG_LINKS    = 'rlt-links';
	public const SLUG_POSTS    = 'rlt-posts';
	public const SLUG_SETTINGS = 'rlt-settings';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addPages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function addPages(): void {
		add_menu_page(
			__( '楽天リンク', 'rakuten-link-tracker' ),
			__( '楽天リンク', 'rakuten-link-tracker' ),
			Installer::CAPABILITY,
			self::SLUG,
			array( new DashboardPage(), 'render' ),
			'dashicons-chart-line',
			58
		);

		add_submenu_page(
			self::SLUG,
			__( 'ダッシュボード', 'rakuten-link-tracker' ),
			__( 'ダッシュボード', 'rakuten-link-tracker' ),
			Installer::CAPABILITY,
			self::SLUG,
			array( new DashboardPage(), 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'リンク一覧', 'rakuten-link-tracker' ),
			__( 'リンク一覧', 'rakuten-link-tracker' ),
			Installer::CAPABILITY,
			self::SLUG_LINKS,
			array( new LinkDetailPage(), 'route' )
		);

		add_submenu_page(
			self::SLUG,
			__( '記事別レポート', 'rakuten-link-tracker' ),
			__( '記事別レポート', 'rakuten-link-tracker' ),
			Installer::CAPABILITY,
			self::SLUG_POSTS,
			array( new PostsReportPage(), 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( '設定', 'rakuten-link-tracker' ),
			__( '設定', 'rakuten-link-tracker' ),
			'manage_options',
			self::SLUG_SETTINGS,
			array( new SettingsPage(), 'render' )
		);
	}

	public function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, self::SLUG ) && ! str_contains( $hook, 'rlt-' ) ) {
			return;
		}

		wp_enqueue_style( 'rlt-admin', Plugin::url( 'assets/admin.css' ), array(), Plugin::VERSION );
	}

	/**
	 * URL of one of the plugin's admin pages.
	 *
	 * @param array<string, string|int> $args
	 */
	public static function url( string $slug, array $args = array() ): string {
		return add_query_arg(
			array_merge( array( 'page' => $slug ), $args ),
			admin_url( 'admin.php' )
		);
	}
}
```

- [ ] **Step 4: DashboardPage を実装する**

`src/Admin/DashboardPage.php`:

```php
<?php

declare(strict_types=1);

namespace RLT\Admin;

use RLT\Data\EventRepository;
use RLT\Installer;
use RLT\Settings;
use RLT\Support\DateRange;

/**
 * Overview screen: totals, daily trend, and the leading links and posts.
 */
final class DashboardPage {

	public const PERIODS = array( 7, 28, 90 );

	private const DEFAULT_DAYS = 28;

	private EventRepository $events;

	public function __construct( ?EventRepository $events = null ) {
		$this->events = $events ?? new EventRepository();
	}

	/**
	 * Read the period out of the query string, defaulting to 28 days.
	 */
	public function rangeFromQuery(): DateRange {
		// Read-only reporting filters; no state changes, so no nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['from'] ) ) : null;
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['to'] ) ) : null;

		if ( null !== $from && null !== $to ) {
			return DateRange::fromRequest( $from, $to, self::DEFAULT_DAYS );
		}

		$days = isset( $_GET['days'] ) ? absint( wp_unslash( $_GET['days'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return DateRange::lastDays( in_array( $days, self::PERIODS, true ) ? $days : self::DEFAULT_DAYS );
	}

	public function render(): void {
		if ( ! current_user_can( Installer::CAPABILITY ) ) {
			wp_die( esc_html__( 'この画面を表示する権限がありません。', 'rakuten-link-tracker' ) );
		}

		$range    = $this->rangeFromQuery();
		$totals   = $this->events->summary( $range );
		$previous = $this->events->summary( $range->previous() );
		$daily    = $this->events->daily( $range );
		$topLinks = $this->events->byLink( $range, false, null, 'clicks', 10 );
		$topPosts = $this->events->byPost( $range, false, 'views', 10 );

		echo '<div class="wrap rlt-dashboard">';
		echo '<h1>' . esc_html__( '楽天リンク ダッシュボード', 'rakuten-link-tracker' ) . '</h1>';

		$this->renderPeriodSelector( $range );

		echo '<div class="rlt-cards">';
		$this->renderCard( __( 'PV', 'rakuten-link-tracker' ), number_format( $totals['views'] ), $totals['views'], $previous['views'] );
		$this->renderCard( __( 'クリック', 'rakuten-link-tracker' ), number_format( $totals['clicks'] ), $totals['clicks'], $previous['clicks'] );
		$this->renderCard( __( 'CTR', 'rakuten-link-tracker' ), number_format( $totals['ctr'] * 100, 2 ) . '%', $totals['ctr'], $previous['ctr'] );
		$this->renderCard( __( 'ユニーククリック', 'rakuten-link-tracker' ), number_format( $totals['unique_clicks'] ), $totals['unique_clicks'], $previous['unique_clicks'] );
		echo '</div>';

		$viewPoints  = array_map(
			static fn ( array $d ): array => array( 'label' => $d['date'], 'value' => $d['views'] ),
			$daily
		);
		$clickPoints = array_map(
			static fn ( array $d ): array => array( 'label' => $d['date'], 'value' => $d['clicks'] ),
			$daily
		);

		echo '<h2>' . esc_html__( '日別PV', 'rakuten-link-tracker' ) . '</h2>';
		// SvgChart escapes every value it writes.
		echo SvgChart::bars( $viewPoints, '#2271b1', __( '日別PV', 'rakuten-link-tracker' ) ); // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<h2>' . esc_html__( '日別クリック', 'rakuten-link-tracker' ) . '</h2>';
		echo SvgChart::bars( $clickPoints, '#d63638', __( '日別クリック', 'rakuten-link-tracker' ) ); // phpcs:ignore WordPress.Security.EscapeOutput

		$this->renderTopLinks( $topLinks );
		$this->renderTopPosts( $topPosts );

		echo '</div>';
	}

	private function renderPeriodSelector( DateRange $range ): void {
		echo '<p class="rlt-periods">';

		foreach ( self::PERIODS as $days ) {
			printf(
				'<a class="button%s" href="%s">%s</a> ',
				$range->dayCount() === $days ? ' button-primary' : '',
				esc_url( AdminMenu::url( AdminMenu::SLUG, array( 'days' => $days ) ) ),
				esc_html( sprintf( /* translators: %d: number of days */ __( '過去%d日', 'rakuten-link-tracker' ), $days ) )
			);
		}

		printf(
			'<span class="rlt-range">%s 〜 %s（%s）</span>',
			esc_html( $range->fromDate() ),
			esc_html( $range->toDate() ),
			esc_html( wp_timezone_string() )
		);

		echo '</p>';
	}

	private function renderCard( string $label, string $value, float $current, float $previous ): void {
		echo '<div class="rlt-card">';
		echo '<span class="rlt-card-label">' . esc_html( $label ) . '</span>';
		echo '<span class="rlt-card-value">' . esc_html( $value ) . '</span>';

		if ( $previous > 0 ) {
			$change = ( ( $current - $previous ) / $previous ) * 100;
			printf(
				'<span class="rlt-card-change %s">%s%s%%</span>',
				$change >= 0 ? 'is-up' : 'is-down',
				$change >= 0 ? '+' : '',
				esc_html( number_format( $change, 1 ) )
			);
		}

		echo '</div>';
	}

	/**
	 * @param array<int, array<string, mixed>> $links
	 */
	private function renderTopLinks( array $links ): void {
		echo '<h2>' . esc_html__( 'クリックの多いリンク', 'rakuten-link-tracker' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'ラベル', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( '記事', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'クリック', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'CTR', 'rakuten-link-tracker' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( array() === $links ) {
			echo '<tr><td colspan="4">' . esc_html__( 'まだデータがありません。', 'rakuten-link-tracker' ) . '</td></tr>';
		}

		foreach ( $links as $link ) {
			printf(
				'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%d</td><td>%s%%</td></tr>',
				esc_url( AdminMenu::url( AdminMenu::SLUG_LINKS, array( 'code' => $link['code'] ) ) ),
				esc_html( '' === $link['label'] ? $link['code'] : $link['label'] ),
				esc_html( get_the_title( $link['post_id'] ) ),
				(int) $link['clicks'],
				esc_html( number_format( $link['ctr'] * 100, 2 ) )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * @param array<int, array<string, mixed>> $posts
	 */
	private function renderTopPosts( array $posts ): void {
		echo '<h2>' . esc_html__( 'PVの多い記事', 'rakuten-link-tracker' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( '記事', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'PV', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'クリック', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'CTR', 'rakuten-link-tracker' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( array() === $posts ) {
			echo '<tr><td colspan="4">' . esc_html__( 'まだデータがありません。', 'rakuten-link-tracker' ) . '</td></tr>';
		}

		foreach ( $posts as $post ) {
			printf(
				'<tr><td>%s</td><td>%d</td><td>%d</td><td>%s%%</td></tr>',
				esc_html( get_the_title( $post['post_id'] ) ),
				(int) $post['views'],
				(int) $post['clicks'],
				esc_html( number_format( $post['ctr'] * 100, 2 ) )
			);
		}

		echo '</tbody></table>';
	}
}
```

- [ ] **Step 5: `assets/admin.css` を作る**

```css
.rlt-cards {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	margin: 16px 0 24px;
}

.rlt-card {
	background: #fff;
	border: 1px solid #dcdcde;
	border-radius: 4px;
	padding: 12px 16px;
	min-width: 150px;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.rlt-card-label {
	color: #50575e;
	font-size: 12px;
}

.rlt-card-value {
	font-size: 22px;
	font-weight: 600;
	line-height: 1.2;
}

.rlt-card-change {
	font-size: 12px;
}

.rlt-card-change.is-up {
	color: #007017;
}

.rlt-card-change.is-down {
	color: #b32d2e;
}

.rlt-chart {
	background: #fff;
	border: 1px solid #dcdcde;
	border-radius: 4px;
	display: block;
	max-width: 100%;
}

.rlt-periods .rlt-range {
	margin-left: 12px;
	color: #50575e;
}

.rlt-short-url {
	font-family: Consolas, Monaco, monospace;
	font-size: 12px;
}

.rlt-progress {
	background: #dcdcde;
	border-radius: 3px;
	height: 18px;
	max-width: 420px;
	overflow: hidden;
}

.rlt-progress-bar {
	background: #2271b1;
	height: 100%;
	width: 0;
	transition: width 0.2s ease;
}
```

- [ ] **Step 6: `Plugin::boot()` に繋ぐ**

```php
		if ( is_admin() ) {
			( new \RLT\Admin\AdminMenu() )->register();
		}
```

- [ ] **Step 7: テストが通ることを確認する**

Task 22〜24 のページクラスがまだ無いため、`AdminMenu::addPages()` が参照するクラスをこの時点で空実装として作っておく。

`src/Admin/LinkDetailPage.php`（この時点では最小限、Task 22 で中身を書く）:

```php
<?php

declare(strict_types=1);

namespace RLT\Admin;

final class LinkDetailPage {

	public function route(): void {
		// Implemented in the links list task.
	}
}
```

`src/Admin/PostsReportPage.php`:

```php
<?php

declare(strict_types=1);

namespace RLT\Admin;

final class PostsReportPage {

	public function render(): void {
		// Implemented in the posts report task.
	}
}
```

`src/Admin/SettingsPage.php`:

```php
<?php

declare(strict_types=1);

namespace RLT\Admin;

final class SettingsPage {

	public function render(): void {
		// Implemented in the settings task.
	}
}
```

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter DashboardPageTest"
```

Expected: PASS — `OK (10 tests, ...)`

- [ ] **Step 8: コミット**

```bash
git add src/Admin src/Plugin.php assets/admin.css tests/Integration/Admin/DashboardPageTest.php
git commit -m "feat: 管理メニューとダッシュボードを追加"
```

---

## Task 22: リンク一覧と詳細

**Files:**
- Create: `src/Admin/LinksListTable.php`
- Rewrite: `src/Admin/LinkDetailPage.php`
- Test: `tests/Integration/Admin/LinksListTableTest.php`

**Interfaces:**
- Consumes: `EventRepository::byLink()`, `EventRepository::linkDetail()`, `LinkRepository`, `DateRange`, `SvgChart`
- Produces:
  - `RLT\Admin\LinksListTable::__construct(DateRange $range)`
  - `RLT\Admin\LinksListTable::get_columns(): array`
  - `RLT\Admin\LinksListTable::prepare_items(): void`
  - `RLT\Admin\LinkDetailPage::route(): void` — `code` があれば詳細、無ければ一覧
  - `RLT\Admin\LinkDetailPage::renderList(): void`
  - `RLT\Admin\LinkDetailPage::renderDetail(string $code): void`
  - `RLT\Admin\LinkDetailPage::handleUpdate(): void` — `admin_post_rlt_update_link`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Integration/Admin/LinksListTableTest.php`:

```php
<?php

namespace RLT\Tests\Integration\Admin;

use RLT\Admin\LinkDetailPage;
use RLT\Admin\LinksListTable;
use RLT\Data\LinkRepository;
use RLT\Installer;
use RLT\Support\DateRange;
use WP_UnitTestCase;

final class LinksListTableTest extends WP_UnitTestCase {

	private LinkRepository $links;
	private array $link;
	private int $postId;

	protected function setUp(): void {
		parent::setUp();
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		update_option( 'timezone_string', 'Asia/Tokyo' );
		set_current_screen( 'toplevel_page_rakuten-link-tracker' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->postId = self::factory()->post->create( array( 'post_title' => '東京のホテル特集', 'post_status' => 'publish' ) );
		$this->links  = new LinkRepository();
		$this->link   = $this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', $this->postId, 'ホテルA' );
	}

	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		parent::tearDown();
	}

	private function table(): LinksListTable {
		$table = new LinksListTable( DateRange::lastDays( 28 ) );
		$table->prepare_items();

		return $table;
	}

	public function test_columns_cover_the_documented_fields(): void {
		$columns = ( new LinksListTable( DateRange::lastDays( 28 ) ) )->get_columns();

		foreach ( array( 'label', 'code', 'post', 'clicks', 'ctr', 'last_click', 'status' ) as $column ) {
			$this->assertArrayHasKey( $column, $columns, "Column {$column} is missing." );
		}
	}

	public function test_prepare_items_loads_the_links(): void {
		$table = $this->table();

		$this->assertCount( 1, $table->items );
		$this->assertSame( $this->link['code'], $table->items[0]['code'] );
	}

	public function test_prepare_items_can_filter_by_post(): void {
		$other = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/b', $other, 'B' );

		$_GET['post_id'] = (string) $this->postId;

		$this->assertCount( 1, $this->table()->items );
	}

	public function test_prepare_items_can_search_by_label(): void {
		$this->links->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/b', $this->postId, '大阪の宿' );

		$_GET['s'] = '大阪';

		$items = $this->table()->items;

		$this->assertCount( 1, $items );
		$this->assertSame( '大阪の宿', $items[0]['label'] );
	}

	public function test_list_screen_renders_the_short_url(): void {
		ob_start();
		( new LinkDetailPage() )->route();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '/go/' . $this->link['code'], $html );
		$this->assertStringContainsString( 'ホテルA', $html );
	}

	public function test_detail_screen_renders_breakdowns(): void {
		global $wpdb;

		$wpdb->insert(
			Installer::clicksTable(),
			array(
				'link_id'      => $this->link['id'],
				'post_id'      => $this->postId,
				'clicked_at'   => gmdate( 'Y-m-d H:i:s' ),
				'visitor_hash' => str_pad( 'v', 64, '0' ),
				'referer'      => 'https://www.google.com/',
				'device'       => 2,
				'is_bot'       => 0,
			)
		);

		$_GET['code'] = $this->link['code'];

		ob_start();
		( new LinkDetailPage() )->route();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'https://www.google.com/', $html );
		$this->assertStringContainsString( 'mobile', $html );
		$this->assertStringContainsString( '<svg', $html );
	}

	public function test_detail_screen_shows_a_notice_for_an_unknown_code(): void {
		$_GET['code'] = 'zzzzzz';

		ob_start();
		( new LinkDetailPage() )->route();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $html );
	}

	public function test_update_changes_the_target_url(): void {
		$_POST = array(
			'code'       => $this->link['code'],
			'target_url' => 'https://hb.afl.rakuten.co.jp/hgc/new',
			'label'      => '改名しました',
			'_wpnonce'   => wp_create_nonce( 'rlt_update_link' ),
		);

		( new LinkDetailPage() )->handleUpdate( false );

		$updated = $this->links->findById( $this->link['id'] );

		$this->assertSame( 'https://hb.afl.rakuten.co.jp/hgc/new', $updated['target_url'] );
		$this->assertSame( '改名しました', $updated['label'] );
	}

	public function test_update_without_a_valid_nonce_changes_nothing(): void {
		$_POST = array(
			'code'       => $this->link['code'],
			'target_url' => 'https://evil.example/',
			'_wpnonce'   => 'wrong',
		);

		( new LinkDetailPage() )->handleUpdate( false );

		$this->assertSame( 'https://hb.afl.rakuten.co.jp/hgc/a', $this->links->findById( $this->link['id'] )['target_url'] );
	}

	public function test_update_rejects_a_javascript_url(): void {
		$_POST = array(
			'code'       => $this->link['code'],
			'target_url' => 'javascript:alert(1)',
			'_wpnonce'   => wp_create_nonce( 'rlt_update_link' ),
		);

		( new LinkDetailPage() )->handleUpdate( false );

		$this->assertSame( 'https://hb.afl.rakuten.co.jp/hgc/a', $this->links->findById( $this->link['id'] )['target_url'] );
	}
}
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter LinksListTableTest"
```

Expected: FAIL — `Class "RLT\Admin\LinksListTable" not found`

- [ ] **Step 3: LinksListTable を実装する**

`src/Admin/LinksListTable.php`:

```php
<?php

declare(strict_types=1);

namespace RLT\Admin;

use RLT\Data\EventRepository;
use RLT\Settings;
use RLT\Support\DateRange;

/**
 * The links table, backed by the aggregated per-link statistics.
 */
final class LinksListTable extends \WP_List_Table {

	private const PER_PAGE = 30;

	private DateRange $range;
	private EventRepository $events;

	public function __construct( DateRange $range, ?EventRepository $events = null ) {
		parent::__construct(
			array(
				'singular' => 'rlt_link',
				'plural'   => 'rlt_links',
				'ajax'     => false,
			)
		);

		$this->range  = $range;
		$this->events = $events ?? new EventRepository();
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'label'      => __( 'ラベル', 'rakuten-link-tracker' ),
			'code'       => __( '短縮URL', 'rakuten-link-tracker' ),
			'post'       => __( '掲載記事', 'rakuten-link-tracker' ),
			'clicks'     => __( 'クリック', 'rakuten-link-tracker' ),
			'ctr'        => __( 'CTR', 'rakuten-link-tracker' ),
			'last_click' => __( '最終クリック', 'rakuten-link-tracker' ),
			'status'     => __( '状態', 'rakuten-link-tracker' ),
		);
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function get_sortable_columns(): array {
		return array(
			'clicks'     => array( 'clicks', true ),
			'ctr'        => array( 'ctr', false ),
			'last_click' => array( 'last_click', false ),
		);
	}

	public function prepare_items(): void {
		// Read-only listing filters; no state changes, so no nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$postId  = isset( $_GET['post_id'] ) ? absint( wp_unslash( $_GET['post_id'] ) ) : 0;
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( (string) $_GET['orderby'] ) ) : 'clicks';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$rows = $this->events->byLink( $this->range, false, $postId > 0 ? $postId : null, $orderby, 500 );

		if ( '' !== $search ) {
			$needle = mb_strtolower( $search );
			$rows   = array_values(
				array_filter(
					$rows,
					static function ( array $row ) use ( $needle ): bool {
						return str_contains( mb_strtolower( $row['label'] ), $needle )
							|| str_contains( mb_strtolower( $row['code'] ), $needle )
							|| str_contains( mb_strtolower( $row['target_url'] ), $needle );
					}
				)
			);
		}

		$total   = count( $rows );
		$current = $this->get_pagenum();

		$this->items = array_slice( $rows, ( $current - 1 ) * self::PER_PAGE, self::PER_PAGE );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total / self::PER_PAGE ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_label( array $item ): string {
		$name = '' === $item['label'] ? $item['code'] : $item['label'];

		$actions = array(
			'detail' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( AdminMenu::url( AdminMenu::SLUG_LINKS, array( 'code' => $item['code'] ) ) ),
				esc_html__( '詳細・編集', 'rakuten-link-tracker' )
			),
			'target' => sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( $item['target_url'] ),
				esc_html__( '遷移先を開く', 'rakuten-link-tracker' )
			),
		);

		return sprintf(
			'<strong><a href="%s">%s</a></strong>%s',
			esc_url( AdminMenu::url( AdminMenu::SLUG_LINKS, array( 'code' => $item['code'] ) ) ),
			esc_html( $name ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_code( array $item ): string {
		return sprintf(
			'<code class="rlt-short-url">%s</code>',
			esc_html( Settings::shortUrl( $item['code'] ) )
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_post( array $item ): string {
		if ( $item['post_id'] <= 0 ) {
			return '—';
		}

		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( (string) get_edit_post_link( $item['post_id'] ) ),
			esc_html( get_the_title( $item['post_id'] ) )
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_clicks( array $item ): string {
		return sprintf(
			'%d <span class="description">(%s %d)</span>',
			(int) $item['clicks'],
			esc_html__( 'ユニーク', 'rakuten-link-tracker' ),
			(int) $item['unique_clicks']
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_ctr( array $item ): string {
		return esc_html( number_format( $item['ctr'] * 100, 2 ) . '%' );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_last_click( array $item ): string {
		if ( null === $item['last_click'] ) {
			return '—';
		}

		return esc_html( get_date_from_gmt( (string) $item['last_click'], 'Y-m-d H:i' ) );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_status( array $item ): string {
		return 1 === (int) $item['status']
			? esc_html__( '有効', 'rakuten-link-tracker' )
			: esc_html__( 'アーカイブ', 'rakuten-link-tracker' );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	public function no_items(): void {
		esc_html_e( 'リンクがまだありません。楽天アフィリエイトリンクを含む記事を保存すると、ここに出てきます。', 'rakuten-link-tracker' );
	}
}
```

- [ ] **Step 4: LinkDetailPage を実装する**

`src/Admin/LinkDetailPage.php`（Task 21 で作った空実装を置き換える）:

```php
<?php

declare(strict_types=1);

namespace RLT\Admin;

use RLT\Data\EventRepository;
use RLT\Data\LinkRepository;
use RLT\Installer;
use RLT\Settings;
use RLT\Support\DateRange;

/**
 * The links screen: a list, and a detail view for one link.
 */
final class LinkDetailPage {

	private LinkRepository $links;
	private EventRepository $events;

	public function __construct( ?LinkRepository $links = null, ?EventRepository $events = null ) {
		$this->links  = $links ?? new LinkRepository();
		$this->events = $events ?? new EventRepository();
	}

	public function register(): void {
		add_action( 'admin_post_rlt_update_link', array( $this, 'handleUpdate' ) );
	}

	public function route(): void {
		if ( ! current_user_can( Installer::CAPABILITY ) ) {
			wp_die( esc_html__( 'この画面を表示する権限がありません。', 'rakuten-link-tracker' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['code'] ) ? sanitize_key( wp_unslash( (string) $_GET['code'] ) ) : '';

		if ( '' === $code ) {
			$this->renderList();

			return;
		}

		$this->renderDetail( $code );
	}

	public function renderList(): void {
		$range = ( new DashboardPage() )->rangeFromQuery();
		$table = new LinksListTable( $range, $this->events );
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'リンク一覧', 'rakuten-link-tracker' ) . '</h1>';
		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( AdminMenu::SLUG_LINKS ) );
		$table->search_box( __( 'リンクを検索', 'rakuten-link-tracker' ), 'rlt-link-search' );
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	public function renderDetail( string $code ): void {
		$link = $this->links->findByCode( $code );

		if ( null === $link ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>'
				. esc_html__( 'リンクが見つかりません。', 'rakuten-link-tracker' )
				. '</p></div></div>';

			return;
		}

		$range  = ( new DashboardPage() )->rangeFromQuery();
		$detail = $this->events->linkDetail( $link['id'], $range, false );

		echo '<div class="wrap">';
		printf(
			'<h1>%s</h1>',
			esc_html( '' === $link['label'] ? $link['code'] : $link['label'] )
		);

		printf(
			'<p><code class="rlt-short-url">%s</code></p>',
			esc_html( Settings::shortUrl( $link['code'] ) )
		);

		$this->renderEditForm( $link );

		echo '<h2>' . esc_html__( '日別クリック', 'rakuten-link-tracker' ) . '</h2>';
		$points = array_map(
			static fn ( array $d ): array => array( 'label' => $d['date'], 'value' => $d['clicks'] ),
			$detail['daily']
		);
		echo SvgChart::bars( $points, '#d63638', __( '日別クリック', 'rakuten-link-tracker' ) ); // phpcs:ignore WordPress.Security.EscapeOutput

		$this->renderBreakdown(
			__( 'リファラ', 'rakuten-link-tracker' ),
			array_map(
				static fn ( array $r ): array => array(
					'name'   => '' === $r['referer'] ? __( '（不明・直接）', 'rakuten-link-tracker' ) : $r['referer'],
					'clicks' => $r['clicks'],
				),
				$detail['referers']
			)
		);

		$this->renderBreakdown(
			__( 'デバイス', 'rakuten-link-tracker' ),
			array_map(
				static fn ( array $d ): array => array(
					'name'   => $d['label'],
					'clicks' => $d['clicks'],
				),
				$detail['devices']
			)
		);

		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $link
	 */
	private function renderEditForm( array $link ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="rlt_update_link" />';
		printf( '<input type="hidden" name="code" value="%s" />', esc_attr( $link['code'] ) );
		wp_nonce_field( 'rlt_update_link' );

		echo '<table class="form-table"><tbody>';

		printf(
			'<tr><th scope="row"><label for="rlt-label">%s</label></th><td><input class="regular-text" type="text" id="rlt-label" name="label" value="%s" /></td></tr>',
			esc_html__( 'ラベル', 'rakuten-link-tracker' ),
			esc_attr( $link['label'] )
		);

		printf(
			'<tr><th scope="row"><label for="rlt-target">%s</label></th><td><input class="large-text" type="url" id="rlt-target" name="target_url" value="%s" /><p class="description">%s</p></td></tr>',
			esc_html__( '遷移先URL', 'rakuten-link-tracker' ),
			esc_attr( $link['target_url'] ),
			esc_html__( '楽天のリンク先が変わったときは、記事を編集せずここだけ直せます。', 'rakuten-link-tracker' )
		);

		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="status" value="1" %s /> %s</label></td></tr>',
			esc_html__( '状態', 'rakuten-link-tracker' ),
			checked( 1, (int) $link['status'], false ),
			esc_html__( '有効', 'rakuten-link-tracker' )
		);

		echo '</tbody></table>';
		submit_button( __( '保存', 'rakuten-link-tracker' ) );
		echo '</form>';
	}

	/**
	 * @param array<int, array{name: string, clicks: int}> $rows
	 */
	private function renderBreakdown( string $heading, array $rows ): void {
		echo '<h2>' . esc_html( $heading ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';

		if ( array() === $rows ) {
			echo '<tr><td>' . esc_html__( 'データがありません。', 'rakuten-link-tracker' ) . '</td></tr>';
		}

		foreach ( $rows as $row ) {
			printf(
				'<tr><td>%s</td><td style="width:100px">%d</td></tr>',
				esc_html( $row['name'] ),
				(int) $row['clicks']
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * @param bool $redirect Set false in tests so the process does not exit.
	 */
	public function handleUpdate( bool $redirect = true ): void {
		if ( ! current_user_can( Installer::CAPABILITY ) ) {
			return;
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), 'rlt_update_link' ) ) {
			return;
		}

		$code = isset( $_POST['code'] ) ? sanitize_key( wp_unslash( (string) $_POST['code'] ) ) : '';
		$link = $this->links->findByCode( $code );

		if ( null === $link ) {
			return;
		}

		$fields = array(
			'label'  => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : $link['label'],
			'status' => empty( $_POST['status'] ) ? 0 : 1,
		);

		if ( isset( $_POST['target_url'] ) ) {
			$url = esc_url_raw( wp_unslash( (string) $_POST['target_url'] ), array( 'http', 'https' ) );

			// An empty result means the scheme was rejected; leave the old URL in place.
			if ( '' !== $url ) {
				$fields['target_url'] = $url;
			}
		}

		$this->links->update( $link['id'], $fields );

		if ( $redirect ) {
			wp_safe_redirect( AdminMenu::url( AdminMenu::SLUG_LINKS, array( 'code' => $code, 'updated' => 1 ) ) );
			exit;
		}
	}
}
```

- [ ] **Step 5: `AdminMenu::register()` にフォーム処理を繋ぐ**

`src/Admin/AdminMenu.php` の `register()` に1行追加する。

```php
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addPages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		( new LinkDetailPage() )->register();
	}
```

- [ ] **Step 6: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter LinksListTableTest"
```

Expected: PASS — `OK (10 tests, ...)`

- [ ] **Step 7: コミット**

```bash
git add src/Admin/LinksListTable.php src/Admin/LinkDetailPage.php src/Admin/AdminMenu.php tests/Integration/Admin/LinksListTableTest.php
git commit -m "feat: リンク一覧と詳細・編集画面を追加"
```

---

## Task 23: 記事別レポート

**Files:**
- Rewrite: `src/Admin/PostsReportPage.php`
- Test: `tests/Integration/Admin/PostsReportPageTest.php`

**Interfaces:**
- Consumes: `EventRepository::byPost()`, `DashboardPage::rangeFromQuery()`
- Produces:
  - `RLT\Admin\PostsReportPage::render(): void`
  - `RLT\Admin\PostsReportPage::ORDERINGS` (array) — `orderby` の値 => ラベル

- [ ] **Step 1: 失敗するテストを書く**

`tests/Integration/Admin/PostsReportPageTest.php`:

```php
<?php

namespace RLT\Tests\Integration\Admin;

use RLT\Admin\PostsReportPage;
use RLT\Data\LinkRepository;
use RLT\Installer;
use WP_UnitTestCase;

final class PostsReportPageTest extends WP_UnitTestCase {

	private int $postId;

	protected function setUp(): void {
		parent::setUp();
		update_option( 'timezone_string', 'Asia/Tokyo' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->postId = self::factory()->post->create( array( 'post_title' => '京都の宿10選', 'post_status' => 'publish' ) );
		( new LinkRepository() )->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', $this->postId, 'A' );

		global $wpdb;
		$wpdb->insert(
			Installer::viewsTable(),
			array(
				'post_id'      => $this->postId,
				'viewed_at'    => gmdate( 'Y-m-d H:i:s' ),
				'visitor_hash' => str_pad( 'v', 64, '0' ),
				'referer'      => '',
				'device'       => 1,
				'is_bot'       => 0,
			)
		);
	}

	protected function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	private function render(): string {
		ob_start();
		( new PostsReportPage() )->render();

		return (string) ob_get_clean();
	}

	public function test_render_lists_the_post(): void {
		$html = $this->render();

		$this->assertStringContainsString( '京都の宿10選', $html );
	}

	public function test_render_shows_views_clicks_and_ctr_columns(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'PV', $html );
		$this->assertStringContainsString( 'クリック', $html );
		$this->assertStringContainsString( 'CTR', $html );
	}

	public function test_render_offers_every_documented_ordering(): void {
		$html = $this->render();

		foreach ( array_keys( PostsReportPage::ORDERINGS ) as $orderby ) {
			$this->assertStringContainsString( 'orderby=' . $orderby, $html );
		}
	}

	public function test_ctr_ascending_ordering_is_available_for_finding_weak_posts(): void {
		$this->assertArrayHasKey( 'ctr_asc', PostsReportPage::ORDERINGS );
	}

	public function test_render_links_to_the_links_screen_filtered_by_post(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'post_id=' . $this->postId, $html );
	}

	public function test_render_escapes_post_titles(): void {
		self::factory()->post->create( array( 'post_title' => '<script>alert(1)</script>', 'post_status' => 'publish' ) );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $this->render() );
	}

	public function test_render_shows_an_empty_state(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Installer::viewsTable() );

		$this->assertStringContainsString( 'まだデータがありません', $this->render() );
	}
}
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PostsReportPageTest"
```

Expected: FAIL — `render()` が何も出力しない

- [ ] **Step 3: 実装する**

`src/Admin/PostsReportPage.php`（Task 21 で作った空実装を置き換える）:

```php
<?php

declare(strict_types=1);

namespace RLT\Admin;

use RLT\Data\EventRepository;
use RLT\Installer;

/**
 * Per-post view of the same data, so a weak article is easy to spot.
 */
final class PostsReportPage {

	/**
	 * @var array<string, string>
	 */
	public const ORDERINGS = array(
		'views'   => 'PVが多い順',
		'clicks'  => 'クリックが多い順',
		'ctr'     => 'CTRが高い順',
		'ctr_asc' => 'CTRが低い順（改善候補）',
	);

	private EventRepository $events;

	public function __construct( ?EventRepository $events = null ) {
		$this->events = $events ?? new EventRepository();
	}

	public function render(): void {
		if ( ! current_user_can( Installer::CAPABILITY ) ) {
			wp_die( esc_html__( 'この画面を表示する権限がありません。', 'rakuten-link-tracker' ) );
		}

		$range = ( new DashboardPage() )->rangeFromQuery();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( (string) $_GET['orderby'] ) ) : 'views';

		if ( ! isset( self::ORDERINGS[ $orderby ] ) ) {
			$orderby = 'views';
		}

		$rows = $this->events->byPost( $range, false, $orderby, 200 );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( '記事別レポート', 'rakuten-link-tracker' ) . '</h1>';

		printf(
			'<p class="description">%s 〜 %s（%s）</p>',
			esc_html( $range->fromDate() ),
			esc_html( $range->toDate() ),
			esc_html( wp_timezone_string() )
		);

		echo '<p>';
		foreach ( self::ORDERINGS as $key => $label ) {
			printf(
				'<a class="button%s" href="%s">%s</a> ',
				$key === $orderby ? ' button-primary' : '',
				esc_url( AdminMenu::url( AdminMenu::SLUG_POSTS, array( 'orderby' => $key, 'days' => $range->dayCount() ) ) ),
				esc_html( $label )
			);
		}
		echo '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( '記事', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'PV', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'クリック', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'CTR', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'リンク', 'rakuten-link-tracker' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( array() === $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'まだデータがありません。', 'rakuten-link-tracker' ) . '</td></tr>';
		}

		foreach ( $rows as $row ) {
			printf(
				'<tr><td><a href="%s">%s</a></td><td>%d</td><td>%d</td><td>%s%%</td><td><a href="%s">%s</a></td></tr>',
				esc_url( (string) get_edit_post_link( $row['post_id'] ) ),
				esc_html( get_the_title( $row['post_id'] ) ),
				(int) $row['views'],
				(int) $row['clicks'],
				esc_html( number_format( $row['ctr'] * 100, 2 ) ),
				esc_url( AdminMenu::url( AdminMenu::SLUG_LINKS, array( 'post_id' => $row['post_id'] ) ) ),
				esc_html__( 'この記事のリンク', 'rakuten-link-tracker' )
			);
		}

		echo '</tbody></table>';
		echo '</div>';
	}
}
```

- [ ] **Step 4: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PostsReportPageTest"
```

Expected: PASS — `OK (7 tests, ...)`

- [ ] **Step 5: コミット**

```bash
git add src/Admin/PostsReportPage.php tests/Integration/Admin/PostsReportPageTest.php
git commit -m "feat: 記事別レポート（CTR昇順で改善候補を見つけられる）を追加"
```

---

## Task 24: 一括変換と一括復元

**Files:**
- Create: `src/Admin/BulkConverter.php`
- Create: `assets/admin.js`
- Test: `tests/Integration/Admin/BulkConverterTest.php`

**Interfaces:**
- Consumes: `PostSync::syncPost()`, `PostSync::restorePost()`, `LinkRepository::postIdsWithLinks()`
- Produces:
  - `RLT\Admin\BulkConverter::BATCH_SIZE` (int 20)
  - `RLT\Admin\BulkConverter::ACTION_CONVERT` (string `'rlt_bulk_convert'`)
  - `RLT\Admin\BulkConverter::ACTION_RESTORE` (string `'rlt_bulk_restore'`)
  - `register(): void`
  - `convertBatch(int $offset): array` → `['offset'=>int,'processed'=>int,'changed'=>int,'total'=>int,'done'=>bool]`
  - `restoreBatch(int $offset): array` → 同じ形
  - `ajaxConvert(): void` / `ajaxRestore(): void`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Integration/Admin/BulkConverterTest.php`:

```php
<?php

namespace RLT\Tests\Integration\Admin;

use RLT\Admin\BulkConverter;
use RLT\Data\LinkRepository;
use RLT\Frontend\PostSync;
use RLT\Settings;
use WP_UnitTestCase;

final class BulkConverterTest extends WP_UnitTestCase {

	private const AFFILIATE = 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=x';

	private BulkConverter $bulk;

	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->bulk = new BulkConverter();
	}

	/**
	 * Create a post whose content still holds the raw affiliate URL, i.e. one
	 * published before the plugin was installed.
	 */
	private function legacyPost(): int {
		$postId = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => 'placeholder' ) );

		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array( 'post_content' => '<a href="' . self::AFFILIATE . '">ホテル</a>' ),
			array( 'ID' => $postId )
		);
		clean_post_cache( $postId );

		return $postId;
	}

	public function test_convert_batch_reports_progress(): void {
		$this->legacyPost();
		$this->legacyPost();

		$result = $this->bulk->convertBatch( 0 );

		$this->assertSame( 2, $result['total'] );
		$this->assertSame( 2, $result['processed'] );
		$this->assertSame( 2, $result['changed'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_convert_batch_actually_rewrites_the_content(): void {
		$postId = $this->legacyPost();

		$this->bulk->convertBatch( 0 );

		$content = get_post_field( 'post_content', $postId );

		$this->assertStringNotContainsString( self::AFFILIATE, $content );
		$this->assertStringContainsString( Settings::shortBase(), $content );
	}

	public function test_convert_batch_is_capped_at_the_batch_size(): void {
		for ( $i = 0; $i < BulkConverter::BATCH_SIZE + 3; $i++ ) {
			$this->legacyPost();
		}

		$first = $this->bulk->convertBatch( 0 );

		$this->assertSame( BulkConverter::BATCH_SIZE, $first['processed'] );
		$this->assertFalse( $first['done'] );
		$this->assertSame( BulkConverter::BATCH_SIZE, $first['offset'] );

		$second = $this->bulk->convertBatch( $first['offset'] );

		$this->assertSame( 3, $second['processed'] );
		$this->assertTrue( $second['done'] );
	}

	public function test_convert_batch_is_idempotent(): void {
		$postId = $this->legacyPost();

		$this->bulk->convertBatch( 0 );
		$after = get_post_field( 'post_content', $postId );

		$second = $this->bulk->convertBatch( 0 );

		$this->assertSame( 0, $second['changed'] );
		$this->assertSame( $after, get_post_field( 'post_content', $postId ) );
	}

	public function test_restore_batch_puts_the_original_urls_back(): void {
		$postId = $this->legacyPost();
		$this->bulk->convertBatch( 0 );

		$result = $this->bulk->restoreBatch( 0 );

		$this->assertSame( 1, $result['changed'] );
		$this->assertTrue( $result['done'] );
		$this->assertStringContainsString( self::AFFILIATE, get_post_field( 'post_content', $postId ) );
	}

	public function test_restore_only_visits_posts_that_have_links(): void {
		$this->legacyPost();
		$this->bulk->convertBatch( 0 );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => 'リンクなし' ) );

		$this->assertSame( 1, $this->bulk->restoreBatch( 0 )['total'] );
	}

	public function test_ajax_actions_are_registered(): void {
		$this->bulk->register();

		$this->assertNotFalse( has_action( 'wp_ajax_' . BulkConverter::ACTION_CONVERT ) );
		$this->assertNotFalse( has_action( 'wp_ajax_' . BulkConverter::ACTION_RESTORE ) );
	}
}
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter BulkConverterTest"
```

Expected: FAIL — `Class "RLT\Admin\BulkConverter" not found`

- [ ] **Step 3: 実装する**

`src/Admin/BulkConverter.php`:

```php
<?php

declare(strict_types=1);

namespace RLT\Admin;

use RLT\Data\LinkRepository;
use RLT\Frontend\PostSync;

/**
 * Converts posts published before the plugin existed, and undoes the conversion
 * across the whole site.
 *
 * Work happens in small batches driven from the browser so a large archive does
 * not hit the PHP time limit.
 */
final class BulkConverter {

	public const BATCH_SIZE     = 20;
	public const ACTION_CONVERT = 'rlt_bulk_convert';
	public const ACTION_RESTORE = 'rlt_bulk_restore';
	public const NONCE          = 'rlt_bulk';

	private PostSync $sync;
	private LinkRepository $links;

	public function __construct( ?PostSync $sync = null, ?LinkRepository $links = null ) {
		$this->links = $links ?? new LinkRepository();
		$this->sync  = $sync ?? new PostSync( $this->links );
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION_CONVERT, array( $this, 'ajaxConvert' ) );
		add_action( 'wp_ajax_' . self::ACTION_RESTORE, array( $this, 'ajaxRestore' ) );
	}

	/**
	 * @return array{offset:int, processed:int, changed:int, total:int, done:bool}
	 */
	public function convertBatch( int $offset ): array {
		$ids     = $this->candidateIds();
		$batch   = array_slice( $ids, $offset, self::BATCH_SIZE );
		$changed = 0;

		foreach ( $batch as $postId ) {
			if ( $this->sync->syncPost( $postId ) ) {
				$changed++;
			}
		}

		return $this->progress( $offset, count( $batch ), $changed, count( $ids ) );
	}

	/**
	 * @return array{offset:int, processed:int, changed:int, total:int, done:bool}
	 */
	public function restoreBatch( int $offset ): array {
		$ids     = $this->links->postIdsWithLinks();
		sort( $ids );
		$batch   = array_slice( $ids, $offset, self::BATCH_SIZE );
		$changed = 0;

		foreach ( $batch as $postId ) {
			if ( $this->sync->restorePost( $postId ) ) {
				$changed++;
			}
		}

		return $this->progress( $offset, count( $batch ), $changed, count( $ids ) );
	}

	public function ajaxConvert(): void {
		$this->respond( 'convertBatch' );
	}

	public function ajaxRestore(): void {
		$this->respond( 'restoreBatch' );
	}

	private function respond( string $method ): void {
		check_ajax_referer( self::NONCE );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '権限がありません。', 'rakuten-link-tracker' ) ), 403 );
		}

		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;

		wp_send_json_success( $this->{$method}( $offset ) );
	}

	/**
	 * Posts of the synced types, oldest first so the offset stays stable between
	 * batches.
	 *
	 * @return int[]
	 */
	private function candidateIds(): array {
		return get_posts(
			array(
				'post_type'        => $this->sync->syncedPostTypes(),
				'post_status'      => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
	}

	/**
	 * @return array{offset:int, processed:int, changed:int, total:int, done:bool}
	 */
	private function progress( int $offset, int $processed, int $changed, int $total ): array {
		$next = $offset + $processed;

		return array(
			'offset'    => $next,
			'processed' => $processed,
			'changed'   => $changed,
			'total'     => $total,
			'done'      => $next >= $total || 0 === $processed,
		);
	}
}
```

- [ ] **Step 4: `assets/admin.js` を作る**

```js
( function () {
	'use strict';

	function run( button, action ) {
		var config = window.rltBulk;
		var status = document.getElementById( 'rlt-bulk-status' );
		var bar = document.getElementById( 'rlt-bulk-bar' );

		button.disabled = true;

		function step( offset ) {
			var body = new URLSearchParams();
			body.append( 'action', action );
			body.append( '_ajax_nonce', config.nonce );
			body.append( 'offset', String( offset ) );

			fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					if ( ! json.success ) {
						status.textContent = config.failed;
						button.disabled = false;
						return;
					}

					var data = json.data;
					var percent = data.total > 0 ? Math.round( ( data.offset / data.total ) * 100 ) : 100;

					bar.style.width = percent + '%';
					status.textContent = data.offset + ' / ' + data.total;

					if ( data.done ) {
						status.textContent = config.finished.replace( '%d', String( data.total ) );
						button.disabled = false;
						return;
					}

					step( data.offset );
				} )
				.catch( function () {
					status.textContent = config.failed;
					button.disabled = false;
				} );
		}

		step( 0 );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var convert = document.getElementById( 'rlt-bulk-convert' );
		var restore = document.getElementById( 'rlt-bulk-restore' );

		if ( convert ) {
			convert.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				run( convert, 'rlt_bulk_convert' );
			} );
		}

		if ( restore ) {
			restore.addEventListener( 'click', function ( event ) {
				event.preventDefault();

				if ( ! window.confirm( window.rltBulk.confirmRestore ) ) {
					return;
				}

				run( restore, 'rlt_bulk_restore' );
			} );
		}
	} );
}() );
```

- [ ] **Step 5: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter BulkConverterTest"
```

Expected: PASS — `OK (7 tests, ...)`

- [ ] **Step 6: コミット**

```bash
git add src/Admin/BulkConverter.php assets/admin.js tests/Integration/Admin/BulkConverterTest.php
git commit -m "feat: 既存記事の一括変換と一括復元をバッチ処理で追加"
```

---

## Task 25: 設定画面とAPIキー管理

**Files:**
- Rewrite: `src/Admin/SettingsPage.php`
- Modify: `src/Admin/AdminMenu.php`
- Test: `tests/Integration/Admin/SettingsPageTest.php`

**Interfaces:**
- Consumes: `Settings`, `ApiKeyManager`, `BulkConverter`
- Produces:
  - `RLT\Admin\SettingsPage::NONCE` (string `'rlt_save_settings'`)
  - `render(): void`
  - `handleSave(bool $redirect = true): void` — `admin_post_rlt_save_settings`
  - `handleCreateKey(bool $redirect = true): void` — `admin_post_rlt_create_key`
  - `handleRevokeKey(bool $redirect = true): void` — `admin_post_rlt_revoke_key`
  - `register(): void`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Integration/Admin/SettingsPageTest.php`:

```php
<?php

namespace RLT\Tests\Integration\Admin;

use RLT\Admin\SettingsPage;
use RLT\Data\ApiKeyManager;
use RLT\Settings;
use WP_UnitTestCase;

final class SettingsPageTest extends WP_UnitTestCase {

	private SettingsPage $page;

	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( Settings::OPTION );
		ApiKeyManager::deleteAll();

		$this->page = new SettingsPage();
	}

	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();
		parent::tearDown();
	}

	private function render(): string {
		ob_start();
		$this->page->render();

		return (string) ob_get_clean();
	}

	public function test_render_shows_every_setting_field(): void {
		$html = $this->render();

		foreach ( array( 'prefix', 'hosts', 'exclude_logged_in', 'unknown_code', 'retention_days' ) as $field ) {
			$this->assertStringContainsString( 'name="' . $field, $html, "Field {$field} is missing." );
		}
	}

	public function test_render_shows_the_bulk_buttons(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'rlt-bulk-convert', $html );
		$this->assertStringContainsString( 'rlt-bulk-restore', $html );
	}

	public function test_save_persists_the_settings(): void {
		$_POST = array(
			'_wpnonce'          => wp_create_nonce( SettingsPage::NONCE ),
			'prefix'            => 'out',
			'hosts'             => "hb.afl.rakuten.co.jp\naf.rakuten.co.jp",
			'exclude_logged_in' => '1',
			'unknown_code'      => '404',
			'retention_days'    => '90',
		);

		$this->page->handleSave( false );

		$this->assertSame( 'out', Settings::get( 'prefix' ) );
		$this->assertSame( '404', Settings::get( 'unknown_code' ) );
		$this->assertSame( 90, Settings::get( 'retention_days' ) );
		$this->assertSame( array( 'hb.afl.rakuten.co.jp', 'af.rakuten.co.jp' ), Settings::hosts() );
	}

	public function test_save_without_a_valid_nonce_changes_nothing(): void {
		$_POST = array(
			'_wpnonce' => 'wrong',
			'prefix'   => 'hacked',
		);

		$this->page->handleSave( false );

		$this->assertSame( 'go', Settings::get( 'prefix' ) );
	}

	public function test_unchecked_checkbox_turns_the_setting_off(): void {
		Settings::update( array( 'exclude_logged_in' => 1 ) );

		$_POST = array(
			'_wpnonce'       => wp_create_nonce( SettingsPage::NONCE ),
			'prefix'         => 'go',
			'hosts'          => 'hb.afl.rakuten.co.jp',
			'retention_days' => '365',
			'unknown_code'   => 'home',
		);

		$this->page->handleSave( false );

		$this->assertSame( 0, Settings::get( 'exclude_logged_in' ) );
	}

	public function test_changing_the_prefix_flushes_rewrite_rules(): void {
		$flushed = false;
		add_action( 'rlt_rewrite_flushed', static function () use ( &$flushed ): void {
			$flushed = true;
		} );

		$_POST = array(
			'_wpnonce'       => wp_create_nonce( SettingsPage::NONCE ),
			'prefix'         => 'out',
			'hosts'          => 'hb.afl.rakuten.co.jp',
			'retention_days' => '365',
			'unknown_code'   => 'home',
		);

		$this->page->handleSave( false );

		// プレフィックスを変えたのに rewrite を流さないと /out/ が 404 になる。
		$this->assertTrue( $flushed );
	}

	public function test_saving_the_same_prefix_does_not_flush(): void {
		$flushed = false;
		add_action( 'rlt_rewrite_flushed', static function () use ( &$flushed ): void {
			$flushed = true;
		} );

		$_POST = array(
			'_wpnonce'       => wp_create_nonce( SettingsPage::NONCE ),
			'prefix'         => 'go',
			'hosts'          => 'hb.afl.rakuten.co.jp',
			'retention_days' => '365',
			'unknown_code'   => 'home',
		);

		$this->page->handleSave( false );

		$this->assertFalse( $flushed );
	}

	public function test_create_key_stores_a_key_and_shows_it_once(): void {
		$_POST = array(
			'_wpnonce' => wp_create_nonce( SettingsPage::NONCE ),
			'label'    => 'Looker Studio',
		);

		$this->page->handleCreateKey( false );

		$keys = ApiKeyManager::all();

		$this->assertCount( 1, $keys );
		$this->assertSame( 'Looker Studio', $keys[0]['label'] );
		$this->assertNotSame( '', get_transient( 'rlt_new_api_key' ) );
	}

	public function test_revoke_key_removes_it(): void {
		$created = ApiKeyManager::create( 'BI' );

		$_POST = array(
			'_wpnonce' => wp_create_nonce( SettingsPage::NONCE ),
			'key_id'   => $created['id'],
		);

		$this->page->handleRevokeKey( false );

		$this->assertSame( array(), ApiKeyManager::all() );
	}

	public function test_render_lists_existing_keys_without_the_secret(): void {
		$created = ApiKeyManager::create( 'BI' );

		$html = $this->render();

		$this->assertStringContainsString( 'BI', $html );
		$this->assertStringNotContainsString( $created['key'], $html );
	}

	public function test_render_shows_the_short_url_example(): void {
		$this->assertStringContainsString( Settings::shortBase(), $this->render() );
	}
}
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter SettingsPageTest"
```

Expected: FAIL — `Undefined constant RLT\Admin\SettingsPage::NONCE`

- [ ] **Step 3: 実装する**

`src/Admin/SettingsPage.php`（Task 21 で作った空実装を置き換える）:

```php
<?php

declare(strict_types=1);

namespace RLT\Admin;

use RLT\Data\ApiKeyManager;
use RLT\Plugin;
use RLT\Settings;

/**
 * Settings, API keys, and the bulk convert/restore controls.
 */
final class SettingsPage {

	public const NONCE = 'rlt_save_settings';

	private const NEW_KEY_TRANSIENT = 'rlt_new_api_key';

	public function register(): void {
		add_action( 'admin_post_rlt_save_settings', array( $this, 'handleSave' ) );
		add_action( 'admin_post_rlt_create_key', array( $this, 'handleCreateKey' ) );
		add_action( 'admin_post_rlt_revoke_key', array( $this, 'handleRevokeKey' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'この画面を表示する権限がありません。', 'rakuten-link-tracker' ) );
		}

		$settings = Settings::all();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( '楽天リンク 設定', 'rakuten-link-tracker' ) . '</h1>';

		$this->renderSettingsForm( $settings );
		$this->renderApiKeys();
		$this->renderBulkTools();
		$this->renderExportLinks();

		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function renderSettingsForm( array $settings ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="rlt_save_settings" />';
		wp_nonce_field( self::NONCE );

		echo '<table class="form-table"><tbody>';

		printf(
			'<tr><th scope="row"><label for="rlt-prefix">%s</label></th><td>
				<input type="text" id="rlt-prefix" name="prefix" class="regular-text" value="%s" />
				<p class="description">%s <code>%s</code></p></td></tr>',
			esc_html__( '短縮URLのプレフィックス', 'rakuten-link-tracker' ),
			esc_attr( (string) $settings['prefix'] ),
			esc_html__( '短縮URLはこの形になります:', 'rakuten-link-tracker' ),
			esc_html( Settings::shortBase() . 'abc123' )
		);

		printf(
			'<tr><th scope="row"><label for="rlt-hosts">%s</label></th><td>
				<textarea id="rlt-hosts" name="hosts" rows="4" class="large-text code">%s</textarea>
				<p class="description">%s</p></td></tr>',
			esc_html__( '対象ホスト', 'rakuten-link-tracker' ),
			esc_textarea( implode( "\n", (array) $settings['hosts'] ) ),
			esc_html__( '1行に1ホスト。ここに書かれたホストへのリンクだけが短縮URLに置き換わります。', 'rakuten-link-tracker' )
		);

		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="exclude_logged_in" value="1" %s /> %s</label></td></tr>',
			esc_html__( '自分のアクセスを除外', 'rakuten-link-tracker' ),
			checked( 1, (int) $settings['exclude_logged_in'], false ),
			esc_html__( 'ログイン中のユーザーのPVとクリックを記録しない', 'rakuten-link-tracker' )
		);

		printf(
			'<tr><th scope="row"><label for="rlt-unknown">%s</label></th><td>
				<select id="rlt-unknown" name="unknown_code">
					<option value="home" %s>%s</option>
					<option value="404" %s>%s</option>
				</select></td></tr>',
			esc_html__( '存在しない短縮URL', 'rakuten-link-tracker' ),
			selected( 'home', $settings['unknown_code'], false ),
			esc_html__( 'サイトトップへリダイレクト', 'rakuten-link-tracker' ),
			selected( '404', $settings['unknown_code'], false ),
			esc_html__( '404を返す', 'rakuten-link-tracker' )
		);

		printf(
			'<tr><th scope="row"><label for="rlt-retention">%s</label></th><td>
				<input type="number" id="rlt-retention" name="retention_days" min="0" value="%d" class="small-text" /> %s
				<p class="description">%s</p></td></tr>',
			esc_html__( '生ログの保持日数', 'rakuten-link-tracker' ),
			(int) $settings['retention_days'],
			esc_html__( '日', 'rakuten-link-tracker' ),
			esc_html__( '0 を指定すると無期限に保持します。', 'rakuten-link-tracker' )
		);

		echo '</tbody></table>';
		submit_button();
		echo '</form>';
	}

	private function renderApiKeys(): void {
		echo '<hr /><h2>' . esc_html__( '読み取り専用APIキー', 'rakuten-link-tracker' ) . '</h2>';
		echo '<p class="description">'
			. esc_html__( '統計の参照だけができるキーです。Looker Studio やスプレッドシート、AIツールに渡すのはこちらを使ってください。WordPress の管理権限は付きません。', 'rakuten-link-tracker' )
			. '</p>';

		$fresh = get_transient( self::NEW_KEY_TRANSIENT );

		if ( is_string( $fresh ) && '' !== $fresh ) {
			delete_transient( self::NEW_KEY_TRANSIENT );
			printf(
				'<div class="notice notice-success"><p>%s</p><p><code>%s</code></p></div>',
				esc_html__( 'キーを発行しました。この値が表示されるのはこの一度きりです。', 'rakuten-link-tracker' ),
				esc_html( $fresh )
			);
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'ラベル', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( '発行日', 'rakuten-link-tracker' ) . '</th>';
		echo '<th>' . esc_html__( '最終使用', 'rakuten-link-tracker' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		$keys = ApiKeyManager::all();

		if ( array() === $keys ) {
			echo '<tr><td colspan="4">' . esc_html__( 'まだキーがありません。', 'rakuten-link-tracker' ) . '</td></tr>';
		}

		foreach ( $keys as $key ) {
			echo '<tr>';
			echo '<td>' . esc_html( $key['label'] ) . '</td>';
			echo '<td>' . esc_html( get_date_from_gmt( $key['created_at'], 'Y-m-d H:i' ) ) . '</td>';
			echo '<td>' . esc_html( null === $key['last_used_at'] ? '—' : get_date_from_gmt( (string) $key['last_used_at'], 'Y-m-d H:i' ) ) . '</td>';
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="rlt_revoke_key" />';
			printf( '<input type="hidden" name="key_id" value="%s" />', esc_attr( $key['id'] ) );
			wp_nonce_field( self::NONCE );
			submit_button( __( '失効', 'rakuten-link-tracker' ), 'delete small', 'submit', false );
			echo '</form></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">';
		echo '<input type="hidden" name="action" value="rlt_create_key" />';
		wp_nonce_field( self::NONCE );
		printf(
			'<input type="text" name="label" class="regular-text" placeholder="%s" /> ',
			esc_attr__( '用途がわかる名前（例: Looker Studio）', 'rakuten-link-tracker' )
		);
		submit_button( __( 'キーを発行', 'rakuten-link-tracker' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	private function renderBulkTools(): void {
		echo '<hr /><h2>' . esc_html__( '既存記事の一括処理', 'rakuten-link-tracker' ) . '</h2>';

		wp_enqueue_script( 'rlt-admin', Plugin::url( 'assets/admin.js' ), array(), Plugin::VERSION, true );
		wp_add_inline_script(
			'rlt-admin',
			'window.rltBulk = ' . wp_json_encode(
				array(
					'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
					'nonce'          => wp_create_nonce( BulkConverter::NONCE ),
					'finished'       => __( '完了しました（%d 件を処理）。', 'rakuten-link-tracker' ),
					'failed'         => __( '処理に失敗しました。', 'rakuten-link-tracker' ),
					'confirmRestore' => __( 'すべての記事の本文を元の楽天URLに戻します。よろしいですか？', 'rakuten-link-tracker' ),
				)
			) . ';',
			'before'
		);

		echo '<p>';
		printf(
			'<button class="button button-primary" id="rlt-bulk-convert">%s</button> ',
			esc_html__( '既存記事をスキャンして変換', 'rakuten-link-tracker' )
		);
		printf(
			'<button class="button" id="rlt-bulk-restore">%s</button>',
			esc_html__( 'すべて元の楽天URLに戻す', 'rakuten-link-tracker' )
		);
		echo '</p>';

		echo '<div class="rlt-progress"><div class="rlt-progress-bar" id="rlt-bulk-bar"></div></div>';
		echo '<p id="rlt-bulk-status" class="description"></p>';
	}

	private function renderExportLinks(): void {
		echo '<hr /><h2>' . esc_html__( 'CSVエクスポート', 'rakuten-link-tracker' ) . '</h2>';
		echo '<p>';
		printf(
			'<a class="button" href="%s">%s</a> ',
			esc_url( rest_url( 'rlt/v1/export/clicks' ) ),
			esc_html__( 'クリックログ', 'rakuten-link-tracker' )
		);
		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( rest_url( 'rlt/v1/export/views' ) ),
			esc_html__( 'PVログ', 'rakuten-link-tracker' )
		);
		echo '</p>';
		echo '<p class="description">'
			. esc_html__( '既定では直近28日分です。?from=2026-01-01&to=2026-01-31 のように期間を指定できます。', 'rakuten-link-tracker' )
			. '</p>';
	}

	public function handleSave( bool $redirect = true ): void {
		if ( ! $this->authorised() ) {
			return;
		}

		$oldPrefix = Settings::prefix();

		$hosts = isset( $_POST['hosts'] )
			? preg_split( '/\R/', sanitize_textarea_field( wp_unslash( (string) $_POST['hosts'] ) ) )
			: array();

		Settings::update(
			array(
				'prefix'            => isset( $_POST['prefix'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['prefix'] ) ) : 'go',
				'hosts'             => array_filter( array_map( 'trim', (array) $hosts ) ),
				'exclude_logged_in' => empty( $_POST['exclude_logged_in'] ) ? 0 : 1,
				'unknown_code'      => isset( $_POST['unknown_code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['unknown_code'] ) ) : 'home',
				'retention_days'    => isset( $_POST['retention_days'] ) ? absint( wp_unslash( $_POST['retention_days'] ) ) : 365,
			)
		);

		// A changed prefix means the old rewrite rule is stale; without a flush
		// every new short URL would 404.
		if ( Settings::prefix() !== $oldPrefix ) {
			( new \RLT\Frontend\RedirectHandler() )->addRewriteRule();
			flush_rewrite_rules();
			do_action( 'rlt_rewrite_flushed' );
		}

		$this->finish( $redirect, 'saved' );
	}

	public function handleCreateKey( bool $redirect = true ): void {
		if ( ! $this->authorised() ) {
			return;
		}

		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : '';

		if ( '' === $label ) {
			$label = __( '名称未設定', 'rakuten-link-tracker' );
		}

		$created = ApiKeyManager::create( $label );

		// Shown once on the next page load, then discarded.
		set_transient( self::NEW_KEY_TRANSIENT, $created['key'], 5 * MINUTE_IN_SECONDS );

		$this->finish( $redirect, 'key-created' );
	}

	public function handleRevokeKey( bool $redirect = true ): void {
		if ( ! $this->authorised() ) {
			return;
		}

		$id = isset( $_POST['key_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['key_id'] ) ) : '';
		ApiKeyManager::revoke( $id );

		$this->finish( $redirect, 'key-revoked' );
	}

	private function authorised(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		return isset( $_POST['_wpnonce'] )
			&& (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), self::NONCE );
	}

	private function finish( bool $redirect, string $notice ): void {
		if ( ! $redirect ) {
			return;
		}

		wp_safe_redirect( AdminMenu::url( AdminMenu::SLUG_SETTINGS, array( 'notice' => $notice ) ) );
		exit;
	}
}
```

- [ ] **Step 4: `AdminMenu::register()` に繋ぐ**

```php
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addPages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		( new LinkDetailPage() )->register();
		( new SettingsPage() )->register();
		( new BulkConverter() )->register();
	}
```

- [ ] **Step 5: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter SettingsPageTest"
```

Expected: PASS — `OK (11 tests, ...)`

- [ ] **Step 6: コミット**

```bash
git add src/Admin/SettingsPage.php src/Admin/AdminMenu.php tests/Integration/Admin/SettingsPageTest.php
git commit -m "feat: 設定画面・APIキー管理・一括処理UIを追加"
```

---

## Task 26: アンインストール（本文復元 → 削除）

順序が本質。テーブルを先に消すと復元できなくなる。

**Files:**
- Create: `uninstall.php`
- Modify: `src/Installer.php`
- Test: `tests/Integration/UninstallTest.php`

**Interfaces:**
- Consumes: `PostSync::restorePost()`, `LinkRepository::postIdsWithLinks()`, `Installer::dropTables()`
- Produces:
  - `RLT\Installer::uninstall(): void`
  - `RLT\Installer::restoreAllPosts(): int`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Integration/UninstallTest.php`:

```php
<?php

namespace RLT\Tests\Integration;

use RLT\Data\ApiKeyManager;
use RLT\Data\LinkRepository;
use RLT\Frontend\PostSync;
use RLT\Installer;
use RLT\Settings;
use WP_UnitTestCase;

final class UninstallTest extends WP_UnitTestCase {

	private const AFFILIATE = 'https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=x';

	protected function setUp(): void {
		parent::setUp();
		( new PostSync() )->register();
	}

	protected function tearDown(): void {
		// 他のテストが動くようにテーブルを作り直す。
		Installer::createTables();
		parent::tearDown();
	}

	private function postWithLink(): int {
		return self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<a href="' . self::AFFILIATE . '">ホテル</a>',
			)
		);
	}

	public function test_restore_all_posts_puts_the_original_urls_back(): void {
		$postId = $this->postWithLink();

		$this->assertStringNotContainsString( self::AFFILIATE, get_post_field( 'post_content', $postId ) );

		$restored = Installer::restoreAllPosts();

		$this->assertSame( 1, $restored );
		$this->assertStringContainsString( self::AFFILIATE, get_post_field( 'post_content', $postId ) );
	}

	public function test_uninstall_restores_before_dropping_tables(): void {
		$postId = $this->postWithLink();

		Installer::uninstall();

		// 先にテーブルを消していたら、この本文は短縮URLのまま取り残される。
		$this->assertStringContainsString( self::AFFILIATE, get_post_field( 'post_content', $postId ) );
	}

	public function test_uninstall_drops_the_tables(): void {
		global $wpdb;

		$this->postWithLink();

		Installer::uninstall();

		foreach ( array( Installer::linksTable(), Installer::clicksTable(), Installer::viewsTable() ) as $table ) {
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		}
	}

	public function test_uninstall_removes_the_options(): void {
		Settings::update( array( 'prefix' => 'out' ) );
		ApiKeyManager::create( 'BI' );
		Settings::salt();

		Installer::uninstall();

		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_option( Settings::SALT_OPTION ) );
		$this->assertFalse( get_option( ApiKeyManager::OPTION ) );
		$this->assertFalse( get_option( Installer::VERSION_OPTION ) );
	}

	public function test_uninstall_removes_the_capability(): void {
		Installer::uninstall();

		$this->assertFalse( get_role( 'administrator' )->has_cap( Installer::CAPABILITY ) );

		Installer::addCapabilities();
	}

	public function test_uninstall_removes_the_backup_post_meta(): void {
		$postId = $this->postWithLink();

		$this->assertNotSame( '', get_post_meta( $postId, PostSync::META_ORIGINAL, true ) );

		Installer::uninstall();

		$this->assertSame( '', get_post_meta( $postId, PostSync::META_ORIGINAL, true ) );
	}
}
```

- [ ] **Step 2: テストを走らせて失敗を確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter UninstallTest"
```

Expected: FAIL — `Call to undefined method RLT\Installer::restoreAllPosts()`

- [ ] **Step 3: `Installer` に復元とアンインストールを追加する**

`src/Installer.php` の `removeCapabilities()` の**後ろ**に追加する。ファイル冒頭の `namespace RLT;` の下に `use RLT\Data\ApiKeyManager;`、`use RLT\Data\LinkRepository;`、`use RLT\Frontend\PostSync;` を足すこと。

```php
	/**
	 * Put the original affiliate URLs back into every post the plugin touched.
	 *
	 * @return int Number of posts changed.
	 */
	public static function restoreAllPosts(): int {
		$links    = new LinkRepository();
		$sync     = new PostSync( $links );
		$restored = 0;

		foreach ( $links->postIdsWithLinks() as $postId ) {
			if ( $sync->restorePost( $postId ) ) {
				$restored++;
			}
		}

		return $restored;
	}

	/**
	 * Remove every trace of the plugin.
	 *
	 * Restoring the post content has to happen FIRST: once the links table is
	 * gone there is no way to map a short URL back to its affiliate URL, and
	 * every /go/ link in every post would be permanently dead.
	 */
	public static function uninstall(): void {
		global $wpdb;

		self::restoreAllPosts();

		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => PostSync::META_ORIGINAL ) );

		self::dropTables();
		self::removeCapabilities();

		Cron::unschedule();

		delete_option( Settings::OPTION );
		delete_option( Settings::SALT_OPTION );
		delete_option( ApiKeyManager::OPTION );
		delete_option( self::VERSION_OPTION );
		delete_transient( 'rlt_new_api_key' );
	}
```

- [ ] **Step 4: `uninstall.php` を作る**

```php
<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * @package RLT
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

if ( ! defined( 'RLT_PLUGIN_FILE' ) ) {
	define( 'RLT_PLUGIN_FILE', __DIR__ . '/rakuten-link-tracker.php' );
}

\RLT\Installer::uninstall();
```

- [ ] **Step 5: テストが通ることを確認する**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter UninstallTest"
```

Expected: PASS — `OK (6 tests, ...)`

- [ ] **Step 6: コミット**

```bash
git add uninstall.php src/Installer.php tests/Integration/UninstallTest.php
git commit -m "feat: アンインストール時に本文を復元してから全データを削除"
```

---

## Task 27: README と全体の通し確認

**Files:**
- Create: `README.md`
- Test: 既存の全テストスイート

- [ ] **Step 1: 全テストを通す**

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-unit.xml.dist"
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist"
```

Expected: 両方とも `OK`。1件でも失敗したらここで止めて直す。

- [ ] **Step 2: 実際のブラウザで一連の流れを確認する**

```powershell
npx wp-env run cli "wp plugin activate rakuten-link-tracker"
npx wp-env run cli "wp rewrite structure '/%postname%/'"
npx wp-env run cli "wp rewrite flush"
npx wp-env run cli "wp post create --post_title='テストホテル記事' --post_status=publish --porcelain --post_content='<p><a href=\"https://hb.afl.rakuten.co.jp/hgc/abc123/?pc=https%3A%2F%2Ftravel.rakuten.co.jp%2F\">ホテル雅叙園東京</a></p><img src=\"https://hb.afl.rakuten.co.jp/hsc/abc123/?me_id=1\" width=\"1\" height=\"1\">'"
```

続いて以下を目視で確認する。

1. `wp post get <ID> --field=post_content` で `href` が `/go/xxxxxx` になり、`img` の `src` が**元のまま**であること
2. ブラウザで記事を開く（ログアウト状態で）→ 短縮URLをクリック → 楽天に飛ぶこと
3. `http://localhost:8888/wp-admin/admin.php?page=rakuten-link-tracker` にPVとクリックが1件ずつ計上されていること
4. リンク一覧 → 詳細で、リファラとデバイスの内訳が出ること
5. 設定でプレフィックスを `out` に変えて保存 → `/out/xxxxxx` でリダイレクトできること（`/go/` は404になる）
6. 設定でAPIキーを発行 → `curl -H "X-RLT-Key: <key>" http://localhost:8888/wp-json/rlt/v1/stats/report` がJSONを返すこと
7. 「すべて元の楽天URLに戻す」→ 本文が元の楽天URLに戻ること

- [ ] **Step 3: `README.md` を書く**

```markdown
# Rakuten Link Tracker

楽天アフィリエイトURLを自サイトの短縮URLに自動で置き換え、クリックと記事PVを計測してCTRを可視化する WordPress プラグインです。

楽天アフィリエイトは分析情報をほとんど提供しないため、どの記事・どのホテルが実際に稼いでいるのかを自前で把握することを目的にしています。

## できること

- 投稿を保存すると、本文中の楽天アフィリエイトURLが `https://example.com/go/abc123` に自動で置き換わる
- 短縮URLのクリックを計測（日時・記事・リファラ・デバイス・ユニーク数）
- 記事のPVを計測し、CTR（クリック ÷ PV）を算出
- 管理画面のダッシュボード、リンク一覧、記事別レポート
- 統計を読み取れる REST API と CSV エクスポート
- 既存記事の一括変換と、いつでも元に戻せる一括復元

## プライバシー

**IPアドレスは一切保存しません。** 訪問者の識別には `sha256(IP + UA + 日替わりソルト)` のハッシュだけを使い、ソルトは毎日入れ替わります。そのため翌日以降は同一訪問者を追跡できず、「ユニーク」は日別ユニークとして数えられます。

## 動作要件

- WordPress 6.0 以上
- PHP 8.0 以上

## インストール

1. このディレクトリを `wp-content/plugins/rakuten-link-tracker` に置く
2. `composer install --no-dev` を実行する（オートローダの生成に必要）
3. 管理画面でプラグインを有効化する

## キャッシュの設定（重要）

`/go/` へのリクエストがキャッシュされると、PHPが実行されずクリックが記録されません。プラグインは `Cache-Control: no-store` と `DONOTCACHEPAGE` を送出しますが、以下は手動で除外設定が必要です。

- **WP Super Cache / W3 Total Cache / LiteSpeed Cache**: 除外URLに `/go/*` を追加
- **Cloudflare**: キャッシュルールで `/go/*` を Bypass に設定
- **Nginx の fastcgi_cache**: `location ^~ /go/ { set $skip_cache 1; }` を追加

プレフィックスを変更した場合は、そのパスに読み替えてください。

## REST API

ベースURL: `https://example.com/wp-json/rlt/v1`

| メソッド | パス | 内容 |
|---|---|---|
| GET | `/stats/summary` | 総クリック・PV・CTR・ユニーク数（前期間比つき） |
| GET | `/stats/daily` | 日別の時系列 |
| GET | `/stats/posts` | 記事別 PV・クリック・CTR |
| GET | `/stats/links` | リンク別 |
| GET | `/stats/links/{code}` | 個別リンクの詳細（日別・リファラ・デバイス） |
| GET | `/stats/report` | 主要指標と所見をまとめた要約（AIに渡す用） |
| GET | `/links` | リンク一覧 |
| PATCH | `/links/{code}` | 遷移先URL・ラベル・状態の変更 |
| GET | `/export/clicks` | クリック生ログのCSV |
| GET | `/export/views` | PV生ログのCSV |

共通パラメータ: `from` / `to`（`YYYY-MM-DD`）、`include_bots`、`limit`、`orderby`、`post_id`

### 認証

**方法1: WordPress のアプリケーションパスワード**（読み書き両方）

```bash
curl -u "user:xxxx xxxx xxxx xxxx xxxx xxxx" \
  "https://example.com/wp-json/rlt/v1/stats/summary"
```

**方法2: 読み取り専用APIキー**（参照のみ。設定画面で発行）

```bash
curl -H "X-RLT-Key: rlt_xxxxxxxx" \
  "https://example.com/wp-json/rlt/v1/stats/report"
```

読み取り専用キーは `PATCH` を拒否します。Looker Studio やスプレッドシート、AIツールに渡すのはこちらを使ってください。

## 開発

```powershell
docker run --rm -v "${PWD}:/app" -w /app composer:2 install
npx wp-env start

# 単体テスト（WordPress 非依存）
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-unit.xml.dist"

# 統合テスト
npx wp-env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker "vendor/bin/phpunit -c phpunit-integration.xml.dist"
```

## アンインストールについて

プラグインを**無効化**してもデータと本文はそのまま残り、再有効化すれば元通り動きます。

プラグインを**削除**すると、まず全記事の本文が元の楽天URLに復元され、そのあとテーブルとオプションが削除されます。この順序のおかげでリンク切れは残りません。

## フック

| フック | 種類 | 用途 |
|---|---|---|
| `rlt_client_ip` | filter | プロキシ配下でクライアントIPを差し替える |
| `rlt_synced_post_types` | filter | 変換対象の投稿タイプ（既定 `post`, `page`） |
| `rlt_before_track_click` | action | クリック記録の直前 |
```

- [ ] **Step 4: コミット**

```bash
git add README.md
git commit -m "docs: READMEにセットアップ・API・キャッシュ除外手順を追加"
```

---

## Self-Review メモ（計画作成者による確認結果）

仕様書の各節と、それを実装するタスクの対応:

| 仕様書の節 | 対応タスク |
|---|---|
| §5 アーキテクチャ | Task 1（骨組み）、以降の各タスクで肉付け |
| §6.1 `rlt_links` | Task 8 |
| §6.2 `rlt_clicks` / §6.3 `rlt_views` | Task 8 |
| §6.4 プライバシー | Task 4（VisitorHash）、Task 10（RequestContext）、Task 19（ソルト更新） |
| §6.5 post meta | Task 11（保存）、Task 26（削除） |
| §7.1 LinkExtractor | Task 5 |
| §7.2 ContentRewriter | Task 6 |
| §7.3 CodeGenerator | Task 2 |
| §7.4 PostSync | Task 11 |
| §7.5 RedirectHandler | Task 12 |
| §7.6 PVビーコン | Task 13 |
| §7.7 BotFilter | Task 3 |
| §8 読み取りAPI | Task 17（統計）、Task 18（CSV）、Task 16（APIキー） |
| §9 管理画面 | Task 20〜25 |
| §10 ライフサイクル | Task 8（有効化）、Task 19（cron）、Task 26（アンインストール） |
| §11 リスクと対策 | Task 12（no-store）、Task 5/6（img保護）、Task 24（一括復元）、Task 26（自動復元） |
| §12 テスト | 全タスクがTDDで進む。Task 27 で通し確認 |
| §13/§14 フェーズ2・3 | 意図的に対象外。テーブル・API とも追加のみで接続できる形になっている |

仕様書に書かれていて計画に無い項目はない。
