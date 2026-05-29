import ChatDrawer from './components/chat-drawer'
import { SilentChat } from './utils/silent-chat'
import { clearChatState } from './hooks/use-chat'
import { insertToTextarea, brToNewline } from './utils/textarea-insert'
import { render, type ReactRootContainer } from '../../utils/react'

const DRAWER_MOUNT_ID = 'js-acms-ai-chat-drawer-container'
const SILENT_MOUNT_ID = 'js-acms-ai-silent-chat-container'
const LAYOUT_CLASS = 'acms-ai-drawer-layout'

export function ensureDrawerMount(): ReactRootContainer | null {
  const existing = document.getElementById(DRAWER_MOUNT_ID)
  if (existing) return existing as ReactRootContainer

  const adminMain = document.getElementById('acms-admin-main')
  if (!adminMain) return null

  // #acms-admin-main にレイアウトクラスを付与 (styles.module.css の :global で定義)
  adminMain.classList.add(LAYOUT_CLASS)

  const mountPoint = document.createElement('div')
  mountPoint.id = DRAWER_MOUNT_ID
  adminMain.appendChild(mountPoint)
  return mountPoint as ReactRootContainer
}

function ensureSilentMount(): ReactRootContainer | null {
  const existing = document.getElementById(SILENT_MOUNT_ID)
  if (existing) return existing as ReactRootContainer

  const adminMain = document.getElementById('acms-admin-main')
  if (!adminMain) return null

  const mountPoint = document.createElement('div')
  mountPoint.id = SILENT_MOUNT_ID
  adminMain.appendChild(mountPoint)
  return mountPoint as ReactRootContainer
}

export function openChatDrawer(options: {
  targetSelector: string
  insertSelector?: string | null
  prompt?: string
  description?: string
  showDrawer?: boolean
  onDone?: () => void
  onRequestStart?: () => void
  onRequestEnd?: () => void
  onBeforeInsert?: () => void
  onAfterInsert?: () => void
}): boolean {
  const {
    targetSelector,
    insertSelector,
    prompt,
    description,
    showDrawer = true,
    onDone,
    onRequestStart,
    onRequestEnd,
    onBeforeInsert,
    onAfterInsert,
  } = options

  const textarea = document.querySelector<HTMLTextAreaElement>(targetSelector)
  if (!textarea) return false

  const insertTextarea = insertSelector
    ? document.querySelector<HTMLTextAreaElement>(insertSelector) ?? undefined
    : undefined

  if (!showDrawer) {
    if (!textarea.value.trim()) {
      console.error('[acms-ai] openChatDrawer: target textarea is empty (silent mode requires content).')
      return false
    }
    if (!prompt) return false
    // サイレントモード: ドロワーとは別のコンテナを使うことで、開いているドロワーを閉じない。
    const silentContainer = ensureSilentMount()
    if (!silentContainer) return false

    const onSilentClose = () => {
      clearChatState(targetSelector)
      if (silentContainer._reactRoot) {
        silentContainer._reactRoot.unmount()
        delete silentContainer._reactRoot
      }
    }
    // 毎回新しいセッションとして実行するため key を付与。
    render(
      <SilentChat
        key={`${targetSelector}-${Date.now()}`}
        textarea={textarea}
        insertTextarea={insertTextarea}
        onClose={() => { onSilentClose(); onDone?.() }}
        onRequestStart={onRequestStart}
        onRequestEnd={onRequestEnd}
        onBeforeInsert={onBeforeInsert}
        onAfterInsert={onAfterInsert}
        prompt={prompt}
      />,
      silentContainer
    )
    return true
  }

  const container = ensureDrawerMount()
  if (!container) return false

  const onClose = () => {
    clearChatState(targetSelector)
    if (container._reactRoot) {
      container._reactRoot.unmount()
      delete container._reactRoot
    }
  }

  // ドロワーモード: ChatDrawer 自体には key を付けず、ルートを使い回す。
  // targetSelector を chatKey にすることで、同じターゲットへの再クリックはセッションを継続し、
  // 別ターゲットへの切り替えは ChatSession のみ再マウントする（ドロワーはちらつかない）。
  render(
    <ChatDrawer
      chatKey={targetSelector}
      onInsert={(content) => insertToTextarea(textarea, insertTextarea, content)}
      onClose={onClose}
      chatId={targetSelector}
      initialContent={textarea.value ? brToNewline(textarea.value) : undefined}
      initialPrompt={prompt}
      description={description}
    />,
    container
  )
  return true
}
