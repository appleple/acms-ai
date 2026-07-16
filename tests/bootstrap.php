<?php

/**
 * PHPUnit 用ブートストラップ。
 *
 * ablogcms/testing-framework の共有ブートストラップを読み込む。これが a-blog cms 本体（ACMS_ROOT。
 * phpunit.xml の <env> で指定）を起動し、コアのオートロード・定数・Application コンテナを用意する。
 * これにより DatabaseTestCase や Config / Field / SQL / DB などコア依存のクラスもテストから利用できる。
 *
 * 本プラグインはランタイム依存（PHP パッケージ）を同梱しないため、src/vendor 等の追加ロードは不要。
 * 管理画面 UI は React/TypeScript で app/bundle に Vite ビルドされるが、PHP テストからは参照しない。
 *
 * プラグイン本体のクラス（Acms\Plugins\AI\...）は composer の PSR-4（autoload）で app/ から解決される。
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/ablogcms/testing-framework/bootstrap.php';

// a-blog cms コアは boot 時に extension/plugins/{Name}/ を指すプラグイン用オートローダを prepend 登録する。
// 同梱 docker-compose.yml では app/ を extension/plugins/AI へバインドマウントするため、放置するとテスト対象
// クラスがそちら（インストール済みコピー）から読み込まれ、リポジトリの app/ とパスが食い違ってカバレッジが
// 紐づかない。composer オートローダを prepend し直し、テスト対象は必ずリポジトリの app/ から解決させる
// （＝コミット対象のコードそのものを検証する）。
$composerLoader = require __DIR__ . '/../vendor/autoload.php';
if ($composerLoader instanceof \Composer\Autoload\ClassLoader) {
    $composerLoader->unregister();
    $composerLoader->register(true);
}
