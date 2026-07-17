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
│   ├── Capability.php             … 機能種別（TextGeneration / StructuredOutput / VisionInput / Streaming / ModelListing）
│   ├── Credentials.php            … 認証情報バッグ（apiKey ＋ プロバイダ固有 attributes）
│   ├── Message.php / ContentPart.php … 会話メッセージ（role ＋ text/image）
│   ├── GenerationRequest.php      … 生成リクエスト（model / messages / instructions / outputSchema / continuationToken）
│   └── GenerationResult.php       … 生成結果（text / raw / continuationToken）
├── ProviderRegistry.php           … id → プロバイダの登録・解決（config の ai_provider で選択）
└── Providers/
    └── OpenAi/                    … 実装層（OpenAI 固有。差し替え・再生成可能）
        ├── OpenAiProvider.php
        ├── EndpointTrait.php
        ├── ResponsesClient.php
        └── StreamingResponsesClient.php
```

消費側（`app/POST/AI/*`, `app/GET/AI/Admin.php`）は `ProviderRegistry::withDefaults()->resolve($config)`
で得た `AiProvider` にしか依存しません。ベンダ固有のワイヤ形状（ペイロード・レスポンス・認証ヘッダ・
モデル一覧）は各プロバイダ実装の内部に閉じ込めます。

## 追加手順

### 1. プロバイダ実装クラスを作る

`app/Services/AI/Providers/<Vendor>/` に `AiProvider` を実装したクラスを置きます。

```php
namespace Acms\Plugins\AI\Services\AI\Providers\Anthropic;

use Acms\Plugins\AI\Services\AI\Contracts\AiProvider;
use Acms\Plugins\AI\Services\AI\Contracts\Capability;
use Acms\Plugins\AI\Services\AI\Contracts\Credentials;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationRequest;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationResult;
use Field;

class AnthropicProvider implements AiProvider
{
    public const ID = 'anthropic';

    public function __construct(private readonly Credentials $credentials) {}

    public static function fromConfig(Field $config): self
    {
        // このプロバイダが必要とする config キーだけをここで読む（3 点認証などの固有概念を契約へ漏らさない）。
        return new self(new Credentials($config->get('ai_anthropic_api_key')));
    }

    public function id(): string { return self::ID; }

    public function supports(Capability $capability): bool { /* 提供する機能を返す */ }

    public function authenticate(): ?array { /* 認証検証＋利用可能モデル一覧。失敗は null */ }

    public function generateText(GenerationRequest $request): GenerationResult { /* 下記参照 */ }

    public function streamText(GenerationRequest $request, callable $onChunk): void { /* 下記参照 */ }
}
```

### 2. リクエスト／レスポンスを変換する

`generateText()` は、プロバイダ非依存の `GenerationRequest` を自ベンダの API 形式へ変換し、応答から
`GenerationResult` を組み立てます。

- `$request->messages`（`Message` / `ContentPart`）→ ベンダのメッセージ配列。role は user/assistant。
- `$request->instructions` → system 指示。
- `$request->outputSchema` / `$request->outputSchemaName` → 構造化出力（JSON Schema / tool use など）。
- `$request->continuationToken` → 会話継続（下記）。
- 応答本文 → `GenerationResult::$text`、継続識別子 → `GenerationResult::$continuationToken`。

### 3. ストリーミングは「正準 SSE 形式」へ変換する

フロント（`src/features/chat/hooks/use-chat.ts`）は **OpenAI Responses API の SSE イベント形状**を
正準ワイヤ形式として解釈します。具体的には次のイベントを `data: <json>\n\n` 形式で受け取ります。

- `{"type":"response.output_text.delta","delta":"..."}` … 逐次の差分テキスト
- `{"type":"response.completed","response":{"id":"..."}}` … 完了と会話継続 ID
- `{"type":"error","message":"..."}` … エラー

新プロバイダの `streamText()` は、自ベンダのストリームを**この正準 SSE 形状の文字列へ変換**して
`$onChunk` に渡してください。これによりフロントを無改修のまま、プロバイダ差をバックエンド内へ閉じ込められます。
（OpenAI はネイティブがこの形状のためそのまま流しています。）

### 4. 会話継続トークンの扱い

`continuationToken` は会話継続の**不透明トークン**です。OpenAI はサーバ側の `previous_response_id`
にマップしています。履歴をサーバ側に保持しないプロバイダ（毎回フル履歴を送る方式）では、
トークンにセッション識別子を入れて履歴を内部で復元する、あるいはフロントから履歴全体を送るよう
拡張するなど、プロバイダ内部で吸収してください（フロントの正準形式は維持する）。

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
- モデル選択（`GET/AI/Admin`）は `provider->authenticate()` の戻り値で自動生成されます。

### 7. テスト

`tests/Unit/Services/Providers/<Vendor>/` に、実通信を差し替えたダブル（`httpGetJson` や HTTP
クライアント生成メソッドを override）を使って、リクエスト変換・レスポンス解析・認証分岐を検証します。
OpenAI 実装のテスト（`tests/Unit/Services/Providers/OpenAi/`）と `tests/Support/` のダブルが参考になります。
