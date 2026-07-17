# 新しい AI プロバイダを追加する

このプラグインの AI 機能は、プロバイダ非依存の**コントラクト層**と、ベンダ固有の**実装層**に
分離されています。OpenAI 以外（Anthropic Claude / Google Gemini など）を追加する際は、この
ドキュメントの手順に沿って実装層を 1 つ足すだけで、消費側（タイトル生成・タグ生成・チャット）の
コードには手を入れずに済みます。

## アーキテクチャ

```
app/Services/AI/
├── Contracts/                     … コントラクト層（安定・プロバイダ非依存）
│   ├── AiProvider.php             … プロバイダが実装する統一インターフェース
│   ├── ModelListingProvider.php   … 利用可能モデルを列挙できるプロバイダの追加契約（能力別・任意）
│   ├── Capability.php             … 機能種別（TextGeneration / StructuredOutput / VisionInput / Streaming）
│   ├── Credentials.php            … 認証情報バッグ（apiKey ＋ プロバイダ固有 attributes）
│   ├── Message.php / ContentPart.php … 会話メッセージ（role ＋ text/image）
│   ├── GenerationRequest.php      … 生成リクエスト（model / messages / instructions / outputSchema / continuationToken）
│   ├── GenerationResult.php       … 生成結果（text / usage / finishReason / continuationToken / raw）
│   ├── TokenUsage.php             … トークン使用量（prompt / completion / total）
│   └── StreamEvent.php            … ストリーミングの中立イベント（delta / completed / error）
├── ProviderRegistry.php           … id → プロバイダの登録・解決（config の ai_provider で選択）
└── Providers/
    └── OpenAi/                    … 実装層（OpenAI 固有。差し替え・再生成可能）
        ├── OpenAiProvider.php
        ├── EndpointTrait.php
        ├── ResponsesClient.php
        ├── StreamingResponsesClient.php
        └── ResponsesStreamParser.php  … OpenAI SSE → StreamEvent のデコード（純粋・テスト可能）
```

消費側（`app/POST/AI/*`, `app/GET/AI/Admin.php`, `app/GET/AI/Config.php`）は
`ProviderRegistry::withDefaults()->resolve($config)` で得た `AiProvider` にしか依存しません。
ベンダ固有のワイヤ形状（ペイロード・レスポンス・認証ヘッダ・モデル一覧・ストリームのイベント形式）は
各プロバイダ実装の内部に閉じ込め、認証情報の生キーやモデル許可リストを消費側へ晒しません。

## 追加手順

### 1. プロバイダ実装クラスを作る

`app/Services/AI/Providers/<Vendor>/` に `AiProvider` を実装したクラスを置きます。モデル列挙に
対応するなら `ModelListingProvider` も実装します（対応しないなら `AiProvider` だけでよい）。

```php
namespace Acms\Plugins\AI\Services\AI\Providers\Anthropic;

use Acms\Plugins\AI\Services\AI\Contracts\AiProvider;
use Acms\Plugins\AI\Services\AI\Contracts\Capability;
use Acms\Plugins\AI\Services\AI\Contracts\Credentials;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationRequest;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationResult;
use Acms\Plugins\AI\Services\AI\Contracts\ModelListingProvider;
use Acms\Plugins\AI\Services\AI\Contracts\StreamEvent;
use Field;

class AnthropicProvider implements AiProvider, ModelListingProvider
{
    public const ID = 'anthropic';

    public function __construct(private readonly Credentials $credentials) {}

    public static function fromConfig(Field $config): self
    {
        // このプロバイダが必要とする config キーだけをここで読む（固有概念を契約へ漏らさない）。
        return new self(new Credentials($config->get('ai_anthropic_api_key')));
    }

    public function id(): string { return self::ID; }

    public function supports(Capability $capability): bool { /* 提供する機能を返す */ }

    // API 呼び出しに足る資格情報が揃っているか。プロバイダ固有キーの充足判定を内部に閉じ、
    // 消費側（Config/AIPostTrait）には bool だけを見せる。
    public function isConfigured(): bool { /* 例: apiKey が空でない */ }

    public function generateText(GenerationRequest $request): GenerationResult { /* 下記参照 */ }

    // 中立イベント（StreamEvent）を $onEvent へ渡す。ワイヤ形式の解釈は内部に閉じる（下記参照）。
    public function streamText(GenerationRequest $request, callable $onEvent): void { /* 下記参照 */ }

    // ModelListingProvider（任意）。認証情報で利用可能モデルを列挙。未充足・失敗は null。
    public function listModels(): ?array { /* 利用可能モデル名の配列。 */ }
}
```

### 2. リクエスト／レスポンスを変換する

`generateText()` は、プロバイダ非依存の `GenerationRequest` を自ベンダの API 形式へ変換し、応答から
`GenerationResult` を組み立てます。

- `$request->messages`（`Message` / `ContentPart`）→ ベンダのメッセージ配列。role は user/assistant。
- `$request->instructions` → system 指示。
- `$request->outputSchema` / `$request->outputSchemaName` → 構造化出力（JSON Schema / tool use など）。
- `$request->continuationToken` → 会話継続（下記）。
- 応答本文 → `GenerationResult::$text`、継続識別子 → `$continuationToken`、終了理由 → `$finishReason`、
  トークン使用量 → `$usage`（`TokenUsage`。取得できなければ null）。生応答をデバッグ用に載せるなら `$raw`。

### 3. ストリーミングは中立イベント（StreamEvent）へデコードする

ワイヤ形式（ベンダの SSE 等）の解釈は**プロバイダ内**で行い、消費側へは中立の `StreamEvent`
（`delta` / `completed` / `error`）だけを渡します。`streamText()` は自ベンダのストリームをデコードして
次の要領で `$onEvent` を呼びます。

- 本文の増分 → `StreamEvent::delta($text)`
- 生成完了（会話継続トークンあり）→ `StreamEvent::completed($continuationToken)`
- エラー → `StreamEvent::error($message)`

HTTP 出力（SSE 整形・echo/flush）は消費側（`app/POST/AI/Chat.php`）の責務で、ブラウザ向けには
プロバイダ非依存の SSE（`data: {"type":"delta","text":"..."}` など）へ整形されます。フロント
（`src/features/chat/hooks/use-chat.ts`）はこの中立形式だけを解釈し、ベンダ固有のイベント名には
依存しません。OpenAI 実装では `ResponsesStreamParser` が `response.output_text.delta` /
`response.completed` / `error` を上記 `StreamEvent` へ写しており、SSE のバイト境界（チャンク分割）も
吸収します。実装の参考にしてください。

### 4. 会話継続トークンの扱い

`continuationToken` は会話継続の**不透明トークン**です。OpenAI はサーバ側の `previous_response_id`
にマップしています。履歴をサーバ側に保持しないプロバイダ（毎回フル履歴を送る方式）では、
トークンにセッション識別子を入れて履歴を内部で復元する、あるいはフロントから履歴全体を送るよう
拡張するなど、プロバイダ内部で吸収してください（消費側・フロントが扱う中立形式は維持する）。

### 5. レジストリへ登録する

`app/Services/AI/ProviderRegistry.php` の `withDefaults()` に 1 行足します。

```php
$registry->register(
    AnthropicProvider::ID,
    static fn(Field $config): AiProvider => AnthropicProvider::fromConfig($config)
);
```

### 6. config と管理画面

- 認証情報の config キー（例 `ai_anthropic_api_key`）を `app/template/admin/main.html` に
  `<input type="hidden" name="config[]" value="...">` で宣言します。
- プロバイダ選択セレクト（`name="ai_provider"`）に `<option value="anthropic">Anthropic</option>` を追加します。
- モデル選択（`GET/AI/Admin`）は、プロバイダが `ModelListingProvider` を実装していれば
  `listModels()` の戻り値で自動生成されます。「有効表示」（`GET/AI/Config`）は `isConfigured()` で判定します。

### 7. テスト

`tests/phpunit/Unit/Services/Providers/<Vendor>/` に、実通信を差し替えたダブル（`httpGetJson` や HTTP
クライアント生成メソッドを override）を使って、リクエスト変換・レスポンス解析・認証分岐を検証します。
OpenAI 実装のテスト（`tests/phpunit/Unit/Services/Providers/OpenAi/`）と `tests/phpunit/Support/` の
ダブルが参考になります。ストリームのデコードは、ワイヤ列を直接与える純粋パーサ
（`ResponsesStreamParserTest` を参照）としてユニット検証できます。
```

