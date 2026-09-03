# 楽天アフィリエイト 短縮URL・クリック計測プラグイン 設計書

- 日付: 2026-09-03
- プラグイン名（仮）: `rakuten-link-tracker`
- 接頭辞: `rlt` / テーブル接頭辞: `wp_rlt_`
- 対象フェーズ: **フェーズ1**

## 1. 背景と目的

外部ツールから WordPress REST API 経由で記事を投稿しており、本文には楽天トラベルのアフィリエイトリンクが含まれる。楽天アフィリエイトは分析情報をほとんど提供しないため、以下を自前で実現する。

1. 楽天アフィリエイトURLを自サイト内の短縮URLに置き換える
2. 短縮URLのクリックを計測する（件数だけでなく、いつ・どの記事から・どんな環境か）
3. 記事のPVを計測し、クリック率（CTR）を算出する
4. 集計データを外部（投稿ツール／LLM／BI／CSV）から読み取れるAPIを提供する

## 2. スコープ

### フェーズ1（本設計書の範囲）

短縮URL発行・本文自動置換・クリック計測・PV計測・CTR算出・管理ダッシュボード・読み取りAPI・CSVエクスポート

### フェーズ2（本設計書の範囲外・接続点のみ定義）

Google Search Console 連携と改善候補記事の抽出

### フェーズ3（本設計書の範囲外・接続点のみ定義）

楽天アフィリエイトの成果レポートCSV取り込みと時刻ベースの推定紐付け

### 明示的な非対象（YAGNI）

- 日次集計ロールアップテーブル（月数百クリック規模では不要。生ログのクエリ集計で足りる）
- A/Bテスト機能
- 楽天以外のASP対応
- マルチサイト対応

## 3. 前提と規模

- 規模: 月数千PV・数百クリック
- 生ログを全件保持して都度集計する方式で十分
- 投稿は外部ツールから REST API 経由（`save_post` は通常投稿と同様に発火する）

## 4. 主要な設計判断

| 論点 | 決定 | 理由 |
|---|---|---|
| 短縮URLの発行タイミング | プラグインが `save_post` で自動検出・発行 | 投稿ツール側の改修が不要 |
| 短縮URLの形式 | 同一サイトの `/go/{code}`（rewrite rule、プレフィックス変更可） | 追加ドメイン不要 |
| 本文の扱い | **本文を実際に書き換える**＋バックアップと復元機能 | RSS・AMP・独自テーマ出力など `the_content` を通らない経路でも確実に計測できる。取りこぼしは致命的 |
| 発行粒度 | 「楽天URL × 記事」ごとに1コード | 記事別の成績とホテル別の人気の両方が見える |
| PV計測 | 自前JSビーコン（REST） | ページキャッシュ／CDN環境でも正しく数えられ、クリックと同一DBに入るためCTRが即座に出る |
| リダイレクト | **302 Found** | 301はブラウザにキャッシュされ2回目以降が計測できない |
| IPアドレス | **保存しない**（日替わりソルト付きハッシュのみ） | 個人情報保護法・GDPR上の負担を最小化。ユニーク判定は当日中のみ可能というトレードオフを受け入れる |
| グラフ描画 | 依存ライブラリなしのインラインSVG | プラグイン肥大化と、CDN遮断環境での破綻を避ける |
| 訪問者ハッシュの計算コスト | **SHA-256 のまま。PBKDF2 や Argon2 は採用しない** | この関数はアフィリエイトのリダイレクト経路で毎回走る。メモリハードなKDFはそこに数十〜数百msを足すため、収益に直結する経路を遅くしてまで得る利益がない。DBとソルトの両方を奪われた攻撃者にはIP逆引きされうるが、そのトレードオフを受け入れる（**利用者が明示的に選択**）|

### リダイレクト経路のレイテンシ（実測・Task 13 完了時点）

`/go/{code}` に**計測可能なオーバーヘッドはない**。プラグインが足すのはコード解決1回・重複チェック1回・INSERT1回で、いずれもインデックス済み。

| リクエスト種別 | 平均 | 最大 |
|---|---|---|
| 記事ページ | 449 ms | 1458 ms |
| `/go/` リダイレクト | **409 ms** | 907 ms |
| WordPress標準の404 | 951 ms | 1394 ms |

（wp-env / Docker on Windows で各10回。実運用のホスティングではいずれも大幅に速くなる）

**この経路に同期処理を足す変更は、必ず再計測してから入れること。**

## 5. アーキテクチャ

計測の中核ロジックは WordPress に依存しない純粋クラスとして切り出し、単体テスト可能にする。WordPress のフックはそれらを繋ぐ薄い層に閉じ込める。

```
rakuten-link-tracker/
  rakuten-link-tracker.php      ブートストラップのみ
  uninstall.php                 本文復元 → テーブル削除
  includes/
    class-plugin.php            起動とフック登録
    class-installer.php         dbDelta スキーマ、DBバージョン管理
    class-link-extractor.php    本文 → 楽天URLの配列              [純粋]
    class-content-rewriter.php  本文の置換／復元                   [純粋]
    class-code-generator.php    短縮コード生成                     [純粋]
    class-bot-filter.php        UA判定                             [純粋]
    class-link-repository.php   rlt_links の CRUD
    class-event-repository.php  clicks/views の記録と集計クエリ
    class-post-sync.php         save_post → 抽出・発行・置換
    class-redirect-handler.php  /go/{code} の解決とリダイレクト
    class-beacon-controller.php REST: PVビーコン受信
    class-stats-controller.php  REST: 読み取りAPI
    class-api-key-manager.php   読み取り専用APIキーの発行・検証
    class-settings.php          オプション管理
    class-cron.php              ログ削除・ソルト更新
  admin/
    class-admin-menu.php
    class-dashboard-page.php
    class-links-list-table.php
    class-link-detail-page.php
    class-posts-report-page.php
    class-settings-page.php
    class-bulk-converter.php    既存記事の一括変換／一括復元
    class-svg-chart.php         インラインSVG描画
  assets/
    beacon.js
    admin.css / admin.js
  tests/
```

## 6. データモデル

### 6.1 `wp_rlt_links`

| 列 | 型 | 内容 |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | PK |
| `code` | VARCHAR(16) | 短縮コード（UNIQUE） |
| `target_url` | TEXT | 元の楽天アフィリエイトURL |
| `url_hash` | CHAR(40) | `sha1(target_url)` |
| `post_id` | BIGINT UNSIGNED | 掲載記事（0 = 未紐付け） |
| `label` | VARCHAR(255) | ホテル名など。自動抽出＋手動編集可 |
| `status` | TINYINT | 1=active / 0=archived |
| `created_at` / `updated_at` | DATETIME | |

制約: `UNIQUE KEY code (code)` / `UNIQUE KEY url_post (url_hash, post_id)` / `KEY post_id (post_id)`

`UNIQUE(url_hash, post_id)` が「楽天URL × 記事ごとに1コード」という発行粒度を担保する。

### 6.2 `wp_rlt_clicks`

| 列 | 型 | 内容 |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK |
| `link_id` | BIGINT UNSIGNED | |
| `post_id` | BIGINT UNSIGNED | 冗長保持（記事別集計の高速化） |
| `clicked_at` | DATETIME | UTC |
| `visitor_hash` | CHAR(64) | `sha256(IP + UA + 日替わりソルト)` |
| `referer` | VARCHAR(255) | |
| `device` | TINYINT | 0=unknown / 1=desktop / 2=mobile / 3=tablet |
| `is_bot` | TINYINT | |

索引: `KEY link_time (link_id, clicked_at)` / `KEY post_time (post_id, clicked_at)`

### 6.3 `wp_rlt_views`

| 列 | 型 | 内容 |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK |
| `post_id` | BIGINT UNSIGNED | |
| `viewed_at` | DATETIME | UTC |
| `visitor_hash` | CHAR(64) | |
| `referer` | VARCHAR(255) | |
| `device` | TINYINT | |
| `is_bot` | TINYINT | |

索引: `KEY post_time (post_id, viewed_at)`

### 6.4 プライバシー

IPアドレスは一切保存せず、`visitor_hash = sha256(IP + UA + 日替わりソルト)` のみを保存する。ソルトは日次cronで更新するため、同一訪問者の識別は当日中しかできず、翌日以降の追跡は不可能。ユニーククリック／ユニークPVは「日別ユニーク」として算出する。

### 6.5 post meta

- `_rlt_original_content` — 置換前の本文（1世代のみ保持）。**単一投稿の緊急ロールバック用**

一括復元とアンインストール時の復元は、post meta ではなく `rlt_links` の `code → target_url` マップを使って `ContentRewriter::restore()` で行う。meta は直近1回分しか持たないため、複数回保存された投稿では信頼できないからである。

## 7. コンポーネント仕様

### 7.1 LinkExtractor（純粋）

**入力**: 本文HTML、対象ホストの配列
**出力**: `[{ url, label, offset }]` の配列

- `href` 属性内のURLのみを対象とする
- **`<img>` の `src` は対象外**。楽天のバナーHTMLに含まれる計測用画像を壊さないため
- 対象ホスト既定値: `hb.afl.rakuten.co.jp`, `af.rakuten.co.jp`（設定で追加可）
- 既に自サイトの `/{prefix}/` になっているURLは検出しない（冪等性）
- `label` は `<a>` のテキスト → `<img>` の `alt` → URLの一部 の優先順で決定
- シングルクォート／ダブルクォート、属性順序の揺れに対応

### 7.2 ContentRewriter（純粋）

- `rewrite(content, map)` — 元URL → 短縮URL に置換
- `restore(content, map)` — 短縮URL → 元URL に復元
- 冪等であること（2回適用しても結果が変わらない）
- `rewrite` → `restore` のラウンドトリップで元に戻ること

### 7.3 CodeGenerator（純粋）

- 6文字。文字集合は英数小文字から紛らわしい文字（`0` `o` `1` `l` `i`）を除いたもの
- 衝突時は UNIQUE 制約違反を検知して再生成（最大10回）

### 7.4 PostSync

`save_post`（priority 20）で発火。リビジョン・オートセーブ・自動下書き・ゴミ箱はスキップ。

1. LinkExtractor で楽天URLを抽出
2. 各URLについて `(url_hash, post_id)` で既存リンクを検索。無ければコードを発行して `rlt_links` に挿入
3. `_rlt_original_content` に置換前の本文を保存
4. ContentRewriter で置換した本文を **`$wpdb->update` で直接書き込み、`clean_post_cache()` を呼ぶ**

`wp_update_post()` を使わないことが重要。使うと `save_post` が再発火して無限ループになり、リビジョンも増殖する。`$wpdb->update` + `clean_post_cache()` なら両方を回避できる。

本文からリンクが消えた場合、対応する `rlt_links` 行は削除せず `status=archived` にする（過去のクリックログを孤児にしないため、また短縮URLが外部に共有されている可能性があるため）。

### 7.5 RedirectHandler

- `init` で `add_rewrite_rule` により `^go/([A-Za-z0-9]{4,16})/?$` を `index.php?rlt_code=$matches[1]` へ（優先度 top）
- プレフィックスは設定で変更可。変更時に `flush_rewrite_rules()`
- `template_redirect` で処理する:
  1. `DONOTCACHEPAGE` を定義
  2. ヘッダ送出: `Cache-Control: no-store, private` / `X-Robots-Tag: noindex, nofollow`
  3. コードから `target_url` を解決
  4. **try/catch 内で**クリックを記録
  5. `wp_redirect(target_url, 302)` して `exit`
- **記録が失敗してもリダイレクトは必ず実行する。** 計測のためにアフィリエイト収益を落としてはならない
- 未知のコード → サイトトップへ302（設定で404に変更可）
- HEAD リクエストは記録せずリダイレクトのみ
- 重複排除: 同一 `visitor_hash` × `link_id` が5秒以内なら記録しない

### 7.6 PVビーコン

- `wp_enqueue_scripts` で、`is_singular()` かつ**その記事に active なリンクが1件以上ある場合のみ** `beacon.js` を読み込む
- `navigator.sendBeacon` で `POST /wp-json/rlt/v1/view` に `post_id` を送信
- **nonce は使わない。** ページキャッシュ環境では nonce が陳腐化して計測が丸ごと壊れるため
- 代替の検証: ①`post_id` の実在確認 ②Origin/Referer が自サイトか ③重複排除（同一 `visitor_hash` × `post_id` が30分以内）④ボット判定
- ログイン中ユーザーは既定で除外（設定可）

### 7.7 BotFilter（純粋）

UA に `bot` / `crawler` / `spider` / `slurp` / `facebookexternalhit` / `headlesschrome` / `preview` 等を含むものをボットと判定する。

**除外せず `is_bot=1` として記録し、表示時にフィルタする。** 判定基準を後から見直しても過去データを失わないため。

## 8. 読み取りAPI（名前空間 `rlt/v1`）

| メソッド / パス | 内容 |
|---|---|
| `GET /stats/summary` | 総クリック / 総PV / CTR / ユニーク数 |
| `GET /stats/daily` | 日別時系列（PV・クリック） |
| `GET /stats/posts` | 記事別 PV・クリック・CTR |
| `GET /stats/links` | リンク別（`post_id` で絞り込み可） |
| `GET /stats/links/{code}` | 個別詳細（日別・リファラ内訳・デバイス内訳） |
| `GET /stats/report` | LLM向け: 主要指標＋所見を1レスポンスに集約 |
| `GET /links` | リンク一覧 |
| `PATCH /links/{code}` | `target_url` / `label` / `status` の変更 |
| `GET /export/clicks.csv` | クリック生ログのCSV |
| `GET /export/views.csv` | PV生ログのCSV |

共通パラメータ: `from`, `to`（ISO8601日付）, `limit`, `orderby`, `include_bots`
レスポンスの日時は ISO8601。タイムゾーンはサイト設定に従い、レスポンスに明示する。

### 認証（2系統）

1. **WordPress 標準**（Application Password / Cookie+nonce）— 独自権限 `rlt_view_stats`（既定で administrator と editor に付与）。投稿ツールは既存の認証をそのまま使える
2. **読み取り専用APIキー** — `X-RLT-Key` ヘッダ。管理画面で発行・失効でき、最終使用日時を表示。統計参照のみ可能で `PATCH` は不可。Looker Studio や LLM に渡すのはこちら。WordPress の管理権限を外部に渡さずに済む

## 9. 管理画面

トップレベルメニュー「楽天リンク」。

1. **ダッシュボード** — 期間セレクタ（7日／28日／90日／今月／先月／カスタム）、サマリーカード（総PV・総クリック・CTR・ユニーククリック、前期間比つき）、日別推移グラフ（2系列）、上位リンクTOP10、上位記事TOP10
2. **リンク一覧**（`WP_List_Table`） — コード／ラベル／掲載記事／クリック数／CTR／最終クリック／状態。検索・記事フィルタ・並び替え。行アクション: 短縮URLをコピー／詳細／遷移先を編集／アーカイブ
3. **リンク詳細** — 日別クリック、リファラ内訳、デバイス内訳
4. **記事別レポート** — PV／クリック／CTR／リンク数。CTR昇順で改善余地のある記事が上に来る
5. **設定** — プレフィックス、対象ホスト、ログインユーザー除外、ボット表示、ログ保持日数、未知コードの挙動、APIキー管理、CSVエクスポート、**既存記事の一括変換**（AJAXで20件ずつ・進捗バー）、**一括復元**

グラフはインラインSVGで自前描画する。

## 10. ライフサイクル

- **有効化** — `dbDelta` でテーブル作成、rewrite フラッシュ、既定オプション投入、`rlt_view_stats` 権限付与
- **無効化** — rewrite フラッシュと cron 解除のみ。**データと本文は保持**（再有効化で元通り動く）
- **アンインストール**（`uninstall.php`） — ①全投稿の本文を元の楽天URLへ**復元してから** ②テーブル削除 ③オプション・権限削除。順序が逆だと復元不能になるため、この順序が本質
- **DBマイグレーション** — `rlt_db_version` オプションで管理
- **日次 cron** — 保持期間（既定365日、0=無期限）を過ぎた生ログの削除、`visitor_hash` 用ソルトの更新

## 11. リスクと対策

| リスク | 対策 |
|---|---|
| `/go/` がキャッシュされ計測漏れ | `no-store` ＋ `DONOTCACHEPAGE`、README にキャッシュプラグイン／CDNの除外手順を明記 |
| 正規表現による本文破壊 | `img` の `src` を非対象化、置換前バックアップ、一括復元ボタン、単体テストで担保 |
| プラグイン削除でリンク切れ | アンインストール時に本文を自動復元 |
| 楽天のリンク先が消える | リンク一覧から `target_url` を編集可能 |
| 自分のクリック・PVが混ざる | ログインユーザー除外（既定ON） |
| 短縮コードの衝突 | UNIQUE制約＋再生成リトライ |
| DB書き込み失敗でリダイレクトが止まる | 記録処理を try/catch で隔離し、必ずリダイレクトする |

## 12. テスト

### 単体テスト（PHPUnit、WordPress非依存）— 必須

- **LinkExtractor** — 通常リンク／`img` の `src` を拾わないこと／変換済みURLを再処理しないこと／楽天以外を無視／複数リンク／属性順序とクォートの揺れ
- **ContentRewriter** — 置換の正確さ／冪等性／`rewrite`→`restore` のラウンドトリップ
- **CodeGenerator** — 文字集合、紛らわしい文字の除外、長さ
- **BotFilter** — 既知のボットUAと通常UAの判別

### 統合テスト（wp-env + `WP_UnitTestCase`）

- REST 投稿 → リンク発行 → 本文置換が行われる
- 再保存してもコードが変わらない（冪等）
- `/go/{code}` が302を返し、クリックが1件記録される
- 未知コードの挙動
- 重複排除が効く
- APIの認証と権限（読み取り専用APIキーで `PATCH` が拒否されること）
- アンインストール時に本文が復元される

### 手動確認

実際に外部ツールからREST投稿 → 記事表示 → クリック → ダッシュボードに反映されることを確認する。

## 13. フェーズ2への接続点（設計のみ、本フェーズでは実装しない）

Search Console 連携は **サービスアカウント方式**を採る。管理画面からサービスアカウントのJSONキーをアップロードし、そのメールアドレスを Search Console のプロパティに「制限付きユーザー」として追加する運用。OAuth同意画面フローの実装が不要になる。

- 新テーブル `wp_rlt_search_console`（`date` / `post_id` / `query` / `impressions` / `clicks` / `position`）を日次cronで取得
- フェーズ1のAPIに `GET /stats/opportunities` を追加し、改善候補記事を返す。判定軸:
  1. 平均掲載順位 11〜20位かつ表示回数が多い記事 — あと一歩で1ページ目
  2. 表示回数は多いが検索CTRが低い記事 — タイトル・ディスクリプション改善
  3. 検索流入は多いがアフィリエイトCTRが低い記事 — リンク配置・訴求改善
- フェーズ1のテーブルには手を入れずに追加できる

## 14. フェーズ3への接続点（設計のみ、本フェーズでは実装しない）

楽天アフィリエイトのリンクには任意のカスタム識別子（サブID）を埋め込めないため、成果とクリックの確定的な紐付けは原理的に不可能。成果レポートCSVをアップロードし、**発生日時とクリックログを時刻ベースで推定紐付けする**方式を採る。新テーブル `wp_rlt_conversions` を追加し、`rlt_clicks` には手を入れない。精度は100%ではないが、現状の「何も分からない」からは大きく改善する。
