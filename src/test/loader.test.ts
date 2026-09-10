import { beforeEach, describe, expect, it, vi } from 'vitest'
import loaderTemplate from '../../app/template/admin/loader.html?raw'

const JS_URL = '/extension/plugins/AI/bundle/acms-ai.js?v=test'
const CSS_URL = '/extension/plugins/AI/bundle/acms-ai.css?v=test'

function executeLoader(): void {
  const match = loaderTemplate.match(/<script>\n([\s\S]*?)\n<\/script>\s*$/)
  if (!match) {
    throw new Error('loader script not found')
  }
  const script = match[1]
    .split('%{AI_JS}').join(JS_URL)
    .split('%{AI_CSS}').join(CSS_URL)

  new Function(script)()
}

function appendAssistantButton(): void {
  document.body.appendChild(document.createElement('acms-ai-assistant-button'))
}

describe('admin assistant loader', () => {
  beforeEach(() => {
    document.head.innerHTML = ''
    document.body.innerHTML = ''
    vi.restoreAllMocks()
  })

  it('ボタンがある場合だけCSSとバンドルを読み込む', () => {
    appendAssistantButton()

    executeLoader()

    expect(document.querySelector<HTMLLinkElement>(`link[href="${CSS_URL}"]`)).not.toBeNull()
    expect(document.querySelector<HTMLScriptElement>('script[data-acms-ai-bundle]')?.src).toContain(JS_URL)
  })

  it('ボタンがない間はバンドルを読み込まない', () => {
    executeLoader()

    expect(document.querySelector('link[rel="stylesheet"]')).toBeNull()
    expect(document.querySelector('script[data-acms-ai-bundle]')).toBeNull()
  })

  it('既存のバンドルがある場合は二重に読み込まない', () => {
    appendAssistantButton()
    const existing = document.createElement('script')
    existing.src = JS_URL
    document.body.appendChild(existing)

    executeLoader()

    expect(document.querySelectorAll(`script[src*="extension/plugins/AI/bundle/acms-ai.js"]`)).toHaveLength(1)
    expect(document.querySelector('link[rel="stylesheet"]')).toBeNull()
  })

  it('カスタム要素が登録済みの場合は二重に読み込まない', () => {
    appendAssistantButton()
    vi.spyOn(window.customElements, 'get').mockReturnValue(class extends HTMLElement {})

    executeLoader()

    expect(document.querySelector('script[data-acms-ai-bundle]')).toBeNull()
    expect(document.querySelector('link[rel="stylesheet"]')).toBeNull()
  })

  it('後から追加されたボタンを検出し、読み込み後に監視を終了する', async () => {
    const disconnect = vi.fn()
    let notifyMutation: MutationCallback | undefined
    class MutationObserverMock {
      constructor(callback: MutationCallback) {
        notifyMutation = callback
      }

      observe = vi.fn()
      disconnect = disconnect
      takeRecords = vi.fn(() => [])
    }
    vi.stubGlobal('MutationObserver', MutationObserverMock)

    executeLoader()
    appendAssistantButton()
    notifyMutation?.([], {} as MutationObserver)

    expect(document.querySelectorAll('script[data-acms-ai-bundle]')).toHaveLength(1)
    expect(document.querySelectorAll(`link[href="${CSS_URL}"]`)).toHaveLength(1)
    expect(disconnect).toHaveBeenCalledOnce()
  })
})
