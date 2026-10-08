import ChatDrawer from '../chat/components/chat-drawer'
import { ensureDrawerMount } from '../chat'
import { render } from '../../utils/react'
import {
  captureBlockEditorTarget,
  insertBlockEditorText,
  type BlockEditorLike,
} from './editor'

interface BlockEditorMenusFillProps {
  editor: BlockEditorLike
}

const editorIds = new WeakMap<object, number>()
let nextEditorId = 1
let nextChatId = 1

function getEditorId(editor: object): number {
  const existing = editorIds.get(editor)
  if (existing) return existing
  const id = nextEditorId++
  editorIds.set(editor, id)
  return id
}

/**
 * このコンポーネントはa-blog cms本体のReactルート内で描画される。
 * 拡張側バンドルとのReactインスタンス競合を避けるためHooksは使わず、refで購読を管理する。
 */
export function BlockEditorAssistant({ editor }: BlockEditorMenusFillProps) {
  const openAssistant = () => {
    const snapshot = captureBlockEditorTarget(editor)
    const container = ensureDrawerMount()
    if (!snapshot || !container) return

    const onUnmount = () => {
      if (container._reactRoot) {
        container._reactRoot.unmount()
        delete container._reactRoot
      }
    }

    render(
      <ChatDrawer
        chatKey={`block-editor-${getEditorId(editor)}-${nextChatId++}`}
        initialContent={snapshot.initialContent}
        description="選択範囲、またはカーソル位置のテキストブロックを対象にします。"
        onInsert={(content) => insertBlockEditorText(editor, snapshot, content) ?? undefined}
        onClose={onUnmount}
      />,
      container
    )
  }

  const subscribeAvailability = (button: HTMLButtonElement | null) => {
    if (!button) return

    const updateAvailability = () => {
      const canOpen = captureBlockEditorTarget(editor) !== null
      button.disabled = !canOpen
      button.title = canOpen ? 'AIアシスタントを開く' : 'テキストブロックを選択してください'
    }
    updateAvailability()
    editor.on?.('selectionUpdate', updateAvailability)
    editor.on?.('transaction', updateAvailability)

    return () => {
      editor.off?.('selectionUpdate', updateAvailability)
      editor.off?.('transaction', updateAvailability)
    }
  }

  return (
    <button
      ref={subscribeAvailability}
      type="button"
      className="acms-admin-btn acms-admin-btn-admin acms-ai-block-editor-assistant-button"
      onClick={openAssistant}
      aria-label="AIアシスタント"
    >
      <span className="material-symbols-outlined acms-admin-block-editor-icon" aria-hidden="true">auto_awesome</span>
      <span>AIアシスタント</span>
    </button>
  )
}
