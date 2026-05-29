import { render } from '@testing-library/react'
import { vi, describe, it, expect, beforeEach } from 'vitest'
import { SilentChat, validateSilentChatInputs } from '../features/chat/utils/silent-chat'
import { useChat, type ChatMessage } from '../features/chat/hooks/use-chat'
import { createTextareaInsert } from '../features/chat/utils/textarea-insert'

vi.mock('../features/chat/hooks/use-chat')
vi.mock('../features/chat/utils/textarea-insert')

// グローバル変数のスタブ
Object.defineProperty(window, 'ACMS', {
  value: { Config: { root: '/test/' } },
  writable: true,
})
Object.defineProperty(window, 'csrfToken', {
  value: 'test-token',
  writable: true,
})

function makeTextarea(value = 'original text') {
  const el = document.createElement('textarea')
  el.value = value
  document.body.appendChild(el)
  return el
}

function makeMessage(overrides: Partial<ChatMessage>): ChatMessage {
  return {
    id: crypto.randomUUID(),
    role: 'assistant',
    type: 'text',
    content: '',
    ...overrides,
  }
}

describe('validateSilentChatInputs', () => {
  it('空白のみの prompt はエラー', () => {
    const textarea = document.createElement('textarea')
    textarea.value = 'x'
    expect(validateSilentChatInputs(`  ${'\t'}  `, textarea)).not.toBeNull()
  })

  it('target textarea が空（空白のみ含む）のときはエラー', () => {
    const textarea = document.createElement('textarea')
    textarea.value = `  ${'\t'}  `
    expect(validateSilentChatInputs('有効なプロンプト', textarea)).not.toBeNull()
  })
})

describe('SilentChat', () => {
  const mockSendMessage = vi.fn()

  beforeEach(() => {
    vi.clearAllMocks()

    vi.mocked(useChat).mockImplementation(() => ({
      messages: [],
      isLoading: true,
      sendMessage: mockSendMessage,
      streamingContent: '',
      lastAssistantContent: undefined,
    }))

    vi.mocked(createTextareaInsert).mockReturnValue(
      (_getSentence, onClose) =>
        (_event) => {
          onClose()
        }
    )
  })

  describe('入力バリデーション', () => {
    it('prompt が空のときは sendMessage を呼ばずエラーを出して onClose する', () => {
      const textarea = makeTextarea()
      const onClose = vi.fn()
      const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})

      render(<SilentChat prompt="" textarea={textarea} onClose={onClose} />)

      expect(mockSendMessage).not.toHaveBeenCalled()
      expect(consoleError).toHaveBeenCalledWith(
        expect.stringContaining('[acms-ai] SilentChat: prompt')
      )
      expect(onClose).toHaveBeenCalledOnce()

      consoleError.mockRestore()
    })

    it('prompt が空白のみのときも同様にエラーにする', () => {
      const textarea = makeTextarea()
      const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
      const whitespaceOnly = `  ${'\t'}  `

      render(<SilentChat prompt={whitespaceOnly} textarea={textarea} />)

      expect(mockSendMessage).not.toHaveBeenCalled()
      expect(consoleError).toHaveBeenCalledWith(
        expect.stringContaining('[acms-ai] SilentChat: prompt')
      )

      consoleError.mockRestore()
    })

    it('target textarea が空のときは sendMessage を呼ばずエラーにする', () => {
      const textarea = makeTextarea('')
      const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})

      render(<SilentChat prompt="有効なプロンプト" textarea={textarea} />)

      expect(mockSendMessage).not.toHaveBeenCalled()
      expect(consoleError).toHaveBeenCalledWith(
        expect.stringContaining('[acms-ai] SilentChat: target textarea')
      )

      consoleError.mockRestore()
    })

    it('textarea が無効なときは sendMessage を呼ばずエラーを出して onClose する', () => {
      const onClose = vi.fn()
      const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
      const notTextarea = document.createElement('div')

      render(
        <SilentChat
          prompt="有効"
          textarea={notTextarea as unknown as HTMLTextAreaElement}
          onClose={onClose}
        />
      )

      expect(mockSendMessage).not.toHaveBeenCalled()
      expect(consoleError).toHaveBeenCalledWith(
        expect.stringContaining('[acms-ai] SilentChat: textarea')
      )
      expect(onClose).toHaveBeenCalledOnce()

      consoleError.mockRestore()
    })
  })

  describe('マウント時の動作', () => {
    it('sendMessage がプロンプトで一度だけ呼ばれる', () => {
      const textarea = makeTextarea()
      render(<SilentChat prompt="テストプロンプト" textarea={textarea} />)

      expect(mockSendMessage).toHaveBeenCalledOnce()
      expect(mockSendMessage).toHaveBeenCalledWith('テストプロンプト')
    })

    it('再レンダーしても sendMessage は二度呼ばれない', () => {
      const textarea = makeTextarea()
      const { rerender } = render(
        <SilentChat prompt="テストプロンプト" textarea={textarea} />
      )
      rerender(<SilentChat prompt="テストプロンプト" textarea={textarea} />)

      expect(mockSendMessage).toHaveBeenCalledOnce()
    })
  })

  describe('correction メッセージが存在する場合の挿入フロー', () => {
    const correctionMessage = makeMessage({ type: 'correction', content: '修正後テキスト' })

    function renderWithCorrection(textarea: HTMLTextAreaElement, insertTextarea?: HTMLTextAreaElement) {
      vi.mocked(useChat).mockImplementation(() => ({
        messages: [correctionMessage],
        isLoading: false,
        sendMessage: mockSendMessage,
        streamingContent: '',
        lastAssistantContent: correctionMessage.content,
      }))
      return render(
        <SilentChat prompt="テスト" textarea={textarea} insertTextarea={insertTextarea} />
      )
    }

    it('createTextareaInsert が正しい引数で呼ばれる', () => {
      const textarea = makeTextarea()
      const insertTextarea = makeTextarea()
      renderWithCorrection(textarea, insertTextarea)

      expect(createTextareaInsert).toHaveBeenCalledWith(textarea, insertTextarea)
    })

    it('挿入後に onClose が呼ばれる', () => {
      const textarea = makeTextarea()
      const onClose = vi.fn()
      vi.mocked(useChat).mockImplementation(() => ({
        messages: [correctionMessage],
        isLoading: false,
        sendMessage: mockSendMessage,
        streamingContent: '',
        lastAssistantContent: correctionMessage.content,
      }))
      render(<SilentChat prompt="テスト" textarea={textarea} onClose={onClose} />)

      expect(onClose).toHaveBeenCalledOnce()
    })

    it('再レンダーされても挿入は一度しか行われない（hasInserted ガード）', () => {
      const textarea = makeTextarea()
      const { rerender } = renderWithCorrection(textarea)
      rerender(<SilentChat prompt="テスト" textarea={textarea} />)

      expect(createTextareaInsert).toHaveBeenCalledOnce()
    })
  })

  describe('correction メッセージが存在しない場合', () => {
    it('onClose が呼ばれ、エラーがコンソールに出力される', () => {
      const textMessage = makeMessage({ type: 'text', content: '通常テキスト' })
      vi.mocked(useChat).mockImplementation(() => ({
        messages: [textMessage],
        isLoading: false,
        sendMessage: mockSendMessage,
        streamingContent: '',
        lastAssistantContent: textMessage.content,
      }))

      const textarea = makeTextarea()
      const onClose = vi.fn()
      const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})

      render(<SilentChat prompt="テスト" textarea={textarea} onClose={onClose} />)

      expect(onClose).toHaveBeenCalledOnce()
      expect(consoleError).toHaveBeenCalledWith(
        expect.stringContaining('[acms-ai]')
      )

      consoleError.mockRestore()
    })
  })

  describe('isLoading が true の間は何もしない', () => {
    it('createTextareaInsert は呼ばれない', () => {
      vi.mocked(useChat).mockReturnValue({
        messages: [makeMessage({ type: 'correction', content: '修正後テキスト' })],
        isLoading: true,
        sendMessage: mockSendMessage,
        streamingContent: '',
        lastAssistantContent: undefined,
      })

      const textarea = makeTextarea()
      render(<SilentChat prompt="テスト" textarea={textarea} />)

      expect(createTextareaInsert).not.toHaveBeenCalled()
    })
  })
})
