import { useCallback, useEffect, useRef, useState, memo } from 'react'
import { SideRightDrawer } from '../../../components/drawer/side-right-drawer'
import { useChat } from '../hooks/use-chat'

const CLOSE_ANIMATION_MS = 300

interface ChatSessionProps {
  onClose: () => void
  chatId?: string
  initialContent?: string
  initialPrompt?: string
  description?: string
  isOpen: boolean
  onInsert?: (content: string) => void
}

/**
 * チャットの状態（useChat）を保持する内側コンポーネント。
 * chatKey が変わると再マウントされ、新しいチャットセッションが開始される。
 * ドロワーの外側シェル（ChatDrawer）は再マウントされないため、ちらつきが発生しない。
 */
const ChatSession = memo(({
  onClose,
  chatId,
  initialContent,
  initialPrompt,
  description,
  isOpen,
  onInsert,
}: ChatSessionProps) => {
  const onError = useCallback((message: string) => {
    console.error('Chat error:', message)
  }, [])

  const { messages, streamingContent, isLoading, sendMessage } = useChat({
    onError,
    chatId,
    initialContent,
  })

  const handleInsert = useCallback((content?: string) => {
    if (content) onInsert?.(content)
  }, [onInsert])

  const handleCopy = useCallback(async (content?: string) => {
    if (!content) return
    try {
      await navigator.clipboard.writeText(content)
    } catch {
      console.error('クリップボードにコピーできませんでした')
    }
  }, [])

  return (
    <SideRightDrawer
      isOpen={isOpen}
      messages={messages}
      streamingContent={streamingContent}
      isLoading={isLoading}
      onClose={onClose}
      onSendMessage={sendMessage}
      onInsert={onInsert ? handleInsert : undefined}
      onCopy={handleCopy}
      description={description}
      initialPrompt={initialPrompt}
    />
  )
})

ChatSession.displayName = 'ChatSession'

interface Props {
  onClose?: () => void
  chatId?: string
  initialContent?: string
  initialPrompt?: string
  description?: string
  /**
   * チャットセッションの識別キー。
   * この値が変わると ChatSession が再マウントされる（ドロワー自体は再マウントされない）。
   * 同じボタンを再クリックしても値が変わらなければセッションは継続される。
   */
  chatKey: string
  /**
   * 修正後テキストの挿入先への書き込み処理。
   * 省略時は挿入ボタンを表示しない。
   */
  onInsert?: (content: string) => void
}

/**
 * ドロワーの外側シェル。isOpen ステートを管理し、開閉アニメーションを制御する。
 * チャットターゲットが切り替わっても、このコンポーネント自体は再マウントされない。
 */
const ChatDrawer = memo((props: Props) => {
  const { onClose, chatId, initialContent, initialPrompt, description, chatKey, onInsert } = props
  const [isOpen, setIsOpen] = useState(false)
  const closeTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  // マウント後にドロワーを開いてCSSトランジションを発火させる
  useEffect(() => {
    setIsOpen(true)
  }, [])

  useEffect(() => {
    return () => {
      if (closeTimerRef.current !== null) {
        clearTimeout(closeTimerRef.current)
      }
    }
  }, [])

  const handleClose = useCallback(() => {
    setIsOpen(false)
    closeTimerRef.current = setTimeout(() => onClose?.(), CLOSE_ANIMATION_MS)
  }, [onClose])

  return (
    <ChatSession
      key={chatKey}
      onClose={handleClose}
      chatId={chatId}
      initialContent={initialContent}
      initialPrompt={initialPrompt}
      description={description}
      isOpen={isOpen}
      onInsert={onInsert}
    />
  )
})

ChatDrawer.displayName = 'ChatDrawer'

export default ChatDrawer
