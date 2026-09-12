<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Logging;

/**
 * 外部APIのエラーから、本文を含まない診断用メタデータだけを抽出する。
 */
final class ProviderErrorLogContext
{
    private const SAFE_FIELDS = ['type', 'code', 'param', 'status'];
    private const MAX_IDENTIFIER_BYTES = 128;

    /**
     * @return array<string, int|string>
     */
    public static function from(mixed $error): array
    {
        $context = ['payload_type' => get_debug_type($error)];
        if (!$error instanceof \stdClass) {
            return $context;
        }

        foreach (self::SAFE_FIELDS as $field) {
            if (!isset($error->{$field})) {
                continue;
            }
            $value = $error->{$field};
            if (is_int($value)) {
                $context[$field] = $value;
                continue;
            }
            if (!is_string($value)) {
                continue;
            }
            $value = trim($value);
            if (
                strlen($value) <= self::MAX_IDENTIFIER_BYTES
                && preg_match('/\A[A-Za-z0-9_.:\/\[\]-]+\z/D', $value) === 1
            ) {
                $context[$field] = $value;
            }
        }

        return $context;
    }

    /**
     * 例外のメッセージ・トレース・引数を含めず、安全な診断情報だけを返す。
     *
     * 外部APIの通信例外は、呼び出し元のスタック引数に入力本文や認証ヘッダーを
     * 含むことがあるため {@see \Throwable::getTrace()} をログへ渡さない。
     *
     * @return array{exception_type: class-string<\Throwable>, exception_code: int}
     */
    public static function fromThrowable(\Throwable $error): array
    {
        return [
            'exception_type' => $error::class,
            'exception_code' => $error->getCode(),
        ];
    }
}
