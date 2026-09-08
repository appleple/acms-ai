import { act, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ChatDrawer from '../features/chat/components/chat-drawer'
import { useChat, type UseChatOptions } from '../features/chat/hooks/use-chat'

vi.mock('../features/chat/hooks/use-chat')

Object.defineProperty(window, 'matchMedia', {
  configurable: true,
  value: vi.fn().mockReturnValue({ matches: false }),
})
Object.defineProperty(window.HTMLElement.prototype, 'scrollIntoView', {
  configurable: true,
  value: vi.fn(),
})

describe('ChatDrawer', () => {
  let options: UseChatOptions | undefined

  beforeEach(() => {
    options = undefined
    vi.clearAllMocks()
    vi.mocked(useChat).mockImplementation((receivedOptions) => {
      options = receivedOptions
      return {
        messages: [],
        streamingContent: '',
        isLoading: false,
        sendMessage: vi.fn(),
        lastAssistantContent: undefined,
      }
    })
  })

  it('ストリーミングエラーをドロワー内に表示する', () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
    render(<ChatDrawer chatKey="test-chat" />)

    act(() => {
      options?.onError?.('Anthropic のクレジット残高が不足しています。')
    })

    expect(screen.getByRole('alert')).toHaveTextContent('Anthropic のクレジット残高が不足しています。')
    consoleError.mockRestore()
  })
})
