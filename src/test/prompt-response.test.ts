import { describe, expect, it } from 'vitest'
import { normalizePromptResponses, promptErrorMessage } from '../features/entry-ai/prompt-response'

describe('normalizePromptResponses', () => {
  it('文字列の候補だけをトリムし、空文字と重複を除く', () => {
    expect(normalizePromptResponses([
      { content: ' 候補A ' },
      { content: '' },
      { content: '候補A' },
      { content: '候補B' },
      { other: 'invalid' },
      null,
    ])).toEqual([
      { content: '候補A' },
      { content: '候補B' },
    ])
  })

  it('配列以外の応答は空配列として扱う', () => {
    expect(normalizePromptResponses({ content: '候補' })).toEqual([])
    expect(normalizePromptResponses(null)).toEqual([])
  })
})

describe('promptErrorMessage', () => {
  it('errorCodeを持つ応答のメッセージだけを返す', () => {
    expect(promptErrorMessage({ errorCode: 500, message: '設定がありません。' }, '生成失敗'))
      .toBe('設定がありません。')
    expect(promptErrorMessage({ errorCode: 500, message: '' }, '生成失敗')).toBe('生成失敗')
    expect(promptErrorMessage([{ content: '候補' }], '生成失敗')).toBeNull()
  })
})
