import { beforeEach, describe, expect, it } from 'vitest'
import { applyTextFields, isGeneratedFields, responseError } from '../features/media-fields/dom'

describe('media fields DOM integration', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <input id="media-modal-filename-42" value="old-name.jpg">
      <input id="media-modal-caption-42" value="old caption">
      <textarea id="media-modal-alt-42">old alt</textarea>
      <textarea id="media-modal-memo-42">old memo</textarea>
    `
  })

  it('ファイル名の拡張子を保って、生成項目だけを反映する', () => {
    applyTextFields(42, { file_name: 'new-name', alt: '新しい代替テキスト' })

    expect(document.querySelector<HTMLInputElement>('#media-modal-filename-42')?.value).toBe('new-name.jpg')
    expect(document.querySelector<HTMLTextAreaElement>('#media-modal-alt-42')?.value).toBe('新しい代替テキスト')
    expect(document.querySelector<HTMLInputElement>('#media-modal-caption-42')?.value).toBe('old caption')
  })

  it('サーバー応答を厳密に判定する', () => {
    expect(isGeneratedFields({ fields: { alt: '猫', tags: ['動物'] } })).toBe(true)
    expect(isGeneratedFields({ fields: { unknown: '猫' } })).toBe(false)
    expect(isGeneratedFields({ fields: { tags: '動物' } })).toBe(false)
    expect(responseError({ message: 'エラー' })).toBe('エラー')
  })
})
