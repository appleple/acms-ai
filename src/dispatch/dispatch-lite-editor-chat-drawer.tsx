import ChatDrawer from '../features/chat/components/chat-drawer'
import { ensureDrawerMount } from '../features/chat'
import { brToNewline, newlineToBr } from '../features/chat/utils/textarea-insert'
import { render } from '../utils/react'

function insertToLiteEditor(liteEditorInstance: any, content: string): void {
  if (!liteEditorInstance || !content) return

  const normalized = newlineToBr(content)

  liteEditorInstance.stopStack = true

  const currentPosition = liteEditorInstance.stackPosition
  liteEditorInstance.data.value = normalized

  const source = liteEditorInstance._getElementByQuery('[data-selector="lite-editor-source"]')
  if (source) {
    source.value = liteEditorInstance.format(normalized)
  }

  liteEditorInstance.stack = liteEditorInstance.stack.slice(0, currentPosition)
  liteEditorInstance.stack.push(normalized)
  liteEditorInstance.stackPosition = currentPosition

  liteEditorInstance._fireEvent('change')
  liteEditorInstance.update()
}

export function DispatchLiteEditorChatDrawer(): void {
  const liteEditorConfBtnOptions = window.ACMS.Config.LiteEditorConf.btnOptions
  liteEditorConfBtnOptions.push({
    label: 'AIアシスタント',
    group: 'mark',
    action: 'extra',
    onClick: function (editor: any) {
      const container = ensureDrawerMount()
      if (!container) return

      const position = editor.stackPosition <= 0 ? 0 : editor.stackPosition - 1
      const initialText: string = editor.stack?.[position] || ''

      const onUnmount = () => {
        if (container._reactRoot) {
          container._reactRoot.unmount()
          delete container._reactRoot
        }
      }

      render(
        <ChatDrawer
          chatKey={String(editor.id)}
          initialContent={initialText ? brToNewline(initialText) : undefined}
          onInsert={(content) => insertToLiteEditor(editor, content)}
          onClose={onUnmount}
        />,
        container
      )
    }
  })
}
