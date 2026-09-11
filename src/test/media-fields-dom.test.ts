import { beforeEach, describe, expect, it } from 'vitest'
import { appendTags, applyTextFields, isGeneratedFields, responseError } from '../features/media-fields/dom'

function setupCreatableSelect(initialTags = '') {
  document.body.innerHTML = `
    <div id="tags-root">
      <input role="combobox" aria-expanded="false" value="">
      <input type="hidden" name="media_label" value="${initialTags}">
    </div>
    <div id="tag-listbox" role="listbox">
      <div id="tag-option" role="option"></div>
    </div>
  `

  const root = document.getElementById('tags-root')
  const select = root?.querySelector<HTMLInputElement>('input[role="combobox"]')
  const hidden = root?.querySelector<HTMLInputElement>('input[name="media_label"]')
  if (!root || !select || !hidden) throw new Error('テスト用のタグ入力欄を取得できません。')

  const descriptor = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')
  if (!descriptor?.get || !descriptor.set) throw new Error('input.value の setter を取得できません。')

  let trackedValue = select.value
  Object.defineProperty(select, 'value', {
    configurable: true,
    get: () => descriptor.get?.call(select),
    set: (value: string) => {
      trackedValue = String(value)
      descriptor.set?.call(select, value)
    },
  })

  select.addEventListener('input', () => {
    if (select.value === trackedValue) return
    trackedValue = select.value
    queueMicrotask(() => {
      select.setAttribute('aria-expanded', 'true')
      select.setAttribute('aria-controls', 'tag-listbox')
    })
  })
  select.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter' || select.getAttribute('aria-expanded') !== 'true') return
    hidden.value = [...new Set([...initialTags.split(',').filter(Boolean), select.value])].join(',')
    initialTags = hidden.value
    descriptor.set?.call(select, '')
    trackedValue = ''
    select.setAttribute('aria-expanded', 'false')
    select.removeAttribute('aria-controls')
  })

  return { root, hidden }
}

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

  it('React管理下の入力を通して既存候補と新規候補を追加する', async () => {
    const { root, hidden } = setupCreatableSelect('既存タグ')

    await appendTags(root, ['既存タグ', '登録済み候補', ' 新規タグ ', '新規タグ'])

    expect(hidden.value).toBe('既存タグ,登録済み候補,新規タグ')
  })

  it('サーバー応答を厳密に判定する', () => {
    expect(isGeneratedFields({ fields: { alt: '猫', tags: ['動物'] } })).toBe(true)
    expect(isGeneratedFields({ fields: { unknown: '猫' } })).toBe(false)
    expect(isGeneratedFields({ fields: { tags: '動物' } })).toBe(false)
    expect(responseError({ message: 'エラー' })).toBe('エラー')
  })
})
