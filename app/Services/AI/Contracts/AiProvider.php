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
     * 保持している認証情報を検証し、利用可能なモデル名の一覧を返す。
     * 認証情報が未設定・不正、または通信に失敗した場合は null。
     *
     * @return list<string>|null
     */
    public function authenticate(): ?array;

    /**
     * テキストを生成する。`$request->outputSchema` があれば構造化出力を要求する。
     */
    public function generateText(GenerationRequest $request): GenerationResult;

    /**
     * ストリーミング生成。各チャンク（正準 SSE 形式のワイヤ列）を `$onChunk` へ渡す。
     * 出力の echo/flush など HTTP 出力の責務は呼び出し側（$onChunk）が持つ。
     *
     * @param callable(string): void $onChunk
     */
    public function streamText(GenerationRequest $request, callable $onChunk): void;
}
