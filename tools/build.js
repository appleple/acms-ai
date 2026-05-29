import fs from 'fs-extra'
import path from 'path'
import { zipPromise } from './lib/system.js'
import { fileURLToPath } from 'url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)

// ディレクトリパスの設定
const rootDir = path.resolve(__dirname, '..')
const srcDir = path.join(rootDir, 'app')
const buildDir = path.join(rootDir, 'build')
const pluginDir = path.join(buildDir, 'AI')  // ZIP解凍時のディレクトリ名用

// 除外するファイル・ディレクトリ
const ignores = [
  '.git',
  '.gitignore',
  'node_modules',
  'vendor',
  '.editorconfig',
  '.eslintrc.js',
  '.node-version',
  '.husky',
  'build',
  '.prettierrc.js',
  'composer.json',
  'composer.lock',
  'package-lock.json',
  'pnpm-lock.yaml',
  'package.json',
  'phpcs.xml',
  'phpmd.xml',
  '.phplint-cache',
  'phpmd.log',
  'tools',
]

async function build() {
  try {
    // buildディレクトリを準備（既存のZIPは保持、作業用のAIディレクトリのみ削除）
    await fs.ensureDir(buildDir)
    await fs.remove(pluginDir)
    await fs.ensureDir(pluginDir)

    // version取得のためpackage.jsonを読み込む
    const pkg = JSON.parse(await fs.readFile(path.join(rootDir, 'package.json')))

    console.log('Copying source files...')
    // appディレクトリの内容をコピー
    await fs.copy(srcDir, pluginDir, {
      overwrite: true,
      filter: (src) => {
        // ignoresリストに含まれるファイル・ディレクトリを除外
        const relativePath = path.relative(srcDir, src)
        return !ignores.some(ignore =>
          relativePath === ignore || relativePath.startsWith(ignore + path.sep)
        )
      }
    })

    console.log('Copying docs...')
    // docsディレクトリが存在する場合はコピー
    const docsDir = path.join(rootDir, 'docs')
    if (await fs.pathExists(docsDir)) {
      await fs.copy(docsDir, path.join(pluginDir, 'docs'), { overwrite: true })
    }

    console.log('Creating ZIP file...')
    // ZIPファイル名とパスの設定
    const zipName = `AI${pkg.version}.zip`
    const zipFile = path.join(buildDir, zipName)
    // 常に最新版を指すバージョンなしのZIP
    const latestZipName = 'AI.zip'
    const latestZipFile = path.join(buildDir, latestZipName)

    // カレントディレクトリを一時的に変更してZIP作成
    const currentDir = process.cwd()
    try {
      process.chdir(buildDir)  // buildディレクトリに移動
      await zipPromise('AI', zipName)  // AIディレクトリをZIP化
    } finally {
      process.chdir(currentDir)  // 元のディレクトリに戻る
    }

    // バージョン付きZIPを最新版（AI.zip）としてもコピー
    await fs.copy(zipFile, latestZipFile, { overwrite: true })

    // プラグインディレクトリを削除（ZIPファイルのみ残す）
    await fs.remove(pluginDir)

    console.log(`Build completed: ${zipFile}`)
    console.log(`Latest copy: ${latestZipFile}`)
  } catch (err) {
    console.error('Build failed:', err)
    console.error('Error details:', err.stack)
    process.exit(1)
  }
}

// スクリプトの実行
build()
