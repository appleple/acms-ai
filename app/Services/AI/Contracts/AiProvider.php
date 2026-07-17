<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Contracts;

/**
 * AI プロバイダの統一契約。
 *
 * 実装は認証情報（{@see Credentials}）を保持した状態で生成される（{@see \Acms\Plugins\AI\Services\AI\ProviderRegistry}）。
 * 新しいプロバイダを追加する際はこのインターフェースを実装し、ベンダ固有のワイヤ形式変換を
 * 実装内部へ閉じ込める。ストリーミングは「フロントが期待する正準 SSE 形式」でチャンクを流す責務を負う
 * （詳細は docs の拡張ガイド参照）。
 */
interface AiProvider
{
    /**
     * プロバイダ識別子（例: 'openai'）。config の `ai_provider` と対応する。
     */
    public function id(): string;

    /**
     * 指定の機能をこのプロバイダが提供するか。
     */
    public function supports(Capability $capability): bool;

    /**
     * API 呼び出しに足る認証情報が揃っているか。プロバイダ固有の資格情報
     * （OpenAI の organization/project 等）の充足判定は実装内に閉じ、消費側には bool だけを見せる。
     */
    public function isConfigured(): bool;

    /**
     * テキストを生成する。`$request->outputSchema` があれば構造化出力を要求する。
     */
    public function generateText(GenerationRequest $request): GenerationResult;

    /**
     * ストリーミング生成。中立の {@see StreamEvent}（delta/completed/error）を `$onEvent` へ渡す。
     * ベンダ固有のワイヤ形式（SSE 等）はプロバイダ内でデコード済みで、SSE 整形・echo/flush などの
     * HTTP 出力の責務は呼び出し側（$onEvent）が持つ。
     *
     * @param callable(StreamEvent): void $onEvent
     */
    public function streamText(GenerationRequest $request, callable $onEvent): void;
}
