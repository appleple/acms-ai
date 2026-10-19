export interface BlockEditorDocumentLike {
  eq: (other: BlockEditorDocumentLike) => boolean
  textBetween: (from: number, to: number, blockSeparator?: string, leafText?: string) => string
}

interface BlockEditorResolvedPositionLike {
  parent: {
    content: { size: number }
    isTextblock: boolean
  }
  start: () => number
}

interface BlockEditorSelectionLike {
  $from: BlockEditorResolvedPositionLike
  empty: boolean
  from: number
  to: number
}

interface BlockEditorTransactionLike {
  insertText: (text: string, from: number, to: number) => BlockEditorTransactionLike
}

export interface BlockEditorLike {
  commands?: { focus?: () => unknown }
  isDestroyed?: boolean
  off?: (event: 'selectionUpdate' | 'transaction', callback: () => void) => void
  on?: (event: 'selectionUpdate' | 'transaction', callback: () => void) => void
  state: {
    doc: BlockEditorDocumentLike
    selection: BlockEditorSelectionLike
    tr: BlockEditorTransactionLike
  }
  view: { dispatch: (transaction: BlockEditorTransactionLike) => void }
}

export interface BlockEditorTargetSnapshot {
  doc: BlockEditorDocumentLike
  from: number
  initialContent: string
  selectionFrom: number
  selectionTo: number
  to: number
}

/**
 * 選択範囲、またはカーソルを含むテキストブロック全体を安全な挿入対象として固定する。
 * テキストを持たない画像・ファイル等の NodeSelection は対象外にする。
 */
export function captureBlockEditorTarget(editor: BlockEditorLike): BlockEditorTargetSnapshot | null {
  if (editor.isDestroyed) return null

  const { doc, selection } = editor.state
  if (!selection.$from.parent.isTextblock) return null

  const from = selection.empty ? selection.$from.start() : selection.from
  const to = selection.empty ? from + selection.$from.parent.content.size : selection.to
  const initialContent = doc.textBetween(from, to, '\n', '\n')

  if (!initialContent.trim()) return null

  return {
    doc,
    from,
    to,
    initialContent,
    selectionFrom: selection.from,
    selectionTo: selection.to,
  }
}

/**
 * AI応答を ProseMirror のテキストトランザクションとして挿入する。
 * 文字列をHTMLパーサーへ渡さないため、タグやイベント属性は常に文字として扱われる。
 */
export function insertBlockEditorText(
  editor: BlockEditorLike,
  snapshot: BlockEditorTargetSnapshot,
  content: string
): string | null {
  if (!content) return null
  if (editor.isDestroyed) return '対象のブロックエディターが見つかりません。もう一度AIアシスタントを開いてください。'

  const { doc, selection } = editor.state
  const targetChanged = !snapshot.doc.eq(doc)
    || selection.from !== snapshot.selectionFrom
    || selection.to !== snapshot.selectionTo

  if (targetChanged) {
    return '対象範囲が変更されています。誤った位置への挿入を防ぐため、AIアシスタントを開き直してください。'
  }

  const transaction = editor.state.tr.insertText(content.replace(/\r\n|\r/g, '\n'), snapshot.from, snapshot.to)
  editor.view.dispatch(transaction)
  editor.commands?.focus?.()
  return null
}
