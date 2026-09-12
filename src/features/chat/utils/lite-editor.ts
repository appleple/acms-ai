import { brToNewline, newlineToBr } from './textarea-insert'

export interface LiteEditorLike {
  id: string | number
  data: {
    showSource?: boolean
    value?: string
    formatedValue?: string
  }
  stack?: string[]
  stackPosition?: number
  stopStack?: boolean
  _getElementByQuery?: (selector: string) => Element | null
  format?: (value: string) => string
  makeEditableHtml?: (value: string) => string
  update?: () => void
}

function normalizeNewlines(value: string): string {
  return value.replace(/\r\n|\r/g, '\n')
}

/**
 * AI 応答は外部入力として扱い、LiteEditor の HTML テンプレートへ渡す前に
 * テキストとしてエスケープする。LiteEditor の update() は data.value をそのまま
 * innerHTML へ描画するため、ここでタグを無効化しないとイベント属性も実行される。
 */
function escapeHtmlText(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
}

function normalizeInitialContent(value: string): string {
  return normalizeNewlines(brToNewline(value))
    .replace(/&nbsp;/gi, ' ')
    .replace(/\u00a0/g, ' ')
}

function getLiteEditorSource(liteEditor: LiteEditorLike): HTMLTextAreaElement | null {
  const source = liteEditor._getElementByQuery?.('[data-selector="lite-editor-source"]')
  return source instanceof HTMLTextAreaElement ? source : null
}

function getLiteEditorEditable(liteEditor: LiteEditorLike): HTMLElement | null {
  const editable = liteEditor._getElementByQuery?.('[data-selector="lite-editor"]')
  return editable instanceof HTMLElement ? editable : null
}

function setTextareaValue(textarea: HTMLTextAreaElement, value: string): void {
  const nativeValueSetter = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')?.set
  if (nativeValueSetter) {
    nativeValueSetter.call(textarea, value)
  } else {
    textarea.value = value
  }
}

function dispatchTextareaChange(textarea: HTMLTextAreaElement, includeInput = false): void {
  if (includeInput) {
    textarea.dispatchEvent(new Event('input', { bubbles: true }))
  }
  textarea.dispatchEvent(new Event('change', { bubbles: true }))
}

function syncLiteEditorStack(liteEditor: LiteEditorLike, value: string): void {
  const stack = Array.isArray(liteEditor.stack) ? liteEditor.stack : []
  const requestedPosition = Number.isInteger(liteEditor.stackPosition)
    ? liteEditor.stackPosition as number
    : stack.length
  const currentPosition = Math.max(0, Math.min(requestedPosition, stack.length))

  liteEditor.stack = [...stack.slice(0, currentPosition), value]
  liteEditor.stackPosition = currentPosition
}

export function getLiteEditorInitialContent(liteEditor: LiteEditorLike | null | undefined): string | undefined {
  if (!liteEditor) return undefined

  const source = getLiteEditorSource(liteEditor)
  if (source?.value) {
    return normalizeInitialContent(source.value)
  }

  const editable = getLiteEditorEditable(liteEditor)
  const currentValue = editable?.innerHTML || liteEditor.data.value || ''
  return currentValue ? normalizeInitialContent(currentValue) : undefined
}

export function insertToLiteEditor(liteEditor: LiteEditorLike | null | undefined, content: string): void {
  if (!liteEditor || !content) return

  const source = getLiteEditorSource(liteEditor)
  const escapedContent = escapeHtmlText(normalizeNewlines(content))
  const normalized = newlineToBr(escapedContent)
  const isSourceMode = liteEditor.data.showSource === true && source !== null

  liteEditor.stopStack = true

  if (isSourceMode) {
    const editableValue = liteEditor.makeEditableHtml?.(escapedContent) ?? normalized

    liteEditor.data.value = editableValue
    liteEditor.data.formatedValue = escapedContent
    syncLiteEditorStack(liteEditor, editableValue)
    liteEditor.update?.()

    const renderedSource = getLiteEditorSource(liteEditor) ?? source
    setTextareaValue(renderedSource, escapedContent)
    renderedSource.style.height = `${renderedSource.scrollHeight}px`
    dispatchTextareaChange(renderedSource, true)
    return
  }

  const formattedValue = liteEditor.format?.(normalized) ?? normalized

  liteEditor.data.value = normalized
  liteEditor.data.formatedValue = formattedValue
  if (source) {
    setTextareaValue(source, formattedValue)
  }
  syncLiteEditorStack(liteEditor, normalized)
  liteEditor.update?.()

  const renderedSource = getLiteEditorSource(liteEditor)
  if (renderedSource) {
    setTextareaValue(renderedSource, formattedValue)
    dispatchTextareaChange(renderedSource)
  }
}
