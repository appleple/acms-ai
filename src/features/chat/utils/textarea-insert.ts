// テキストユニットの textarea は値に `<br />` を含んで保存される。
// 表示用に改行へ、挿入時に再び `<br />` へ相互変換する。
const BR_TAG_REGEX = /<br\s*\/?>/gi
const TEXT_UNIT_SOURCE_MODE_TAGS = /^(ul|ol|dl|pre|blockquote|none|markdown|table|template|div)/

export function brToNewline(value: string): string {
  return value.replace(BR_TAG_REGEX, '\n')
}

export function newlineToBr(value: string): string {
  return value.replace(/\r\n|\r|\n/g, '<br />')
}

function normalizeNewlines(value: string): string {
  return value.replace(/\r\n|\r/g, '\n')
}

function dispatchTextareaEvents(textarea: HTMLTextAreaElement): void {
  textarea.dispatchEvent(new Event('input', { bubbles: true }))
  textarea.dispatchEvent(new Event('change', { bubbles: true }))
}

function getTextUnitTag(textarea: HTMLTextAreaElement): string | null {
  const name = textarea.getAttribute('name')
  const match = name?.match(/^text_text_(.+)$/)
  if (!match) return null

  const tagSelectName = `text_tag_${match[1]}`
  const tagSelect = Array.from(document.querySelectorAll<HTMLSelectElement>('select[name^="text_tag_"]'))
    .find((select) => select.name === tagSelectName)

  return tagSelect?.value ?? null
}

export function shouldPreserveTextareaNewlines(textarea: HTMLTextAreaElement): boolean {
  const explicitFormat = textarea.dataset.acmsAiInsertFormat
  if (explicitFormat === 'plain') return true
  if (explicitFormat === 'html') return false

  const textUnitTag = getTextUnitTag(textarea)
  if (!textUnitTag) return false

  const sourceModeTags = window.ACMS?.Config?.LiteEditorSourceModeTags ?? TEXT_UNIT_SOURCE_MODE_TAGS
  return sourceModeTags.test(textUnitTag)
}

/**
 * textarea にテキストを挿入する。
 * execCommand('insertText') を使用してブラウザのundo履歴を保持する。
 * React管理下の textarea の変更検知のため、input/change イベントも発火する。
 */
export function insertToTextarea(
  textarea: HTMLTextAreaElement,
  insertTextarea: HTMLTextAreaElement | undefined,
  content: string
): void {
  const target = insertTextarea ?? textarea
  const normalized = shouldPreserveTextareaNewlines(target)
    ? normalizeNewlines(content)
    : newlineToBr(content)
  target.focus()
  target.select()
  // execCommand はundo履歴を保持するため、value の直接書き換えより優先する
  // eslint-disable-next-line deprecation/deprecation
  const succeeded = typeof document.execCommand === 'function'
    ? document.execCommand('insertText', false, normalized)
    : false
  if (!succeeded) {
    // フォールバック: execCommand が無効な環境では直接セット
    const nativeValueSetter = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')?.set
    if (nativeValueSetter) {
      nativeValueSetter.call(target, normalized)
    } else {
      target.value = normalized
    }
  }
  dispatchTextareaEvents(target)
}

/**
 * textarea への挿入ハンドラを生成するファクトリ関数。
 *
 * @param textarea - テキストの読み込み元 textarea
 * @param insertTextarea - 挿入先 textarea。省略時は textarea と同じ要素に挿入します。
 */
export function createTextareaInsert(
  textarea: HTMLTextAreaElement,
  insertTextarea?: HTMLTextAreaElement
): (getSentence: () => string, onClose: () => void) => (event: React.MouseEvent<HTMLButtonElement>) => void {
  return (getSentence: () => string, onClose: () => void) => {
    return (event: React.MouseEvent<HTMLButtonElement>) => {
      event.preventDefault()
      event.stopPropagation()

      const sentence = getSentence()
      if (!sentence) return

      insertToTextarea(textarea, insertTextarea, sentence)
      onClose()
    }
  }
}
