import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  captureBlockEditorTarget,
  insertBlockEditorText,
  type BlockEditorDocumentLike,
  type BlockEditorLike,
} from '../features/block-editor-assistant/editor'
import { registerBlockEditorAssistant } from '../features/block-editor-assistant'

function makeEditor(options: {
  content?: string
  cursor?: number
  from?: number
  isTextblock?: boolean
  to?: number
} = {}) {
  const content = options.content ?? '本文テキスト'
  let currentText = content
  let doc: BlockEditorDocumentLike
  const makeDoc = (): BlockEditorDocumentLike => ({
    eq(other) {
      return other === this
    },
    textBetween(from, to) {
      return currentText.slice(from - 1, to - 1)
    },
  })
  doc = makeDoc()
  const selectionFrom = options.from ?? options.cursor ?? 1
  const selectionTo = options.to ?? options.cursor ?? selectionFrom
  const hidden = document.createElement('input')
  hidden.type = 'hidden'
  hidden.value = currentText
  const dispatch = vi.fn((transaction: { replacement?: { text: string, from: number, to: number } }) => {
    const replacement = transaction.replacement
    if (!replacement) return
    currentText = currentText.slice(0, replacement.from - 1)
      + replacement.text
      + currentText.slice(replacement.to - 1)
    hidden.value = currentText
    doc = makeDoc()
    editor.state.doc = doc
  })
  const transaction = {
    insertText: vi.fn((text: string, from: number, to: number) => {
      transaction.replacement = { text, from, to }
      return transaction
    }),
    replacement: undefined as { text: string, from: number, to: number } | undefined,
  }
  const editor: BlockEditorLike = {
    state: {
      doc,
      selection: {
        from: selectionFrom,
        to: selectionTo,
        empty: selectionFrom === selectionTo,
        $from: {
          parent: {
            isTextblock: options.isTextblock ?? true,
            content: { size: currentText.length },
          },
          start: () => 1,
        },
      },
      tr: transaction,
    },
    view: { dispatch: dispatch as BlockEditorLike['view']['dispatch'] },
    commands: { focus: vi.fn() },
  }

  return { editor, hidden, dispatch, transaction }
}

describe('block editor target', () => {
  it('選択テキストを対象範囲として取得する', () => {
    const { editor } = makeEditor({ content: '前選択テキスト後', from: 2, to: 8 })

    expect(captureBlockEditorTarget(editor)).toMatchObject({
      from: 2,
      to: 8,
      initialContent: '選択テキスト',
    })
  })

  it('選択がなければ現在のテキストブロック全体を取得する', () => {
    const { editor } = makeEditor({ content: 'ブロック全体', cursor: 4 })

    expect(captureBlockEditorTarget(editor)).toMatchObject({
      from: 1,
      to: 7,
      initialContent: 'ブロック全体',
    })
  })

  it('画像などテキストブロック以外は対象にしない', () => {
    const { editor } = makeEditor({ isTextblock: false })

    expect(captureBlockEditorTarget(editor)).toBeNull()
  })

  it('AI応答をHTMLではなくテキストのトランザクションとして挿入しhidden inputを同期する', () => {
    const { editor, hidden, dispatch, transaction } = makeEditor({ content: '置換前', cursor: 2 })
    const snapshot = captureBlockEditorTarget(editor)
    if (!snapshot) throw new Error('snapshot not found')
    const payload = '<img src=x onerror="document.body.dataset.xss=1">\n安全な本文'

    expect(insertBlockEditorText(editor, snapshot, payload)).toBeNull()
    expect(transaction.insertText).toHaveBeenCalledWith(payload, 1, 4)
    expect(dispatch).toHaveBeenCalledOnce()
    expect(hidden.value).toBe(payload)
    expect(document.querySelector('img')).toBeNull()
  })

  it('ドロワーを開いた後に本文または選択範囲が変わった場合は挿入しない', () => {
    const first = makeEditor({ content: '変更前', cursor: 2 })
    const snapshot = captureBlockEditorTarget(first.editor)
    if (!snapshot) throw new Error('snapshot not found')

    first.editor.state.selection.from = 3
    first.editor.state.selection.to = 3
    expect(insertBlockEditorText(first.editor, snapshot, '応答')).toContain('対象範囲が変更')
    expect(first.dispatch).not.toHaveBeenCalled()

    first.editor.state.selection.from = 2
    first.editor.state.selection.to = 2
    first.editor.state.doc = makeEditor({ content: '別の本文' }).editor.state.doc
    expect(insertBlockEditorText(first.editor, snapshot, '応答')).toContain('対象範囲が変更')
    expect(first.dispatch).not.toHaveBeenCalled()
  })

  it('複数エディターの挿入先を混同しない', () => {
    const first = makeEditor({ content: '一つ目', cursor: 2 })
    const second = makeEditor({ content: '二つ目', cursor: 2 })
    const firstSnapshot = captureBlockEditorTarget(first.editor)
    const secondSnapshot = captureBlockEditorTarget(second.editor)
    if (!firstSnapshot || !secondSnapshot) throw new Error('snapshot not found')

    insertBlockEditorText(second.editor, secondSnapshot, '二つ目の応答')

    expect(first.hidden.value).toBe('一つ目')
    expect(second.hidden.value).toBe('二つ目の応答')
  })
})

describe('block editor plugin registration', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
  })

  it('Fill props付きでblock-editor.menusへ一度だけ登録する', () => {
    const fill = vi.fn()
    const register = vi.fn((_name: string, plugin: { setup: (context: { ui: { fill: typeof fill } }) => void }) => {
      plugin.setup({ ui: { fill } })
    })
    const withFillProps = vi.fn((component: unknown) => ({ component }))
    const get = vi.fn().mockReturnValueOnce(undefined).mockReturnValueOnce({})
    window.ACMS = {
      Ready: vi.fn(),
      Config: { root: '/' },
      addListener: vi.fn(),
      plugins: { register, get, withFillProps },
    }

    registerBlockEditorAssistant()
    registerBlockEditorAssistant()

    expect(register).toHaveBeenCalledOnce()
    expect(withFillProps).toHaveBeenCalledOnce()
    expect(fill).toHaveBeenCalledWith('block-editor.menus', expect.any(Object))
  })

  it('プラグインAPIがない環境では何もしない', () => {
    window.ACMS = { Ready: vi.fn(), Config: { root: '/' }, addListener: vi.fn() }

    expect(() => registerBlockEditorAssistant()).not.toThrow()
  })
})
