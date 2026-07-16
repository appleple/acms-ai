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

// テスト実行時は composer オートローダが 2 つ生きている:
//   (1) 本リポジトリの vendor        … PSR-4 `Acms\Plugins\AI\` => app/
//   (2) a-blog cms コアの php/vendor  … PSR-4 `Acms\Plugins\`   => extension/plugins/
// (2) はコア boot（testing-framework の bootstrap 経由）で composer 標準どおり prepend 登録されるため
// (1) より前に走り、`Acms\Plugins\AI\...` を extension/plugins/AI から解決してしまう。同梱 docker-compose.yml は
// app/ を extension/plugins/AI へバインドマウントしており中身は同一なのでテストの合否は変わらないが、実行パスが
// app/ と食い違うため <source>=app のカバレッジが紐づかない。(1) を prepend し直して AI 名前空間をリポジトリの
// app/ から解決させる（＝バインドマウント先ではなく、コミット対象のコードそのものを検証する）。コア専用クラスは
// (1) に無く (2) へフォールスルーするため副作用はない。
$composerLoader = require __DIR__ . '/../vendor/autoload.php';
if ($composerLoader instanceof \Composer\Autoload\ClassLoader) {
    $composerLoader->unregister();
    $composerLoader->register(true);
}
