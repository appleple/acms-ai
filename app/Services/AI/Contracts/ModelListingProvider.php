<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Contracts;

/**
 * 利用可能モデルを列挙できるプロバイダの追加契約（能力別インターフェース）。
 *
 * モデル発見は生成そのものとは別の関心事であり、対応するプロバイダだけが実装する。
 * 消費側（管理画面のモデル選択など）は {@see \Acms\Plugins\AI\Services\AI\Contracts\AiProvider}
 * が本インターフェースを実装しているかで分岐する。
 */
interface ModelListingProvider
{
    /**
     * 認証情報を用いて利用可能なモデル名の一覧を返す。
     * 認証情報が未設定・不正、または通信に失敗した場合は null。
     *
     * @return list<string>|null
     */
    public function listModels(): ?array;
}
