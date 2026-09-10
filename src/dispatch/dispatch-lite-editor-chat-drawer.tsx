import ChatDrawer from '../features/chat/components/chat-drawer'
import { ensureDrawerMount } from '../features/chat'
import {
  getLiteEditorInitialContent,
  insertToLiteEditor,
  type LiteEditorLike,
} from '../features/chat/utils/lite-editor'
import { render } from '../utils/react'

export function DispatchLiteEditorChatDrawer(): void {
  // ライトエディタを使わない管理画面では設定が存在しないため何もしない
  const liteEditorConfBtnOptions = window.ACMS.Config.LiteEditorConf?.btnOptions
  if (!liteEditorConfBtnOptions) {
    return
  }
  liteEditorConfBtnOptions.push({
    label: 'AIアシスタント',
    group: 'mark',
    action: 'extra',
    onClick: function (editor: LiteEditorLike) {
      const container = ensureDrawerMount()
      if (!container) return

      const initialText = getLiteEditorInitialContent(editor)

      const onUnmount = () => {
        if (container._reactRoot) {
          container._reactRoot.unmount()
          delete container._reactRoot
        }
      }

      render(
        <ChatDrawer
          chatKey={String(editor.id)}
          initialContent={initialText}
          onInsert={(content) => insertToLiteEditor(editor, content)}
          onClose={onUnmount}
        />,
        container
      )
    }
  })
}
