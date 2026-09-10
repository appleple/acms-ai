import { beforeEach, describe, expect, it, vi } from 'vitest'

const render = vi.fn()
const dispatchLiteEditor = vi.fn()
const defineAssistantButton = vi.fn()

vi.mock('../utils/react', () => ({ render }))
vi.mock('../dispatch/dispatch-lite-editor-chat-drawer', () => ({
  DispatchLiteEditorChatDrawer: dispatchLiteEditor,
}))
vi.mock('../elements/acms-ai-assistant-button', () => ({
  defineAcmsAiAssistantButton: defineAssistantButton,
}))

function setAcms(liteEditor = false): void {
  window.ACMS = {
    Ready: (callback: () => void) => callback(),
    Config: {
      root: '/',
      ...(liteEditor ? { LiteEditorConf: { btnOptions: [] } } : {}),
    },
    addListener: vi.fn(),
  }
}

describe('main entry point', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    vi.clearAllMocks()
    vi.resetModules()
  })

  it('エントリー編集用ルートがない画面ではReact UIをマウントしない', async () => {
    setAcms()

    await import('../main')

    expect(defineAssistantButton).toHaveBeenCalledOnce()
    expect(render).not.toHaveBeenCalled()
    expect(dispatchLiteEditor).not.toHaveBeenCalled()
  })

  it('エントリー編集用ルートとLiteEditor設定がある場合だけ既存UIを初期化する', async () => {
    document.body.innerHTML = '<div id="js-acms-ai"></div>'
    setAcms(true)

    await import('../main')

    expect(render).toHaveBeenCalledOnce()
    expect(dispatchLiteEditor).toHaveBeenCalledOnce()
  })
})
