<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Contracts;

/**
 * AI プロバイダが提供しうる機能の種別。
 *
 * 消費側（Title/Tag/Chat/Media など）が必要とする機能をプロバイダが満たすかを
 * {@see AiProvider::supports()} で問い合わせるための語彙。プロバイダ非依存の契約であり、
 * 特定ベンダの API 概念（Responses API / Messages API 等）はここには現れない。
 */
enum Capability
{
    /** テキスト生成（プロンプト → テキスト）。 */
    case TextGeneration;

    /** JSON Schema などによる構造化出力。 */
    case StructuredOutput;

    /** 画像入力（vision / マルチモーダル）。 */
    case VisionInput;

    /** ストリーミング生成（逐次チャンク出力）。 */
    case Streaming;

    /** 認証情報に基づく利用可能モデルの列挙。 */
    case ModelListing;
}
