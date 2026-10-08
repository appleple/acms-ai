import { ArtificialIntelligence } from './container/artificial-intelligence'
import { PromptContextProvider } from './stores/use-prompt'
import { EntryContextProvider } from './stores/use-entry'
import { render } from './utils/react'
import { DispatchLiteEditorChatDrawer } from './dispatch/dispatch-lite-editor-chat-drawer'
import { defineAcmsAiAssistantButton } from './elements/acms-ai-assistant-button'
import { buildEntryAiSlots, readEntryAiEnabledState } from './features/entry-ai/dom'
import { registerMediaFields } from './features/media-fields'
import { registerBlockEditorAssistant } from './features/block-editor-assistant'
import './elements/acms-ai-assistant-button.css'
import './features/block-editor-assistant/styles.css'

// カスタム要素はできるだけ早く登録する（どの管理画面でも <acms-ai-assistant-button> を使えるように）
defineAcmsAiAssistantButton()

// エントリー編集のタイトル/タグ生成 UI は #js-acms-ai がある画面でのみマウントする
// （Web Component 単体で読み込まれる他の管理画面には存在しないため）
const acmsAIRoot = document.getElementById('js-acms-ai')
if (acmsAIRoot) {
  // 「有効」設定（ai_title_enabled / ai_tag_enabled）に応じて、各機能の表示を切り替える。
  // data 属性が無い場合は後方互換で両方表示する。
  const { titleEnabled, tagEnabled } = readEntryAiEnabledState(acmsAIRoot)
  const slots = buildEntryAiSlots({ titleEnabled, tagEnabled })
  render(
    <PromptContextProvider>
      <EntryContextProvider>
        <ArtificialIntelligence titleEnabled={titleEnabled} tagEnabled={tagEnabled} slots={slots} />
      </EntryContextProvider>
    </PromptContextProvider>,
    acmsAIRoot
  )
}

// ライトエディタのAIアシスタントボタンは、ライトエディタ設定がある画面でのみ追加する
window.ACMS.Ready(() => {
  registerMediaFields()
  registerBlockEditorAssistant()
  if (window.ACMS?.Config?.LiteEditorConf?.btnOptions) {
    DispatchLiteEditorChatDrawer()
  }
})
