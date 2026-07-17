import { ArtificialIntelligence } from './container/artificial-intelligence'
import { PromptContextProvider } from './stores/use-prompt'
import { EntryContextProvider } from './stores/use-entry'
import { render } from './utils/react'
import { DispatchLiteEditorChatDrawer } from './dispatch/dispatch-lite-editor-chat-drawer'
import { defineAcmsAiAssistantButton } from './elements/acms-ai-assistant-button'
import './elements/acms-ai-assistant-button.css'

// カスタム要素はできるだけ早く登録する
defineAcmsAiAssistantButton()

// エントリー編集のタイトル/タグ生成 UI は #js-acms-ai がある画面でのみマウントする
// （Web Component 単体で読み込まれる他の管理画面には存在しないため）
const acmsAIRoot = document.getElementById('js-acms-ai')
if (acmsAIRoot) {
  render(
    <PromptContextProvider>
      <EntryContextProvider>
        <ArtificialIntelligence />
      </EntryContextProvider>
    </PromptContextProvider>,
    acmsAIRoot
  )
}

// ライトエディタのAIアシスタントボタンは、ライトエディタ設定がある画面でのみ追加する
window.ACMS.Ready(() => {
  if (window.ACMS?.Config?.LiteEditorConf?.btnOptions) {
    DispatchLiteEditorChatDrawer()
  }
})
