import { ArtificialIntelligence } from './container/artificial-intelligence'
import { PromptContextProvider } from './stores/use-prompt'
import { EntryContextProvider } from './stores/use-entry'
import { render } from './utils/react'
import { DispatchLiteEditorChatDrawer } from './dispatch/dispatch-lite-editor-chat-drawer'
import { defineAcmsAiAssistantButton } from './elements/acms-ai-assistant-button'
import './elements/acms-ai-assistant-button.css'

// カスタム要素はできるだけ早く登録する
defineAcmsAiAssistantButton()

const acmsAIRoot = document.getElementById('js-acms-ai') as HTMLElement
render(
  <PromptContextProvider>
    <EntryContextProvider>
      <ArtificialIntelligence />
    </EntryContextProvider>
  </PromptContextProvider>,
  acmsAIRoot
)

window.ACMS.Ready(() => {
  DispatchLiteEditorChatDrawer()
})
