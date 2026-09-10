<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Contracts;

/**
 * モデル一覧 API を前提にせず、管理画面でモデル名を手入力するプロバイダの追加契約。
 *
 * OpenAI 互換 API は Chat Completions を提供していても /models を提供するとは限らないため、
 * 一覧取得の失敗を認証失敗と誤判定せず、接続先のドキュメントに記載されたモデル名を入力させる。
 */
interface ManualModelProvider
{
}
