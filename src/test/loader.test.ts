import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
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

interface MutationObserverMockInstance {
  callback: MutationCallback
  observe: MutationObserver['observe']
  disconnect: MutationObserver['disconnect']
}

const observerInstances: MutationObserverMockInstance[] = []

function appendAdminMain(): HTMLElement {
  const adminMain = document.createElement('div')
  adminMain.id = 'acms-admin-main'
  document.body.appendChild(adminMain)
  return adminMain
}

function appendAssistantButton(parent: ParentNode = document.body): HTMLElement {
  const button = document.createElement('acms-ai-assistant-button')
  parent.appendChild(button)
  return button
}

function addedNodesRecord(...nodes: Node[]): MutationRecord {
  return { addedNodes: nodes } as unknown as MutationRecord
}

function notify(instance: MutationObserverMockInstance, ...records: MutationRecord[]): void {
  instance.callback(records, instance as unknown as MutationObserver)
}

function appendMediaMarker(parent: ParentNode = document.body): HTMLElement {
  const marker = document.createElement('div')
  marker.id = 'js-acms-ai-media'
  parent.appendChild(marker)
  return marker
}

function appendBlockEditor(parent: ParentNode = document.body, legacy = false): HTMLElement {
  const editor = document.createElement(legacy ? 'div' : 'acms-block-editor')
  if (legacy) editor.className = 'js-block-editor'
  parent.appendChild(editor)
  return editor
}

describe('admin assistant loader', () => {
  beforeEach(() => {
    document.head.innerHTML = ''
    document.body.innerHTML = ''
    observerInstances.length = 0

    class MutationObserverMock {
      callback: MutationCallback
      observe = vi.fn()
      disconnect = vi.fn()
      takeRecords = vi.fn(() => [])

      constructor(callback: MutationCallback) {
        this.callback = callback
        observerInstances.push(this)
      }
    }

    vi.stubGlobal('MutationObserver', MutationObserverMock)
  })

  afterEach(() => {
    observerInstances.forEach((observer) => observer.disconnect())
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
    document.head.innerHTML = ''
    document.body.innerHTML = ''
  })

  it('ボタンがある場合だけCSSとバンドルを読み込む', () => {
    appendAssistantButton(appendAdminMain())

    executeLoader()

    expect(document.querySelector<HTMLLinkElement>(`link[href="${CSS_URL}"]`)).not.toBeNull()
    expect(document.querySelector<HTMLScriptElement>('script[data-acms-ai-bundle]')?.src).toContain(JS_URL)
  })

  it('ボタンがない間はバンドルを読み込まない', () => {
    executeLoader()

    expect(document.querySelector('link[rel="stylesheet"]')).toBeNull()
    expect(document.querySelector('script[data-acms-ai-bundle]')).toBeNull()
  })

  it('複数のローダーがあってもCSSとバンドルを1回だけ読み込む', () => {
    appendAssistantButton(appendAdminMain())

    executeLoader()
    executeLoader()

    expect(document.querySelectorAll(`link[href="${CSS_URL}"]`)).toHaveLength(1)
    expect(document.querySelectorAll('script[data-acms-ai-bundle]')).toHaveLength(1)
  })

  it('メディアAIのマーカーがある場合もバンドルを読み込む', () => {
    appendMediaMarker()

    executeLoader()

    expect(document.querySelector<HTMLScriptElement>('script[data-acms-ai-bundle]')).not.toBeNull()
  })

  it.each([false, true])('ブロックエディター%sがある場合もバンドルを読み込む', (legacy) => {
    appendBlockEditor(appendAdminMain(), legacy)

    executeLoader()

    expect(document.querySelector<HTMLScriptElement>('script[data-acms-ai-bundle]')).not.toBeNull()
  })

  it('既存のバンドルがある場合は二重に読み込まない', () => {
    const existing = document.createElement('script')
    existing.src = JS_URL
    document.body.appendChild(existing)

    executeLoader()

    expect(document.querySelectorAll(`script[src*="extension/plugins/AI/bundle/acms-ai.js"]`)).toHaveLength(1)
    expect(document.querySelector('link[rel="stylesheet"]')).toBeNull()
    expect(observerInstances).toHaveLength(0)
  })

  it('カスタム要素が登録済みの場合は二重に読み込まない', () => {
    vi.spyOn(window.customElements, 'get').mockReturnValue(class extends HTMLElement {})

    executeLoader()

    expect(document.querySelector('script[data-acms-ai-bundle]')).toBeNull()
    expect(document.querySelector('link[rel="stylesheet"]')).toBeNull()
    expect(observerInstances).toHaveLength(0)
  })

  it('後から追加されたボタンを検出し、読み込み後に監視を終了する', () => {
    const adminMain = appendAdminMain()
    executeLoader()
    const button = appendAssistantButton(adminMain)
    notify(observerInstances[0], addedNodesRecord(button))

    expect(document.querySelectorAll('script[data-acms-ai-bundle]')).toHaveLength(1)
    expect(document.querySelectorAll(`link[href="${CSS_URL}"]`)).toHaveLength(1)
    expect(observerInstances[0].disconnect).toHaveBeenCalledOnce()
  })

  it('後から追加されたメディアAIのマーカーも検出する', () => {
    const adminMain = appendAdminMain()
    executeLoader()
    const marker = appendMediaMarker(adminMain)
    notify(observerInstances[0], addedNodesRecord(marker))

    expect(document.querySelectorAll('script[data-acms-ai-bundle]')).toHaveLength(1)
    expect(document.querySelectorAll(`link[href="${CSS_URL}"]`)).toHaveLength(1)
    expect(observerInstances[0].disconnect).toHaveBeenCalledOnce()
  })

  it('後から追加されたブロックエディターも検出する', () => {
    const adminMain = appendAdminMain()
    executeLoader()
    const editor = appendBlockEditor(adminMain)
    notify(observerInstances[0], addedNodesRecord(editor))

    expect(document.querySelectorAll('script[data-acms-ai-bundle]')).toHaveLength(1)
    expect(observerInstances[0].disconnect).toHaveBeenCalledOnce()
  })

  it('追加ノードの子孫にあるボタンも検出する', () => {
    const adminMain = appendAdminMain()
    executeLoader()
    const wrapper = document.createElement('div')
    appendAssistantButton(wrapper)
    adminMain.appendChild(wrapper)
    notify(observerInstances[0], addedNodesRecord(wrapper))

    expect(document.querySelectorAll('script[data-acms-ai-bundle]')).toHaveLength(1)
    expect(observerInstances[0].disconnect).toHaveBeenCalledOnce()
  })

  it('無関係なDOM更新ではバンドルを読み込まない', () => {
    const adminMain = appendAdminMain()
    executeLoader()
    appendAssistantButton(adminMain)
    const unrelatedNode = document.createElement('div')
    adminMain.appendChild(unrelatedNode)
    notify(observerInstances[0], addedNodesRecord(unrelatedNode))

    expect(document.querySelector('link[rel="stylesheet"]')).toBeNull()
    expect(document.querySelector('script[data-acms-ai-bundle]')).toBeNull()
    expect(observerInstances[0].disconnect).not.toHaveBeenCalled()
  })

  it('#acms-admin-mainだけを監視する', () => {
    const adminMain = appendAdminMain()

    executeLoader()

    expect(observerInstances[0].observe).toHaveBeenCalledWith(adminMain, {
      childList: true,
      subtree: true,
    })
  })

  it('#acms-admin-mainがない場合はbodyを監視する', () => {
    executeLoader()

    expect(observerInstances[0].observe).toHaveBeenCalledWith(document.body, {
      childList: true,
      subtree: true,
    })
  })
})
