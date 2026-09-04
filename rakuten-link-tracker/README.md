# Rakuten Link Tracker

楽天アフィリエイトURLを自サイトの短縮URLに自動で置き換え、クリックと記事PVを計測してCTRを可視化する WordPress プラグインです。

楽天アフィリエイトは分析情報をほとんど提供しないため、どの記事・どのホテルが実際に稼いでいるのかを自前で把握することを目的にしています。

## できること

- 投稿を保存すると、本文中の楽天アフィリエイトURLが `https://example.com/go/abc123` に自動で置き換わる（`<img>` の `src` は計測用画像を壊さないよう対象外）
- ブログカードなど、URLがショートコードの属性値として書かれているリンク（例: `[blogcard url="https://hb.afl.rakuten.co.jp/..."]`）も計測対象。ただし本文（`post_content`）そのものは書き換えず、表示時（`the_content`）にショートコードが展開した後のリンクだけを短縮URLに差し替える。ブログカードはプレビュー生成のためにURLをサーバー側で取得する仕組みのため、保存時に本文を書き換えてしまうと取得先が `/go/` のリダイレクトになってプレビューが壊れ、カードが表示されるたびにクリックとして誤計測されてしまう
- 短縮URLのクリックを計測（日時・記事・リファラ・デバイス・ユニーク数）
- 記事のPVを計測し、CTR（クリック ÷ PV）を算出
- 管理画面のダッシュボード、リンク一覧、記事別レポート
- 統計を読み取れる REST API と CSV エクスポート
- 既存記事の一括変換と、いつでも元に戻せる一括復元

## プライバシー

**IPアドレスは一切保存しません。** 訪問者の識別には `sha256(IP + UA + 日替わりソルト)` のハッシュだけを使い、ソルトは日次cronで毎日入れ替わります。そのため翌日以降は同一訪問者を追跡できず、「ユニーク」は常に日別ユニークとして数えられます（ソルトとDBの両方を奪われた場合はIPを逆引きされうる、というトレードオフを利用者が明示的に受け入れた設計です）。

## CTRについて

管理画面に出てくる CTR（クリック率）は「クリック数 ÷ PV」ですが、**PVの単位はリンクではなく記事です**。1つの記事に複数の楽天リンクを貼っている場合、その記事のPVがすべてのリンクで共有され、リンクごとの表示回数（インプレッション）は計測していません。そのためリンク一覧・ダッシュボードの「クリックの多いリンク」表に出るCTRは「CTR（記事PV比）」と表示され、3本リンクがある記事ならどのリンクも同じ分母になります。記事別レポートのCTRは、その記事自身のPVに対するものなので、この注意点はあてはまりません。

## 動作要件

- WordPress 6.0 以上
- PHP 8.0 以上

## インストール

このプラグインは公開リポジトリ [`sackn888/affiliates`](https://github.com/sackn888/affiliates) の `rakuten-link-tracker/` ディレクトリからそのままインストールされます。ランタイム依存は持たず、`composer install` は不要です。

1. リポジトリの `rakuten-link-tracker/` ディレクトリの内容を `wp-content/plugins/rakuten-link-tracker` に置く（このディレクトリ自体がリポジトリとして丸ごと配置される想定で、`git clone` してディレクトリ名をリネームするか、`rakuten-link-tracker/` だけを取り出して配置する）
2. 管理画面でプラグインを有効化する

一度インストールすれば、以降は `sackn888/affiliates` リポジトリの `main` ブランチを自動的に追跡して自己更新します（詳細は次節）。

（`vendor/` はComposer自身のオートロード用の足場のみを含み、開発時のテスト実行にしか使いません。`RLT\` 名前空間から `src/` を解決する小さなオートローダーをプラグイン本体が持っているため、`vendor/` が存在しなくても起動します。同様に `tests/`、`composer.json`、`composer.lock`、`phpunit-unit.xml.dist`、`phpunit-integration.xml.dist` もインストール先に残りますが、リポジトリをそのまま配置する都合上そうなっているだけで、実行時には一切読み込まれない無害なファイルです。）

## 更新の仕組み

このプラグインはPublicなGitHubリポジトリ（`sackn888/affiliates`）を直接更新元にしています。ビルドもリリース作業もトークンも不要で、更新を配るのに必要な作業はこのリポジトリの `main` ブランチへの `git push` だけです。

- 各サイトは12時間ごとに、`main` ブランチ上の `rakuten-link-tracker/rakuten-link-tracker.php` のプラグインヘッダ（`Version:`）だけをGitHubから取得して現在のバージョンと比較します
- 新しいバージョンがあれば、通常のプラグイン更新画面に更新通知が出ます。パッケージは `main` ブランチのブランチアーカイブ（`https://github.com/sackn888/affiliates/archive/refs/heads/main.zip`）で、タグやリリースの作成は不要です。アーカイブはリポジトリ全体（`affiliates-main/`）なので、更新処理はその中の `rakuten-link-tracker/` だけを取り出してインストールします
- 更新チェックに失敗した場合（GitHubに到達できない等）は、プラグイン画面に警告が表示されます
- `rakuten-link-tracker.php` 冒頭の `RLT_GITHUB_REPO` / `RLT_GITHUB_BRANCH` / `RLT_GITHUB_PATH` 定数が `OWNER` プレースホルダーを含んだままの場合、更新チェックは何もしません（通信もチェックも一切行われません）

## プレフィックスの変更は安全です

設定画面で短縮URLのプレフィックス（既定 `go`）を変更しても、**過去に発行済みの短縮URLは404になりません**。変更前のプレフィックスは自動的に「過去のプレフィックス」として記憶され（最大10件）、リダイレクトと復元の両方で引き続き解決されます。記事に埋め込まれて外部に共有済みのリンクを壊さないための仕組みです。

## キャッシュの設定（重要）

`/go/` へのリクエストがキャッシュされると、PHPが実行されずクリックが記録されません。プラグインは `/go/{code}` のレスポンスに `Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private` と `DONOTCACHEPAGE` を送出しますが、キャッシュプラグインやCDN側では以下の除外設定が別途必要です。プレフィックスを変更した場合は、そのパスに読み替えてください。

- **WP Super Cache / W3 Total Cache / LiteSpeed Cache**（国内レンタルサーバーで併用されがちなキャッシュプラグイン）: 除外URLの設定に `/go/*` を追加
- **Cloudflare**: キャッシュルールで `/go/*` を Bypass に設定
- **エックスサーバー / ConoHa WING などのサーバー側キャッシュ（LiteSpeed Cache 相当）**: 管理画面のキャッシュ除外設定に `/go/*` を追加
- **Nginx の fastcgi_cache**: `location ^~ /go/ { set $skip_cache 1; }` を追加

## REST API

ベースURL: `https://example.com/wp-json/rlt/v1`

| メソッド | パス | 内容 |
|---|---|---|
| GET | `/stats/summary` | 総クリック・PV・CTR・ユニーク数（前期間比つき） |
| GET | `/stats/daily` | 日別の時系列 |
| GET | `/stats/posts` | 記事別 PV・クリック・CTR |
| GET | `/stats/links` | リンク別（`post_id` で絞り込み可） |
| GET | `/stats/links/{code}` | 個別リンクの詳細（日別・リファラ・デバイス） |
| GET | `/stats/report` | 主要指標と所見をまとめた要約（AIに渡す用） |
| GET | `/links` | リンク一覧（`/stats/links` と同じ実装） |
| PATCH | `/links/{code}` | 遷移先URL・ラベル・状態の変更（`target_url` は2000文字まで） |
| GET | `/export/clicks` | クリック生ログのCSV |
| GET | `/export/views` | PV生ログのCSV |

共通パラメータ: `from` / `to`（`YYYY-MM-DD`）、`include_bots`。`/stats/*` と `/links` は加えて `limit`（既定50・最大500）、`orderby`、`/stats/links` と `/links` はさらに `post_id` を受け付けます。`limit` と `orderby` は集計を返すエンドポイントのみで意味を持ち、`/export/*` は `from` / `to` / `include_bots` のみです。

### 認証

**方法1: WordPress のアプリケーションパスワード**（読み書き両方。`rlt_view_stats` 権限が必要で、既定で administrator と editor に付与されます）

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

## 主な設定項目（既定値）

管理画面の設定画面から変更できます。括弧内は `Settings::defaults()` の既定値です。

- プレフィックス（`go`）
- 対象ホスト（`hb.afl.rakuten.co.jp`, `af.rakuten.co.jp`）
- ログインユーザーのクリック・PVを除外するか（有効）
- 未知の短縮コードへのアクセス時の挙動（サイトトップへリダイレクト。404にも変更可）
- ログの保持日数（365日。0で無期限）
- クリックの重複排除ウィンドウ（5秒）／PVの重複排除ウィンドウ（1800秒）

## 開発

開発コマンドはリポジトリのルート（`rakuten-link-tracker/` の一つ上の階層。`.wp-env.json` がある場所）から実行してください。

```powershell
docker run --rm -v "${PWD}\rakuten-link-tracker:/app" -w /app composer:2 install
npx @wordpress/env start

# 単体テスト（WordPress 非依存）
npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker -- vendor/bin/phpunit -c phpunit-unit.xml.dist

# 統合テスト
npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/rakuten-link-tracker -- vendor/bin/phpunit -c phpunit-integration.xml.dist
```

このマシンにはPHPが入っていないため、Composerのコマンドはすべて上記のようにDocker経由で実行してください。

## アンインストールについて

プラグインを**無効化**してもデータと本文はそのまま残り、再有効化すれば元通り動きます。

プラグインを**削除**すると、まず全記事の本文が元の楽天URLに復元され、そのあとテーブルとオプションが削除されます。この順序のおかげでリンク切れは残りません（無効化だけでは本文はいっさい変更されません）。

## 変更履歴

### 1.0.1

- 管理画面のダッシュボードが二重に表示される不具合を修正
- ブログカードなど、URLがショートコードの属性値として書かれているリンク（例: `[blogcard url="..."]`）が計測対象外だった不具合を修正。表示時にショートコード展開後のリンクを短縮URLへ差し替える方式のため、既存記事の本文は書き換わらない
- WordPress自身が `/go/` へ送るサーバー間リクエスト（プレビュー生成など）を人間のクリックとして誤計測していた不具合を修正

更新後は、設定画面の「既存記事をスキャンして変換」を再実行してください。既存記事のショートコード内リンクに短縮コードを発行するために必要です（本文は書き換わりません）。

### 1.0.0

- 初回リリース

## フック

| フック | 種類 | 用途 |
|---|---|---|
| `rlt_client_ip` | filter | プロキシ配下でクライアントIPを差し替える |
| `rlt_synced_post_types` | filter | 変換対象の投稿タイプ（既定 `post`, `page`） |
| `rlt_before_track_click` | action | クリック記録の直前 |
| `rlt_rewrite_flushed` | action | 設定画面でプレフィックスを変更しリライトルールを再登録した直後 |
