import { beforeEach, describe, expect, it } from 'vitest'
import { buildEntryAiSlots, readEntryAiEnabledState } from '../features/entry-ai/dom'

function setEntryFormDom(): void {
  document.body.innerHTML = `
    <div id="js-acms-ai" data-title-enabled="true" data-tag-enabled="false"></div>
    <table>
      <tbody>
        <tr id="entry-title-display">
          <th>タイトル</th>
          <td>
            <input id="entry-title" type="text">
            <div role="alert"></div>
          </td>
        </tr>
        <tr id="entry-tag-display">
          <th>タグ</th>
          <td><acms-tag-select></acms-tag-select></td>
        </tr>
      </tbody>
    </table>
  `
}

describe('readEntryAiEnabledState', () => {
  beforeEach(setEntryFormDom)

  it('data属性のtrue/falseを機能フラグへ変換する', () => {
    const root = document.getElementById('js-acms-ai') as HTMLElement

    expect(readEntryAiEnabledState(root)).toEqual({
      titleEnabled: true,
      tagEnabled: false,
    })
  })

  it('data属性がない旧テンプレートでは両機能を有効にする', () => {
    const root = document.createElement('div')

    expect(readEntryAiEnabledState(root)).toEqual({
      titleEnabled: true,
      tagEnabled: true,
    })
  })
})

describe('buildEntryAiSlots', () => {
  beforeEach(setEntryFormDom)

  it('有効なフィールドだけにボタンと結果のスロットを構築する', () => {
    const slots = buildEntryAiSlots({ titleEnabled: true, tagEnabled: false })

    expect(slots.titleButton).toBe(document.getElementById('entry-ai-title-button'))
    expect(slots.titleResult).toBe(document.getElementById('entry-ai-title-result'))
    expect(slots.tagButton).toBeNull()
    expect(slots.tagResult).toBeNull()
    expect(document.querySelector('#entry-title-display + tr #entry-ai-title-result')).not.toBeNull()
    expect(slots.titleResult).toHaveAttribute('aria-live', 'polite')
  })

  it('複数回呼ばれても同じスロットを再利用する', () => {
    const enabled = { titleEnabled: true, tagEnabled: true }
    const first = buildEntryAiSlots(enabled)
    const second = buildEntryAiSlots(enabled)

    expect(second).toEqual(first)
    expect(document.querySelectorAll('#entry-ai-title-button')).toHaveLength(1)
    expect(document.querySelectorAll('#entry-ai-title-result')).toHaveLength(1)
    expect(document.querySelectorAll('#entry-ai-tag-button')).toHaveLength(1)
    expect(document.querySelectorAll('#entry-ai-tag-result')).toHaveLength(1)
  })

  it('対象フィールドがない画面では空のスロットを返す', () => {
    document.body.innerHTML = ''

    expect(buildEntryAiSlots({ titleEnabled: true, tagEnabled: true })).toEqual({
      titleButton: null,
      titleResult: null,
      tagButton: null,
      tagResult: null,
    })
  })
})
