import { describe, expect, it, vi, beforeEach } from 'vitest'
import {
  getLiteEditorInitialContent,
  insertToLiteEditor,
} from '../dispatch/dispatch-lite-editor-chat-drawer'
import { insertToTextarea } from '../features/chat/utils/textarea-insert'

function makeLiteEditor(options: {
  showSource: boolean
  sourceValue?: string
  editableHtml?: string
  dataValue?: string
  stack?: string[]
  stackPosition?: number
}) {
  const root = document.createElement('div')
  root.dataset.id = 'editor-test'

  const editable = document.createElement('div')
  editable.dataset.selector = 'lite-editor'
  editable.innerHTML = options.editableHtml ?? ''
  root.appendChild(editable)

  const source = document.createElement('textarea')
  source.dataset.selector = 'lite-editor-source'
  source.value = options.sourceValue ?? ''
  root.appendChild(source)

  document.body.appendChild(root)

  const editor = {
    id: 'editor-test',
    data: {
      showSource: options.showSource,
      value: options.dataValue ?? options.editableHtml ?? '',
      formatedValue: options.sourceValue ?? '',
    },
    stack: options.stack ?? ['old value'],
    stackPosition: options.stackPosition ?? 1,
    stopStack: false,
    _getElementByQuery: (selector: string) => root.querySelector(selector),
    format: vi.fn((value: string) => `formatted:${value}`),
    makeEditableHtml: vi.fn((value: string) => value.replace(/\n/g, '<br>')),
    update: vi.fn(() => {
      source.value = editor.data.formatedValue
      editable.innerHTML = editor.data.value
      editor.stopStack = false
    }),
  }

  return { editor, source, editable }
}

describe('LiteEditor AI assistant insertion', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
  })

  it('source表示中は stack ではなく現在の textarea 値を初期テキストにする', () => {
    const { editor } = makeLiteEditor({
      showSource: true,
      sourceValue: 'いまの本文<br />次の行',
      dataValue: '古い本文',
    })

    expect(getLiteEditorInitialContent(editor)).toBe('いまの本文\n次の行')
  })

  it('リッチ表示中は現在の編集DOMを初期テキストにする', () => {
    const { editor } = makeLiteEditor({
      showSource: false,
      editableHtml: '表示中の本文<br>次の行',
      sourceValue: '',
    })

    expect(getLiteEditorInitialContent(editor)).toBe('表示中の本文\n次の行')
  })

  it('リッチ表示中でも hidden source があれば source の表示用テキストを優先する', () => {
    const { editor } = makeLiteEditor({
      showSource: false,
      sourceValue: '表示中の本文\n次の行',
      editableHtml: '表示中の本文&nbsp;ではなくDOM内部HTML',
    })

    expect(getLiteEditorInitialContent(editor)).toBe('表示中の本文\n次の行')
  })

  it('新規ユニット貼り付け由来の nbsp は通常スペースへ戻して初期テキストにする', () => {
    const { editor } = makeLiteEditor({
      showSource: false,
      sourceValue: '',
      editableHtml: 'This&nbsp;is&nbsp;pasted&nbsp;text.<br>Next\u00a0line.',
      stack: [],
      stackPosition: 0,
    })

    expect(getLiteEditorInitialContent(editor)).toBe('This is pasted text.\nNext line.')
  })

  it('source表示中は raw textarea と LiteEditor 内部値を両方更新する', () => {
    const { editor, source } = makeLiteEditor({
      showSource: true,
      sourceValue: '元の本文',
    })
    const onInput = vi.fn()
    const onChange = vi.fn()
    source.addEventListener('input', onInput)
    source.addEventListener('change', onChange)

    insertToLiteEditor(editor, '修正後\r\n本文')

    expect(source.value).toBe('修正後\n本文')
    expect(editor.data.formatedValue).toBe('修正後\n本文')
    expect(editor.data.value).toBe('修正後<br>本文')
    expect(editor.stack).toEqual(['old value', '修正後<br>本文'])
    expect(editor.update).toHaveBeenCalledOnce()
    expect(onInput).toHaveBeenCalledOnce()
    expect(onChange).toHaveBeenCalledOnce()
  })

  it('新規追加直後の stack が空の LiteEditor でも戻し先を失わない', () => {
    const { editor, source } = makeLiteEditor({
      showSource: true,
      sourceValue: '',
      stack: [],
      stackPosition: 0,
    })

    insertToLiteEditor(editor, '新規ユニット本文')

    expect(source.value).toBe('新規ユニット本文')
    expect(editor.stack).toEqual(['新規ユニット本文'])
    expect(editor.stackPosition).toBe(0)
  })

  it('リッチ表示中は HTML 値を更新し hidden source に change を通知する', () => {
    const { editor, source } = makeLiteEditor({
      showSource: false,
      editableHtml: '元の本文',
    })
    const onChange = vi.fn()
    source.addEventListener('change', onChange)

    insertToLiteEditor(editor, '修正後\n本文')

    expect(editor.data.value).toBe('修正後<br />本文')
    expect(editor.data.formatedValue).toBe('formatted:修正後<br />本文')
    expect(source.value).toBe('formatted:修正後<br />本文')
    expect(editor.stack).toEqual(['old value', '修正後<br />本文'])
    expect(onChange).toHaveBeenCalledOnce()
  })
})

describe('plain textarea AI assistant insertion', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    Object.defineProperty(document, 'execCommand', {
      configurable: true,
      value: vi.fn((_command: string, _showUi: boolean, value: string) => {
        const target = document.activeElement
        if (target instanceof HTMLTextAreaElement) {
          target.value = value
        }
        return true
      }),
    })
  })

  function makeTextarea(name?: string) {
    const textarea = document.createElement('textarea')
    if (name) textarea.name = name
    document.body.appendChild(textarea)
    return textarea
  }

  it('通常 textarea は既存互換で改行を br に変換して戻す', () => {
    const textarea = makeTextarea()
    const onInput = vi.fn()
    const onChange = vi.fn()
    textarea.addEventListener('input', onInput)
    textarea.addEventListener('change', onChange)

    insertToTextarea(textarea, undefined, '修正後\n本文')

    expect(textarea.value).toBe('修正後<br />本文')
    expect(onInput).toHaveBeenCalledOnce()
    expect(onChange).toHaveBeenCalledOnce()
  })

  it('text unit の source系タグ textarea は raw 改行のまま戻す', () => {
    const textarea = makeTextarea('text_text_42')
    const select = document.createElement('select')
    select.name = 'text_tag_42'
    const option = document.createElement('option')
    option.value = 'markdown'
    option.selected = true
    select.appendChild(option)
    document.body.appendChild(select)

    insertToTextarea(textarea, undefined, '修正後\n本文')

    expect(textarea.value).toBe('修正後\n本文')
  })
})
