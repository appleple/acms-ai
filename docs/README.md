# AI拡張アプリ

a-blog cms の AI機能を拡張するアプリです。

## ダウンロード

最新版の拡張アプリは以下からダウンロードできます。

- [AI.zip をダウンロード（最新版）](https://github.com/appleple/acms-ai/releases/latest/download/AI.zip)

バージョンを指定してダウンロードしたい場合や過去のバージョンは [Releases](https://github.com/appleple/acms-ai/releases) を参照してください。

## 動作環境

- a-blog cms: Ver. 3.2.x (3.3+ not tested yet)
- PHP: 8.1 – 8.5 (8.6+ not tested yet)
- a-blog cms for Professional or Enterprise のみ（現状スタンダードライセンスでの利用はライセンス違反となります）

## サポートモデル

- OpenAI: API が返すモデルのうち、既定では一般用途の GPT-5.6・GPT-5.5・GPT-5.4 系
- Anthropic: API が返す利用可能モデルのうち、構造化出力に対応する Claude モデル
- Google Gemini: API が返す利用可能モデルのうち、構造化出力に対応する Gemini 2.5 / 3 系の Pro、Flash、Flash-Lite モデル
- OpenAI互換: 接続先が Chat Completions API で提供するモデル（モデル名は管理画面で手入力）

## 注意点

- APIキーによって利用できるモデルは異なります。使用したいモデルが表示されない場合は、各プロバイダのAPIキーとモデルアクセス権を確認してください。
- 本番環境では、APIキーを `.env` で管理することを推奨します。`.env` を使用しない場合は管理画面にも保存できますが、保存済みのAPIキーは管理画面へ再表示されず、空欄のまま保存すると現在の値を維持します。
- 選択中のプロバイダとモデルは config として保存されます。
- config はキャッシュを残します。設定が反映されない場合はダッシュボードからコンフィグキャッシュをクリアしてください。

管理画面に表示するモデル候補は、`private/config.system.yaml` の acms-ai 管理ブロックにある
`ai_openai_allowed_models`、`ai_anthropic_allowed_models`、`ai_gemini_allowed_models` で絞り込めます。
値は空白・カンマ・改行区切りで複数指定でき、`*` をワイルドカードとして使用できます。空の場合は、
各プロバイダが機能要件に基づいて列挙した全モデルを表示します。OpenAIのモデル許可設定キーがまだ存在しない
旧環境でも、既定値として `gpt-5.6* gpt-5.5* gpt-5.4*` を使用します。最新の GPT-5.6 系を
候補に含めつつ、APIキーでまだ利用できない場合にモデル候補が空にならないよう GPT-5.5・GPT-5.4 系も
含めます。前方一致のため、APIキーに利用権限があれば用途特化モデルも候補に含まれる場合があります。
管理ブロックは拡張アプリの更新時に置き換わるため、個別の変更は更新後に再適用してください。

### AI生成リクエストの安全制限

タイトル生成・タグ生成・AIアシスタント・メディアAI生成のエンドポイントは、対象ブログで投稿者以上の権限を持つ
ログインユーザーだけが利用できます。権限がない場合は HTTP `403` を返し、プロバイダの認証情報は
読み込みません。

過大な送信や短時間の連続実行による意図しないAPI消費を抑えるため、次の既定値を設けています。

- 1リクエストでAIへ渡す入力文字列の合計: 262,144バイトまで（超過時は HTTP `413`）
- ブログ・ログインユーザーごとの実行回数: 1分間に20回まで（超過後5分間は HTTP `429`）

必要な場合は `private/config.system.yaml` の acms-ai 管理ブロックで次の値を調整できます。

```yaml
ai_request_max_input_bytes: 262144
ai_rate_limit_window_minutes: 1
ai_rate_limit_requests: 20
ai_rate_limit_lock_minutes: 5
```

`ai_rate_limit_requests` を `0` にするとレート制限を無効化できます。入力上限を含め、管理ブロックの
個別変更は拡張アプリ更新後に再適用してください。

監査ログには、送信した記事本文・プロンプト・チャット入力・メディア画像に加えて、AIが返した応答本文や外部APIの
エラーメッセージも記録しません。解析失敗時は、プロバイダ・モデル・応答サイズ・失敗種別など、
内容を復元できない診断用メタデータだけを記録します。

OpenAI利用時、タイトル・タグ・メディア項目などの単発生成は Responses API に `store: false` を送り、
後から取得できる application state として応答を保存しません。AIアシスタントのチャットは会話継続に
`previous_response_id` を使うため `store: true` を送り、OpenAI側で少なくとも30日間保持されます。
これは通常契約の abuse monitoring による保持とは別です。Zero Data Retention が適用されたプロジェクトでは
`store` が `false` に強制されるため、この方式のチャット継続は利用できない場合があります。

## インストール方法

拡張アプリをダウンロード後、zip ファイルを解凍して `extension/plugins/` に設置します。

設置が完了すると、「管理画面 -> 拡張アプリ」に `AI` という名前で本拡張アプリが表示されるので、インストールをクリックしインストールします。

## 使い方

### 1. 利用するプロバイダの認証情報を取得

OpenAI を利用する場合は `https://platform.openai.com/api-keys` で `API KEY` を取得してください。
`Organization ID` と `Project ID` は通常不要です。複数の Organization を利用する場合や、legacy user API key で
対象を明示する場合だけ設定します。

Anthropic を利用する場合は `https://platform.claude.com/` で API KEY を取得してください。

Google Gemini を利用する場合は `https://ai.google.dev/gemini-api/docs/api-key` から Google AI Studio の API Keys 画面を開き、`Create API key` で API KEY を取得してください。新規利用者には、利用規約への同意後にデフォルトの Google Cloud プロジェクトと API KEY が自動作成される場合があります。

有料枠へアップグレードする場合は、Google AI Studio の API Keys または Projects 画面にある `Set up billing` から請求情報を設定してください。詳細は `https://ai.google.dev/gemini-api/docs/billing` を参照してください。

さくらのAI Engine を利用する場合は `https://manual.sakura.ad.jp/cloud/ai-engine/02-howto.html` の
手順で利用開始後、左メニューの「アカウントトークン」からトークンを発行してください。発行された
`<UUID>:<シークレット>` 全体を OpenAI互換 API KEY に入力します。Base URL は既定の
`https://api.ai.sakura.ad.jp/v1` を使用し、モデル名はコントロールパネルの「利用可能なモデル」に
表示されたチャットモデル名（例: `gpt-oss-120b`）を入力してください。

その他の OpenAI 互換サービスでは、サービスが案内する Chat Completions の `/v1` 相当の Base URL、
Bearer トークン、チャットモデル名を入力してください。Base URL に入力した接続先へ記事本文や
チャット内容が送信されるため、信頼できる接続先だけを指定してください。Base URL は公開ネットワークへ
解決される HTTPS URL だけを指定できます。ループバック、プライベートIP、
リンクローカル等の内部アドレスは、HTTPSの場合も拒否します。検査済みの接続先へ直接通信するため、
環境変数で設定された HTTP(S) プロキシは使用しません。

### 2. 認証情報を `.env` で管理する（推奨）

本番環境では、APIキーをa-blog cms設置ディレクトリ直下の `.env` で管理することを推奨します。
環境変数は管理画面に保存された値より優先され、管理画面のHTMLにも値を出力しません。

```dotenv
ACMS_AI_OPENAI_API_KEY=sk-...
# 以下2項目は必要な場合だけ設定
ACMS_AI_OPENAI_ORGANIZATION_ID=org-...
ACMS_AI_OPENAI_PROJECT_ID=proj-...
ACMS_AI_ANTHROPIC_API_KEY=sk-ant-...
ACMS_AI_GEMINI_API_KEY=AIza...
ACMS_AI_COMPAT_API_KEY=...
```

OpenAI互換APIキーには `ACMS_AI_COMPAT_API_KEY` を使用してください。以前案内していた
`ACMS_AI_SAKURA_API_KEY` も互換名として利用できます。両方がある場合は
`ACMS_AI_COMPAT_API_KEY` が優先されます。

環境変数を追加した後にAI設定画面を一度保存すると、対応するDB上の旧認証情報が削除されます。
その後 `.env` から変数を削除した場合は自動的にDB値へ戻らないため、管理画面で認証情報を再設定してください。

`.env` には秘密情報が含まれます。ファイル権限を必要最小限（例: 所有者のみ読み書き可能）にし、
Webサーバーから `.env` へアクセスしたときに必ず `403` または `404` になることを確認してください。
a-blog cms同梱の `.htaccess` にはドットファイルのアクセス拒否がありますが、Nginxなど `.htaccess` を
使用しない構成ではWebサーバー側に同等の拒否設定が必要です。

OpenAIの `Organization ID` と `Project ID` は、OpenAI Platformの各設定画面で確認できます。
いずれも通常は設定不要です。

### 3. a-blog cms の管理画面でプロバイダとモデルを設定する

AI管理画面の「API設定」で利用するプロバイダを選択します。`.env` に対応するAPIキーがある場合、
キーの入力欄は表示されず「.env で設定済み」と表示されるため、管理画面でキーを入力する必要はありません。

`.env` を利用できない環境では、選択したプロバイダのAPIキーを管理画面へ入力することもできます。
OpenAI互換プロバイダの `Base URL` は管理画面で設定します。空欄の場合は、さくらのAI Engineの
`https://api.ai.sakura.ad.jp/v1` を使用します。

プロバイダを変更した場合は、いったん保存してから、そのプロバイダで利用するモデルを選択して再度保存します。
OpenAI互換ではモデル一覧APIが標準化されていないため、接続先で確認したモデル名を入力してください。

設定後、「プロンプト設定」でタイトル生成・タグ生成の有効状態とプロンプトを確認します。
両機能はインストール時点では有効です。必要に応じて無効化またはプロンプトを変更してください。

## メディア画像AI生成

「プロンプト設定」の「メディア画像 プロンプト設定」で機能と対象項目を有効にします。
必要な場合は「API設定」で「画像解析モデル」を選択してください。未選択の場合は通常のモデルを使用します。

メディアの編集画面で、生成する項目を選択して「選択項目をAI生成」を押します。
候補は編集フォームへ入るだけで、その時点では保存されません。内容を確認してからメディアの更新ボタンで保存してください。
タグは既存タグを消さず、重複しない候補だけを追加します。

対象は JPEG、PNG、GIF、WebP の画像メディアで、1ファイル 8MB 以下です。
画像URLは使わず、ログインユーザーが編集できるメディアだけをCMSのストレージから読み込みます。

## エントリー編集AI機能

エントリー編集画面のタイトル欄とタグ欄に「AI生成」ボタンが表示されます。表示する機能は、
AI管理画面の「プロンプト設定」で個別に有効・無効を切り替えられます。

### タイトル生成機能
タイトル欄の「AI生成」をクリックすると、入力済みのユニットを元にタイトル候補が生成されます。
候補を1つ選択して「このタイトルにする」をクリックすると、タイトル欄へ反映されます。
もう一度「AI生成」を実行すると、表示中の候補は新しい候補に置き換わります。

反映したタイトルを保存するには、エントリーを保存してください。

### タグ生成機能
タグ欄の「AI生成」をクリックすると、入力済みのユニットを元にタグ候補が生成されます。
追加する候補にチェックを入れるとタグ欄へ反映されます。再度「AI生成」を実行すると、既存タグと
生成済みの候補を考慮し、重複しない候補を追加生成します。

反映したタグを保存するには、エントリーを保存してください。

## AIアシスタント機能

### ライトエディタ連携
ライトエディタにある「AIアシスタント」ボタンをクリックすると、画面右側にチャットドロワーが開きます。現在の編集内容を文脈として、テキストの校正・要約・翻訳・書き直しなど、自由に AI へ指示できます。結果は「挿入」ボタンでエディタへ反映されます。

### `<acms-ai-assistant-button>` Web Component
テンプレートから直接 AI アシスタントを呼び出せる Web Component です。任意の `<textarea>` をターゲットに指定することで、カスタムフィールドなどエントリー編集画面の任意の場所にAIボタンを追加できます。

この要素は **Light DOM** で動作します。子要素として書いた `<button>` がそのままボタンとして利用されるため、`acms-admin-btn` などの管理画面標準クラスや任意の CSS をそのまま適用できます。`<button>` を書かなかった場合は、要素内のテキストを内包する `<button class="acms-admin-btn">` が自動生成されます。

#### 属性

| 属性 | 必須 | 説明 |
|------|------|------|
| `target` | 必須 | 参照する textarea の CSS セレクター |
| `insert-target` | 任意 | 挿入先の textarea セレクター（省略時は `target` と同じ） |
| `prompt` | 任意 | 最初のメッセージとして送るプロンプト文字列 |
| `description` | 任意 | チャット欄の説明文 |
| `drawer-use` | 任意 | `"false"` を指定するとドロワーを開かずサイレント実行 |

#### スタイリング

Light DOM のため、外部 CSS（`acms-admin.css` の `.acms-admin-btn` など）がそのまま適用できます。子要素として書いた `<button>` に好きなクラスを付与してください。

```html
<acms-ai-assistant-button target="#body">
  <button type="button" class="acms-admin-btn">AIアシスタントを開く</button>
</acms-ai-assistant-button>
```

ローディング中は、ホスト要素（`<acms-ai-assistant-button>`）に `loading` 属性が付与され、ボタン内にローディング表示用の `<span>` が追加されます。スピナー等の標準スタイルは本拡張アプリの CSS で提供されますが、必要に応じて以下のクラスで上書きできます。

| クラス | 対象 |
|--------|------|
| `acms-ai-assistant-button__loading` | ローディング表示のコンテナ |
| `acms-ai-assistant-button__spinner` | デフォルトのスピナー |
| `acms-ai-assistant-button__sr-only` | スクリーンリーダー向けテキスト |

```css
acms-ai-assistant-button[loading] button {
  /* ローディング中のボタンの見た目を調整する例 */
  opacity: 0.7;
}
```

#### 使用例

**ドロワーを開いてチャットする（基本）**
```html
<acms-ai-assistant-button target="#body">
  <button type="button" class="acms-admin-btn">AIアシスタントを開く</button>
</acms-ai-assistant-button>
```

`<button>` を省略すると、テキストを内包したボタンが自動生成されます。

```html
<acms-ai-assistant-button target="#body">
  AIアシスタントを開く
</acms-ai-assistant-button>
```

**プロンプトを指定してドロワーを開く**
```html
<acms-ai-assistant-button
  target="#body"
  prompt="英語に翻訳してください。"
>
  <button type="button" class="acms-admin-btn">英訳する</button>
</acms-ai-assistant-button>
```

**ドロワーを開かずサイレント実行（自動校正など）**

`drawer-use="false"` を指定すると UI を表示せず即時実行します。AI のレスポンスから `<correction>` タグで囲まれた内容が自動的に textarea へ挿入されます。
もしレスポンスが安定しない場合、「修正後のテキストを &lt;correction&gt;&lt;/correction&gt; タグで囲んで返してください。」とpromptに付け足してください。

```html
<acms-ai-assistant-button
  target="#body"
  prompt="誤字脱字を修正してください。修正後のテキストを &lt;correction&gt;&lt;/correction&gt; タグで囲んで返してください。"
  drawer-use="false"
>
  <button type="button" class="acms-admin-btn">自動校正する</button>
</acms-ai-assistant-button>
```

**別フィールドへ結果を挿入する**

`insert-target` を指定すると、結果を別の textarea へ挿入できます。

```html
<acms-ai-assistant-button
  target="#body"
  insert-target="#title"
  prompt="英語に翻訳してください。"
  drawer-use="false"
>
  <button type="button" class="acms-admin-btn">タイトルを英訳して挿入</button>
</acms-ai-assistant-button>
```

**挿入時の改行形式を指定する**

通常は改行を `<br />` に変換して textarea へ挿入します。a-blog cms のテキストユニットで
`markdown`、`pre`、`none` などのソース系タグが選ばれている場合は、自動的に通常の改行を維持します。

独自の textarea で通常の改行を維持したい場合は、挿入先に
`data-acms-ai-insert-format="plain"` を指定してください。逆にソース系タグの自動判定より
HTML 形式を優先したい場合は `"html"` を指定できます。

```html
<textarea id="markdown-body" data-acms-ai-insert-format="plain"></textarea>

<acms-ai-assistant-button target="#markdown-body">
  <button type="button" class="acms-admin-btn">AIアシスタントを開く</button>
</acms-ai-assistant-button>
```

サイレント実行時は、ホスト要素に対して以下のカスタムイベントが発火します。処理状況に応じた UI 制御に利用できます。

| イベント名 | タイミング |
|-----------|-----------|
| `acms-ai:request-start` | AI へのリクエスト開始時 |
| `acms-ai:request-end` | AI のレスポンス受信完了時 |
| `acms-ai:before-insert` | textarea への挿入直前 |
| `acms-ai:after-insert` | textarea への挿入直後 |
