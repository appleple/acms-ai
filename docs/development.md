# 開発ガイド（テスト・静的解析・CI）

AI 拡張アプリの PHP 側の自動テスト・静的解析・コーディング規約・CI の実行手順をまとめる。
管理画面 UI（React/TypeScript）のビルド・テストは [package.json](../package.json) の `pnpm run build` /
`pnpm run test` を参照。

## 前提

- 検証は Docker で行う。本体同梱のオールインワンイメージ `appleple/acms:3.2-php8.x` を使う。
- テスト基盤は [ablogcms/testing-framework](https://packagist.org/packages/ablogcms/testing-framework)（`3.2.*`）。
- ランタイム依存（PHP パッケージ）は同梱していない。独自 DB テーブルも持たない
  （利用するのはコアの `tag` テーブルのみ）。

## セットアップ

```bash
cp .env.example .env            # 検証する a-blog cms / PHP バージョンを ACMS_IMAGE_TAG で選ぶ
docker compose up -d --wait     # a-blog cms + MySQL を起動
docker compose exec acms bash   # 以降はコンテナ内 /workspace で作業する
```

コンテナ内（`/workspace`）で:

```bash
composer install                                        # 開発依存（testing-framework / PHPStan / PHPUnit / phpcs）
ACMS_ROOT=/var/www/html vendor/bin/acms-create-database # テスト用 DB スキーマを作成（初回のみ）
```

`docker-compose.yml` は `app/` を `extension/plugins/AI` へ、リポジトリ全体を `/workspace` へバインドマウントする。
`composer` / `phpunit` / `phpstan` / `phpcs` はすべて `/workspace` から実行する。

## 品質チェック

```bash
composer lint      # PHP_CodeSniffer（PSR12 + PHPCompatibility 8.1-8.5）
composer analyse   # PHPStan level 6（phpVersion 8.1-8.5）
composer test      # PHPUnit（Unit + Integration）
composer check     # 上記 3 つをまとめて実行
```

`composer format` で phpcs の自動整形（phpcbf）を実行できる。

### ローカル上書き

`*.dist`（`phpunit.xml.dist` / `phpcs.xml.dist` / `phpstan.neon.dist`）と `.env.example` だけをコミットする。
別パスの本体を使うなど環境差を吸収したい場合は、`.dist` をコピーした上書きファイル
（`phpunit.xml` / `phpcs.xml` / `phpstan.neon` / `.env`）を作る。これらは Git 無視される。

## テスト構成と方針

| 種別 | 基底クラス | 対象 | 置き場所 |
|------|-----------|------|---------|
| Unit | `Acms\TestingFramework\TestCase` | DB を使わない純粋ロジック | `tests/Unit/` |
| Integration | `Acms\TestingFramework\DatabaseTestCase` | DB 依存ロジック（トランザクションで自動ロールバック） | `tests/Integration/` |

- テスト対象はドメインロジック層（`app/Services`）。行カバレッジ **90% 以上**を維持する。
- `redirect()` / `exit` / HTTP・テンプレート依存（`app/GET`・`app/POST` のハンドラ、`ServiceProvider`、`Hook`、
  および Services 内の実通信シーム `AI::httpGetJson` / `ResponsesClient::exec` / `StreamingResponsesClient`）は
  決定的なユニット検証ができないため対象外＝実機/E2E で担保する。カバレッジ母数からも外している
  （`phpunit.xml.dist` の `<source>` と `@codeCoverageIgnore`）。
- 実通信は差し替え可能な薄いシームに切り出してあり、テスト用ダブル（`tests/Support/`）で解析・分岐ロジックを
  決定的に検証する。

### カバレッジ計測

イメージには pcov が入っている（既定は無効）。有効化して計測する:

```bash
php -d pcov.enabled=1 -d pcov.directory="$PWD" vendor/bin/phpunit --coverage-text
```

> テスト基盤の boot 時にコアがプラグイン用オートローダを prepend 登録するため、`tests/bootstrap.php` で
> composer オートローダを prepend し直し、テスト対象を必ずリポジトリの `app/` から解決させている
> （バインドマウント先のインストール済みコピーと取り違えないため。カバレッジの紐付けにも必要）。

## CI（GitHub Actions）

[.github/workflows/ci.yaml](../.github/workflows/ci.yaml) が push / pull_request で動く。

- **quality**: 同梱 `docker-compose.yml` で a-blog cms + MySQL を起動し、コンテナ内で `composer lint` /
  `composer analyse` を 1 回実行する（PHPCompatibility / PHPStan はレンジ全体を解析するため PHP ごとの反復は不要）。
- **phpunit**: `PHP 8.1 – 8.5` を matrix で掃引し、各バージョンで `acms-create-database` → `phpunit` を実行する。
- **js**: `pnpm run build`（tsc + vite）と `pnpm run test`（vitest）。
- **ci**: 上記すべての成功を確認する集約ゲート。**ブランチ保護ではこの `CI` ジョブだけを必須チェックにする**
  （a-blog cms / PHP バージョンを増やしてもルールセットを編集せずに済む）。

新しい a-blog cms バージョンは `phpunit` ジョブの `matrix.acms` に追加する。未公開イメージの組み合わせは
`exclude:` で外す。

## リリース

プラグインのバージョンは `app/ServiceProvider.php` の `$version` が正。`v1.2.3` のようなタグを push すると
[.github/workflows/release.yml](../.github/workflows/release.yml) が UI をビルド（`pnpm run build`）→ `app/` を zip 化
→ GitHub Release として公開する。タグと `$version` が食い違うと Verify ステップで失敗し、誤った zip は公開されない。

```bash
composer release:patch   # $version をパッチ更新（minor / major も可）
```
