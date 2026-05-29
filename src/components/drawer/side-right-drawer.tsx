import { memo, useCallback, useMemo, useRef, useEffect, useState } from 'react'
import styles from '../../css/styles.module.css'
import AutoResizeTextarea, { type AutoResizeTextareaRef } from '../textarea/auto-resize-textarea'
import type { TooltipRefProps } from 'react-tooltip'
import type { ChatMessage } from '../../features/chat/hooks/use-chat'
import { Tooltip } from '../tooltip'
import { useDrawerResize } from './hooks/use-drawer-resize'

// ページセッション内でアコーディオンの折りたたみ状態を保持するモジュールスコープのストア。
// このプラグインではドロワーは常に単一インスタンス（ensureDrawerMount で保証）のため、
// インスタンス間の共有は発生しない。開閉サイクルをまたいで状態を維持するために意図的にモジュールスコープで定義している。
const accordionCollapsedStore = new Set<string>()

interface MessageItemProps {
  msg: ChatMessage
  isCollapsed: boolean | undefined
  onToggle: (id: string) => void
  onCopy: ((content?: string) => void) | undefined
  onInsert: ((content?: string) => void) | undefined
  isLoading: boolean
}

const MessageItem = memo(({ msg, isCollapsed, onToggle, onCopy, onInsert, isLoading }: MessageItemProps) => {
  const copyTooltipRef = useRef<TooltipRefProps>(null)
  const insertTooltipRef = useRef<TooltipRefProps>(null)
  const handleToggle = useCallback(() => onToggle(msg.id), [onToggle, msg.id])
  const handleCopy = useCallback(() => {
    onCopy?.(msg.content)
    copyTooltipRef.current?.open({ anchorSelect: `[data-tooltip-id="copy-tooltip-${msg.id}"]` })
    setTimeout(() => copyTooltipRef.current?.close(), 1500)
  }, [onCopy, msg.content, msg.id])
  const handleInsert = useCallback(() => {
    onInsert?.(msg.content)
    insertTooltipRef.current?.open({ anchorSelect: `[data-tooltip-id="insert-tooltip-${msg.id}"]` })
    setTimeout(() => insertTooltipRef.current?.close(), 1500)
  }, [onInsert, msg.content, msg.id])

  if (msg.type === 'initial') {
    const collapsed = isCollapsed ?? false
    return (
      <div className={styles.chatInitialBlock}>
        <div className={[styles.chatInitialHeader, collapsed ? styles.chatBlockHeaderCollapsed : ''].filter(Boolean).join(' ')}>
          <span>対象テキスト</span>
          <button
            type="button"
            className={styles.chatAccordionToggle}
            onClick={handleToggle}
            aria-expanded={!collapsed}
            aria-label={collapsed ? '展開' : '折りたたむ'}
          >
            <span className="material-symbols-outlined acms-admin-block-editor-icon">
              {collapsed ? 'open_in_full' : 'close_fullscreen'}
            </span>
          </button>
        </div>
        {!collapsed && (
          <div className={styles.chatInitialContent}>
            {msg.content}
          </div>
        )}
      </div>
    )
  }

  if (msg.type === 'correction') {
    const collapsed = isCollapsed ?? false
    return (
      <div className={styles.chatCorrectionBlock}>
        <div className={[styles.chatCorrectionHeader, collapsed ? styles.chatBlockHeaderCollapsed : ''].filter(Boolean).join(' ')}>
          <span>修正後のテキスト</span>
          <div className={styles.chatBlockHeaderActions}>
            <button
              type="button"
              className={styles.chatIconButton}
              onClick={handleCopy}
              aria-label="コピー"
              data-tooltip-id={`copy-tooltip-${msg.id}`}
            >
              <span className="material-symbols-outlined acms-admin-block-editor-icon">content_copy</span>
            </button>
            <Tooltip ref={copyTooltipRef} id={`copy-tooltip-${msg.id}`} content="コピーしました" imperativeModeOnly />
            {onInsert && (
              <>
                <button
                  type="button"
                  className={styles.chatIconButton}
                  onClick={handleInsert}
                  disabled={isLoading}
                  aria-label="挿入"
                  data-tooltip-id={`insert-tooltip-${msg.id}`}
                >
                  <span className="material-symbols-outlined acms-admin-block-editor-icon">subdirectory_arrow_left</span>
                </button>
                <Tooltip ref={insertTooltipRef} id={`insert-tooltip-${msg.id}`} content="挿入しました" imperativeModeOnly />
              </>
            )}
            <button
              type="button"
              className={styles.chatAccordionToggle}
              onClick={handleToggle}
              aria-expanded={!collapsed}
              aria-label={collapsed ? '展開' : '折りたたむ'}
            >
              <span className="material-symbols-outlined acms-admin-block-editor-icon">
                {collapsed ? 'open_in_full' : 'close_fullscreen'}
              </span>
            </button>
          </div>
        </div>
        {!collapsed && (
          <div className={styles.chatCorrectionContent}>
            {msg.content}
          </div>
        )}
      </div>
    )
  }

  return (
    <div
      className={
        msg.role === 'user'
          ? styles.chatMessageUser
          : styles.chatMessageAssistant
      }
    >
      <div className={styles.chatMessageBubble}>
        {msg.content}
      </div>
    </div>
  )
})

MessageItem.displayName = 'MessageItem'

interface SideRightDrawerProps {
  isOpen: boolean
  messages: ChatMessage[]
  streamingContent: string
  isLoading: boolean
  onClose: () => void
  onSendMessage: (content: string) => void
  onInsert?: (content?: string) => void
  onCopy?: (content?: string) => void
  description?: string
  initialPrompt?: string
}

export const SideRightDrawer = memo(({
  isOpen,
  messages,
  streamingContent,
  isLoading,
  onClose,
  onSendMessage,
  onInsert,
  onCopy,
  description,
  initialPrompt,
}: SideRightDrawerProps) => {
  const inputRef = useRef<AutoResizeTextareaRef>(null)
  const messagesEndRef = useRef<HTMLDivElement>(null)
  const { drawerRef, drawerWidth, isResizing, handleResizeStart, handleResizeKeyDown } = useDrawerResize({ isOpen })
  const [collapsedIds, setCollapsedIds] = useState<Set<string>>(
    () => new Set(accordionCollapsedStore)
  )

  useEffect(() => {
    if (initialPrompt) {
      inputRef.current?.setValue(initialPrompt)
    }
  // マウント時のみ実行
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // Escapeキーで閉じる
  useEffect(() => {
    if (!isOpen) return
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', handleKeyDown)
    return () => document.removeEventListener('keydown', handleKeyDown)
  }, [isOpen, onClose])

  // モバイルでドロワーが開いている間はbodyのスクロールをロック
  useEffect(() => {
    const isMobile = window.matchMedia('(max-width: 768px)').matches
    if (!isMobile || !isOpen) return
    const main = document.querySelector<HTMLElement>('#acms-admin-main > main')
    const prevBody = document.body.style.overflow
    const prevMain = main?.style.overflow ?? ''
    document.body.style.overflow = 'hidden'
    if (main) main.style.overflow = 'hidden'
    return () => {
      document.body.style.overflow = prevBody
      if (main) main.style.overflow = prevMain
    }
  }, [isOpen])

  const scrollToBottom = useCallback(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' })
  }, [])

  useEffect(() => {
    scrollToBottom()
  }, [messages, streamingContent, scrollToBottom])

  const commitInput = useCallback(() => {
    const value = inputRef.current?.value?.trim()
    if (value && !isLoading) {
      onSendMessage(value)
      inputRef.current?.setValue('')
    }
  }, [onSendMessage, isLoading])

  const handleSubmit = useCallback(
    (e: React.SubmitEvent) => {
      e.preventDefault()
      commitInput()
    },
    [commitInput]
  )

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        e.preventDefault()
        commitInput()
      }
    },
    [commitInput]
  )

  const toggleAccordion = useCallback((id: string) => {
    setCollapsedIds(prev => {
      const next = new Set(prev)
      if (next.has(id)) {
        next.delete(id)
        accordionCollapsedStore.delete(id)
      } else {
        next.add(id)
        accordionCollapsedStore.add(id)
      }
      return next
    })
  }, [])

  const drawerStyle = useMemo(() => ({
    ...(drawerWidth !== null ? { width: drawerWidth } : {}),
    ...(isResizing ? { transition: 'none' as const } : {}),
  }), [drawerWidth, isResizing])

  const descriptionText =
    description != null && description.trim() !== ''
      ? description
      : 'メッセージを入力して送信してください'

  return (
    <div
      ref={drawerRef}
      className={[
        styles.sideRightDrawer,
        isOpen ? styles.sideRightDrawerOpen : '',
      ].filter(Boolean).join(' ')}
      style={drawerStyle}
      role="complementary"
      aria-label="AIアシスタント"
    >
      {/* ドロワーリサイズハンドル */}
      <div
        className={styles.sideRightDrawerResizeHandle}
        onMouseDown={handleResizeStart}
        onKeyDown={handleResizeKeyDown}
        role="separator"
        aria-orientation="vertical"
        aria-label="ドロワー幅を調整（←→キーで調整）"
        tabIndex={0}
      />
      {/* ドロワーヘッダー */}
      <div className={styles.sideRightDrawerHeader}>
        <span className={styles.sideRightDrawerTitle}>AIアシスタント</span>
        <button
          type="button"
          className={styles.sideRightDrawerClose}
          onClick={onClose}
          aria-label="ドロワーを閉じる"
        >
          ✕
        </button>
      </div>

      {/* チャットエリア */}
      <div className={styles.sideRightDrawerBody}>
        <div className={`${styles.chatContainer} ${styles.chatContainerFull}`}>
          <div className={styles.chatMessages}>
            <div className={styles.chatDescription}>{descriptionText}</div>
            {messages.map((msg) => (
              <MessageItem
                key={msg.id}
                msg={msg}
                isCollapsed={collapsedIds.has(msg.id)}
                onToggle={toggleAccordion}
                onCopy={onCopy}
                onInsert={onInsert}
                isLoading={isLoading}
              />
            ))}
            {streamingContent && (
              <div className={styles.chatMessageAssistant}>
                <div className={styles.chatMessageBubble}>
                  {streamingContent}
                  <span className={styles.chatStreamingCursor} />
                </div>
              </div>
            )}
            {isLoading && !streamingContent && (
              <div className={styles.chatMessageAssistant}>
                <span className={styles.chatLoadingStatus}>回答中</span>
              </div>
            )}
            <div ref={messagesEndRef} />
          </div>

          <div className={styles.chatInputArea}>
            <form onSubmit={handleSubmit} className={styles.chatInputWrapper}>
              <AutoResizeTextarea
                ref={inputRef}
                className={styles.chatInput}
                placeholder="メッセージを入力..."
                disabled={isLoading}
                onKeyDown={handleKeyDown}
              />
              <button
                type="submit"
                className={styles.chatSendButton}
                disabled={isLoading}
              >
                送信
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
  )
})

SideRightDrawer.displayName = 'SideRightDrawer'
