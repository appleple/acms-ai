import { useCallback, useMemo, useRef, useState } from 'react'
import { postStreamingRequest } from '../../../api/fetcher'

export interface ChatMessage {
  id: string
  role: 'user' | 'assistant'
  content: string
  type?: 'text' | 'correction' | 'initial'
}

function parseMessageBlocks(text: string): ChatMessage[] {
  const result: ChatMessage[] = []
  const correctionRegex = /<correction>([\s\S]*?)<\/correction>/g
  let lastIndex = 0
  let match: RegExpExecArray | null

  // eslint-disable-next-line no-cond-assign
  while ((match = correctionRegex.exec(text)) !== null) {
    const before = text.slice(lastIndex, match.index).trim()
    if (before) result.push({ id: crypto.randomUUID(), role: 'assistant', type: 'text', content: before })
    const correction = match[1].trim()
    if (correction) result.push({ id: crypto.randomUUID(), role: 'assistant', type: 'correction', content: correction })
    lastIndex = match.index + match[0].length
  }

  const after = text.slice(lastIndex).trim()
  if (after) result.push({ id: crypto.randomUUID(), role: 'assistant', type: 'text', content: after })

  if (result.length === 0 && text.trim()) {
    result.push({ id: crypto.randomUUID(), role: 'assistant', type: 'text', content: text.trim() })
  }
  return result
}

interface ChatState {
  messages: ChatMessage[]
  previousResponseId: string | null
}

const chatStateStore = new Map<string, ChatState>()

interface SSEEvent {
  type?: string
  text?: string
  continuationToken?: string
  message?: string
}

/**
 * ReadableStream からプロバイダ非依存の SSE イベント（delta/completed/error）を読み取り、
 * 各コールバックに委譲する純粋関数。ベンダ固有のワイヤ形式（OpenAI の response.* イベント）は
 * サーバ側でデコード済みで、ここではその中立形式だけを解釈する。
 * `completed` で accumulatedText をリセットし、残余テキストを返す。
 */
async function processSSEStream(
  reader: ReadableStreamDefaultReader<Uint8Array>,
  onDelta: (accumulatedText: string) => void,
  onCompleted: (text: string, responseId: string | undefined) => void,
  onError: (message: string) => void,
): Promise<string> {
  const decoder = new TextDecoder()
  let buffer = ''
  let accumulatedText = ''

  // eslint-disable-next-line no-constant-condition
  while (true) {
    const { done, value } = await reader.read()
    if (done) break

    buffer += decoder.decode(value, { stream: true })
    const lines = buffer.split('\n')
    buffer = lines.pop() ?? ''

    for (const line of lines) {
      if (!line.startsWith('data: ')) continue
      const jsonStr = line.slice(6)
      if (jsonStr === '[DONE]') continue

      try {
        const event = JSON.parse(jsonStr) as SSEEvent

        if (event.type === 'error') {
          onError(event.message ?? 'エラーが発生しました。')
          return accumulatedText
        }

        if (event.type === 'delta' && typeof event.text === 'string') {
          accumulatedText += event.text
          onDelta(accumulatedText)
        }

        if (event.type === 'completed') {
          const completed = accumulatedText
          accumulatedText = ''
          onCompleted(completed, event.continuationToken)
        }
      } catch {
        // Skip malformed JSON
      }
    }
  }

  return accumulatedText
}

export function clearChatState(chatId: string): void {
  chatStateStore.delete(chatId)
}

export interface UseChatOptions {
  onError?: (message: string) => void
  chatId?: string
  initialContent?: string
  silent?: boolean
}

export function useChat({ onError, chatId, initialContent, silent }: UseChatOptions = {}) {
  const stored = chatId ? chatStateStore.get(chatId) : undefined
  const [messages, setMessages] = useState<ChatMessage[]>(() => {
    if (stored) return stored.messages
    return initialContent
      ? [{ id: crypto.randomUUID(), role: 'user', type: 'initial', content: initialContent }]
      : []
  })
  const [streamingContent, setStreamingContent] = useState('')
  const [isLoading, setIsLoading] = useState(false)
  const previousResponseIdRef = useRef<string | null>(stored?.previousResponseId ?? null)

  const setMessagesAndSave = useCallback(
    (updater: (prev: ChatMessage[]) => ChatMessage[]) => {
      setMessages((prev) => {
        const next = updater(prev)
        if (chatId) {
          chatStateStore.set(chatId, {
            messages: next,
            previousResponseId: previousResponseIdRef.current,
          })
        }
        return next
      })
    },
    [chatId]
  )

  const sendMessage = useCallback(
    async (content: string) => {
      if (!content.trim() || isLoading) return

      const userMessage: ChatMessage = { id: crypto.randomUUID(), role: 'user', content: content.trim() }
      setMessagesAndSave((prev) => [...prev, userMessage])
      setIsLoading(true)
      setStreamingContent('')

      const isFirstMessage = !previousResponseIdRef.current
      const apiInput =
        isFirstMessage && initialContent
          ? `${initialContent}\n\n${userMessage.content}`
          : userMessage.content
      const data: { input: string; previousResponseId?: string; silent?: string } = {
        input: apiInput,
        ...(silent && { silent: '1' }),
      }
      if (previousResponseIdRef.current) {
        data.previousResponseId = previousResponseIdRef.current
      }

      const result = await postStreamingRequest({
        url: window.ACMS.Config.root,
        data,
        exec: 'ACMS_POST_AI_Chat',
        formToken: window.csrfToken,
      })

      if (!result.ok) {
        const msg =
          result.status === 404
            ? 'チャットAPIが見つかりません。プラグインの設定を確認してください。'
            : result.status === 500
              ? `サーバーエラー (${result.status})。APIキーやモデルの設定を確認してください。`
              : `接続に失敗しました。(${result.status})`
        onError?.(msg)
        setIsLoading(false)
        return
      }

      if (!result.response.body) {
        onError?.('レスポンスボディが空です。')
        setIsLoading(false)
        return
      }
      const reader = result.response.body.getReader()

      try {
        const remaining = await processSSEStream(
          reader,
          (accumulatedText) => setStreamingContent(accumulatedText),
          (text, responseId) => {
            if (responseId) previousResponseIdRef.current = responseId
            if (text) {
              const blocks = parseMessageBlocks(text)
              setMessagesAndSave((msgs) => [...msgs, ...blocks])
            }
            setStreamingContent('')
          },
          (message) => onError?.(message),
        )

        // Flush remaining streaming content (if stream ended without response.completed)
        if (remaining) {
          const blocks = parseMessageBlocks(remaining)
          setMessagesAndSave((msgs) => [...msgs, ...blocks])
        }
        setStreamingContent('')
      } catch (e) {
        console.error('Stream read error:', e)
        onError?.('ストリーミング中にエラーが発生しました。')
      } finally {
        setIsLoading(false)
      }
    },
    [isLoading, initialContent, onError, setMessagesAndSave, silent]
  )

  const lastAssistantContent = useMemo(
    () => messages.filter((m) => m.role === 'assistant').pop()?.content,
    [messages]
  )

  return {
    messages,
    streamingContent,
    isLoading,
    sendMessage,
    lastAssistantContent,
  }
}
