import { useEffect, useRef } from 'react'
import { useChat } from '../hooks/use-chat'
import { createTextareaInsert, brToNewline } from './textarea-insert'

export function validateSilentChatInputs(
  prompt: string | undefined | null,
  textarea: HTMLTextAreaElement | null | undefined
): string | null {
  if (textarea == null || !(textarea instanceof HTMLTextAreaElement)) {
    return '[acms-ai] SilentChat: textarea が無効です。HTMLTextAreaElement を指定してください。'
  }
  if (!textarea.value.trim()) {
    return '[acms-ai] SilentChat: target textarea が空です。テキストを入力してから実行してください。'
  }
  if (typeof prompt !== 'string' || !prompt.trim()) {
    return '[acms-ai] SilentChat: prompt が空です。プロンプト文字列を指定してください。'
  }
  return null
}

interface SilentChatProps {
  prompt: string
  textarea: HTMLTextAreaElement
  insertTextarea?: HTMLTextAreaElement
  onClose?: () => void
  onRequestStart?: () => void
  onRequestEnd?: () => void
  onBeforeInsert?: () => void
  onAfterInsert?: () => void
}

/**
 * モーダルを表示せず、プロンプトで即座にチャットを実行して correction を挿入するコンポーネント。
 * data-acms-chat-modal="false" の場合に使用する。
 */
export const SilentChat = ({
  prompt,
  textarea,
  insertTextarea,
  onClose,
  onRequestStart,
  onRequestEnd,
  onBeforeInsert,
  onAfterInsert,
}: SilentChatProps) => {
  const hasRun = useRef(false)
  const hasInserted = useRef(false)
  const hasRequestEnded = useRef(false)

  const { messages, isLoading, sendMessage } = useChat({
    initialContent: textarea?.value ? brToNewline(textarea.value) : undefined,
    silent: true,
  })

  useEffect(() => {
    if (!hasRun.current) {
      hasRun.current = true
      const validationError = validateSilentChatInputs(prompt, textarea)
      if (validationError) {
        console.error(validationError)
        onClose?.()
        return
      }
      onRequestStart?.()
      sendMessage(prompt)
    }
  // マウント時のみ実行
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    if (isLoading || !messages.some((m) => m.role === 'assistant')) return
    if (hasInserted.current) return

    if (!hasRequestEnded.current) {
      hasRequestEnded.current = true
      onRequestEnd?.()
    }

    const correction = messages.find((m) => m.type === 'correction')
    if (correction) {
      hasInserted.current = true
      onBeforeInsert?.()
      const fakeEvent = { preventDefault: () => {}, stopPropagation: () => {} } as React.MouseEvent<HTMLButtonElement>
      createTextareaInsert(textarea, insertTextarea)(() => correction.content, () => {
        onAfterInsert?.()
        onClose?.()
      })(fakeEvent)
    } else {
      console.error('[acms-ai] correction not found. Please instruct the prompt to return it wrapped in a <correction> tag.')
      onClose?.()
    }
  }, [isLoading, messages, textarea, insertTextarea, onClose, onRequestEnd, onBeforeInsert, onAfterInsert])

  return null
}
