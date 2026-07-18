import ChatDrawer from '../features/chat/components/chat-drawer'
import { ensureDrawerMount } from '../features/chat'
import { brToNewline, newlineToBr } from '../features/chat/utils/textarea-insert'
import { render } from '../utils/react'

function normalizeNewlines(value: string): string {
  return value.replace(/\r\n|\r/g, '\n')
}

function normalizeInitialContent(value: string): string {
  return brToNewline(value)
    .replace(/&nbsp;/gi, ' ')
    .replace(/\u00a0/g, ' ')
}

function getLiteEditorSource(liteEditorInstance: any): HTMLTextAreaElement | null {
  const source = liteEditorInstance?._getElementByQuery?.('[data-selector="lite-editor-source"]')
  return source instanceof HTMLTextAreaElement ? source : null
}

function getLiteEditorEditable(liteEditorInstance: any): HTMLElement | null {
  const editable = liteEditorInstance?._getElementByQuery?.('[data-selector="lite-editor"]')
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

function syncLiteEditorStack(liteEditorInstance: any, value: string): void {
  const currentPosition = liteEditorInstance.stackPosition ?? 0
  if (!Array.isArray(liteEditorInstance.stack)) {
    liteEditorInstance.stack = []
  }
  liteEditorInstance.stack = liteEditorInstance.stack.slice(0, currentPosition)
  liteEditorInstance.stack.push(value)
  liteEditorInstance.stackPosition = currentPosition
}

export function getLiteEditorInitialContent(liteEditorInstance: any): string | undefined {
  if (!liteEditorInstance) return undefined

  const source = getLiteEditorSource(liteEditorInstance)
  if (source?.value) {
    return normalizeInitialContent(source.value)
  }

  const editable = getLiteEditorEditable(liteEditorInstance)
  const currentValue = editable?.innerHTML || liteEditorInstance.data?.value || ''
  return currentValue ? normalizeInitialContent(currentValue) : undefined
}

export function insertToLiteEditor(liteEditorInstance: any, content: string): void {
  if (!liteEditorInstance || !content) return

  const source = getLiteEditorSource(liteEditorInstance)
  const rawContent = normalizeNewlines(content)
  const normalized = newlineToBr(content)
  const isSourceMode = Boolean(liteEditorInstance.data?.showSource && source)

  liteEditorInstance.stopStack = true

  if (isSourceMode && source) {
    const editableValue = typeof liteEditorInstance.makeEditableHtml === 'function'
      ? liteEditorInstance.makeEditableHtml(rawContent)
      : normalized

    liteEditorInstance.data.value = editableValue
    liteEditorInstance.data.formatedValue = rawContent
    syncLiteEditorStack(liteEditorInstance, editableValue)
    liteEditorInstance.update?.()

    const renderedSource = getLiteEditorSource(liteEditorInstance) ?? source
    setTextareaValue(renderedSource, rawContent)
    renderedSource.style.height = `${renderedSource.scrollHeight}px`
    dispatchTextareaChange(renderedSource, true)
    return
  }

  const formattedValue = typeof liteEditorInstance.format === 'function'
    ? liteEditorInstance.format(normalized)
    : normalized

  liteEditorInstance.data.value = normalized
  liteEditorInstance.data.formatedValue = formattedValue
  if (source) {
    setTextareaValue(source, formattedValue)
  }
  syncLiteEditorStack(liteEditorInstance, normalized)
  liteEditorInstance.update?.()

  const renderedSource = getLiteEditorSource(liteEditorInstance)
  if (renderedSource) {
    setTextareaValue(renderedSource, formattedValue)
    dispatchTextareaChange(renderedSource)
  }
}

export function DispatchLiteEditorChatDrawer(): void {
  // ライトエディタを使わない管理画面では設定が存在しないため何もしない
  const liteEditorConfBtnOptions = window.ACMS.Config.LiteEditorConf?.btnOptions
  if (!liteEditorConfBtnOptions) {
    return
  }
  liteEditorConfBtnOptions.push({
    label: 'AIアシスタント',
    group: 'mark',
    action: 'extra',
    onClick: function (editor: any) {
      const container = ensureDrawerMount()
      if (!container) return

      const initialText = getLiteEditorInitialContent(editor)

      const onUnmount = () => {
        if (container._reactRoot) {
          container._reactRoot.unmount()
          delete container._reactRoot
        }
      }

      render(
        <ChatDrawer
          chatKey={String(editor.id)}
          initialContent={initialText}
          onInsert={(content) => insertToLiteEditor(editor, content)}
          onClose={onUnmount}
        />,
        container
      )
    }
  })
}
