import { act, renderHook } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useChat } from '../features/chat/hooks/use-chat'

describe('useChat のHTTPエラー表示', () => {
  beforeEach(() => {
    window.ACMS = {
      Ready: vi.fn(),
      Config: { root: '/', bid: 1 },
      addListener: vi.fn(),
    }
    window.csrfToken = 'token'
    vi.restoreAllMocks()
  })

  it('サーバーが返した権限エラーの説明を利用者へ渡す', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(
      JSON.stringify({ message: 'AI 機能を利用する権限がありません。', errorCode: 403 }),
      { status: 403, headers: { 'Content-Type': 'application/json' } },
    ))
    const onError = vi.fn()
    const { result } = renderHook(() => useChat({ onError }))

    await act(async () => {
      await result.current.sendMessage('校正してください。')
    })

    expect(onError).toHaveBeenCalledWith('AI 機能を利用する権限がありません。')
    expect(result.current.isLoading).toBe(false)
  })
})
