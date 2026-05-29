// テキストユニットの textarea は値に `<br />` を含んで保存される。
// 表示用に改行へ、挿入時に再び `<br />` へ相互変換する。
const BR_TAG_REGEX = /<br\s*\/?>/gi

export function brToNewline(value: string): string {
  return value.replace(BR_TAG_REGEX, '\n')
}

export function newlineToBr(value: string): string {
  return value.replace(/\r\n|\r|\n/g, '<br />')
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
  const normalized = newlineToBr(content)
  target.focus()
  target.select()
  // execCommand はundo履歴を保持するため、value の直接書き換えより優先する
  // eslint-disable-next-line deprecation/deprecation
  const succeeded = document.execCommand('insertText', false, normalized)
  if (!succeeded) {
    // フォールバック: execCommand が無効な環境では直接セット
    const nativeValueSetter = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')?.set
    if (nativeValueSetter) {
      nativeValueSetter.call(target, normalized)
    } else {
      target.value = normalized
    }
    target.dispatchEvent(new Event('input', { bubbles: true }))
    target.dispatchEvent(new Event('change', { bubbles: true }))
  }
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
