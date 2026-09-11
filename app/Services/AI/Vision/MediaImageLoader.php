<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Vision;

use Acms\Plugins\AI\Services\AI\Contracts\ContentPart;
use Acms\Services\Facades\Media;
use Acms\Services\Facades\PrivateStorage;
use Acms\Services\Facades\PublicStorage;

/**
 * 権限検査済みの CMS メディア画像を、AI へ渡すインラインデータに変換する。
 *
 * クライアントから URL やパスを受け取らず、メディア ID から CMS の標準ストレージを
 * 解決する。これにより SSRF、パストラバーサル、TLS 検証の回避を構造的に排除する。
 */
final class MediaImageLoader
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    /** @var list<string> */
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function load(int $mediaId): ContentPart
    {
        if ($mediaId <= 0) {
            throw new \RuntimeException('メディアが指定されていません。');
        }
        if (!Media::validate(BID) || !Media::canEdit($mediaId)) {
            throw new \RuntimeException('このメディアを編集する権限がありません。');
        }

        $media = Media::getMedia($mediaId);
        if ($media === null) {
            throw new \RuntimeException('指定されたメディアが見つかりません。');
        }
        if ($media['type'] !== 'image') {
            throw new \RuntimeException('画像メディアだけを解析できます。');
        }

        $private = $media['status'] !== '';
        $storage = $private ? PrivateStorage::getInstance() : PublicStorage::getInstance();
        $baseDir = $private ? MEDIA_STORAGE_DIR : MEDIA_LIBRARY_DIR;
        $path = $baseDir . $media['path'];

        try {
            // CMS のストレージ実装にパス検証を委譲する。S3 等でも同じ契約で動作する。
            $readable = $storage->isSafeRelativePath($path) && $storage->exists($path) && $storage->isFile($path);
            $size = $readable ? $storage->getFileSize($path) : 0;
            $mimeType = $readable ? $storage->getMimeType($path) : null;
        } catch (\Throwable) {
            throw new \RuntimeException('メディア画像を読み込めません。');
        }
        if (!$readable) {
            throw new \RuntimeException('メディア画像を読み込めません。');
        }
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new \RuntimeException('画像サイズは 8MB 以下にしてください。');
        }
        if ($mimeType === null || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \RuntimeException('対応していない画像形式です。');
        }

        try {
            $bytes = $storage->get($path, $baseDir);
        } catch (\Throwable) {
            throw new \RuntimeException('メディア画像を読み込めません。');
        }
        if (!is_string($bytes) || strlen($bytes) !== $size || @getimagesizefromstring($bytes) === false) {
            throw new \RuntimeException('メディア画像が破損しているか、形式が正しくありません。');
        }

        return ContentPart::imageData($mimeType, base64_encode($bytes));
    }
}
