import { beforeEach, describe, expect, it, vi } from 'vitest'
import { parseErrorMessage, postRequest, postStreamingRequest } from '../api/fetcher'

describe('AI API fetcher', () => {
  beforeEach(() => {
    window.ACMS = {
      Ready: vi.fn(),
      Config: { root: '/', bid: 7 },
      addListener: vi.fn(),
    }
    vi.restoreAllMocks()
  })

  it('通常POSTは非2xxでもサーバーのJSONエラーを呼び出し元へ返す', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(
      JSON.stringify({ message: 'AI 機能を利用する権限がありません。', errorCode: 403 }),
      { status: 403, headers: { 'Content-Type': 'application/json' } },
    ))

    const result = await postRequest({
      url: '/',
      data: { mode: 'createTitle' },
      exec: 'ACMS_POST_AI_Title',
      formToken: 'token',
    })

    expect(result).toEqual({
      message: 'AI 機能を利用する権限がありません。',
      errorCode: 403,
    })
    expect(fetch).toHaveBeenCalledWith('/bid/7/', expect.objectContaining({ method: 'POST' }))
  })

  it('JSONでない非2xxにはHTTPステータス付きの代替エラーを返す', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('Forbidden', { status: 403 }))

    const result = await postRequest({
      url: '/',
      data: { mode: 'createTag' },
      exec: 'ACMS_POST_AI_Tag',
      formToken: 'token',
    })

    expect(result).toEqual({
      message: 'サーバーからエラーが返されました。(403)',
      errorCode: 403,
    })
  })

  it('ストリーミングPOSTは非2xxの本文を保持する', async () => {
    const errorBody = JSON.stringify({ message: '入力内容を指定してください。', errorCode: 400 })
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(errorBody, { status: 400 }))

    const result = await postStreamingRequest({
      url: '/',
      data: { input: '' },
      exec: 'ACMS_POST_AI_Chat',
      formToken: 'token',
    })

    expect(result).toEqual({ ok: false, status: 400, errorBody })
    if (!result.ok) {
      expect(parseErrorMessage(result.errorBody)).toBe('入力内容を指定してください。')
    }
  })

  it('不正なJSONや空のmessageからはエラーメッセージを作らない', () => {
    expect(parseErrorMessage('not-json')).toBeNull()
    expect(parseErrorMessage('{"message":""}')).toBeNull()
  })
})
