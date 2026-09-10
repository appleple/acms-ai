import { beforeEach, describe, expect, it, vi } from 'vitest'
import { openChatDrawer } from '../features/chat'

describe('openChatDrawer textarea resolution', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    vi.restoreAllMocks()
  })

  it('targetが存在しない場合は警告して開始しない', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})

    expect(openChatDrawer({ targetSelector: '#missing' })).toBe(false)
    expect(warn).toHaveBeenCalledWith(expect.stringContaining('target textarea not found'))
  })

  it('targetがtextarea以外の場合は開始しない', () => {
    document.body.innerHTML = '<div id="target"></div>'
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})

    expect(openChatDrawer({ targetSelector: '#target' })).toBe(false)
    expect(warn).toHaveBeenCalledWith(expect.stringContaining('target textarea not found'))
  })

  it('不正なCSSセレクターでも例外にせず開始しない', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})

    expect(openChatDrawer({ targetSelector: '[' })).toBe(false)
    expect(warn).toHaveBeenCalledWith(expect.stringContaining('invalid target selector'))
  })

  it('insert-targetが解決できない場合はtargetへ誤挿入せず開始しない', () => {
    document.body.innerHTML = '<textarea id="target">本文</textarea>'
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})

    expect(openChatDrawer({
      targetSelector: '#target',
      insertSelector: '#missing-insert-target',
    })).toBe(false)
    expect(warn).toHaveBeenCalledWith(expect.stringContaining('insert-target textarea not found'))
  })
})
