import { beforeEach, describe, expect, it } from 'vitest'
import { collectEntryUnitHtml } from '../utils'

describe('collectEntryUnitHtml', () => {
  beforeEach(() => {
    document.body.replaceChildren()
  })

  it('旧ライトエディタのHTMLを収集する', () => {
    document.body.innerHTML = `
      <div class="entryFormLiteEditor"><p>旧テキスト</p></div>
    `

    expect(collectEntryUnitHtml()).toBe('<p>旧テキスト</p>\n')
  })

  it('ブロックエディタのhidden inputからHTMLを収集する', () => {
    document.body.innerHTML = `
      <input type="hidden" name="block-editor_html_1">
    `
    document.querySelector<HTMLInputElement>('input')!.value = '<h2>見出し</h2>'

    expect(collectEntryUnitHtml()).toBe('<h2>見出し</h2>\n')
  })

  it('両形式が混在してもDOM上のユニット順を維持する', () => {
    document.body.innerHTML = `
      <div class="entryFormLiteEditor"><p>最初</p></div>
      <input type="hidden" name="block-editor_html_2">
      <div class="entryFormLiteEditor"><p>最後</p></div>
    `
    document.querySelector<HTMLInputElement>('input')!.value = '<p>中央</p>'

    expect(collectEntryUnitHtml()).toBe('<p>最初</p>\n<p>中央</p>\n<p>最後</p>\n')
  })

  it('非表示ユニットの本文を除外する', () => {
    document.body.innerHTML = `
      <div class="entryFormColumnItem-hidden">
        <div class="entryFormLiteEditor"><p>非表示の旧テキスト</p></div>
        <input type="hidden" name="block-editor_html_3" value="非表示のブロック">
      </div>
      <div class="entryFormLiteEditor"><p>表示中</p></div>
    `

    expect(collectEntryUnitHtml()).toBe('<p>表示中</p>\n')
  })

  it('空のブロックエディタを除外する', () => {
    document.body.innerHTML = `
      <input type="hidden" name="block-editor_html_4" value="">
    `

    expect(collectEntryUnitHtml()).toBe('')
  })

  it('同じ名前でもhiddenではないinputを除外する', () => {
    document.body.innerHTML = `
      <input type="text" name="block-editor_html_preview" value="収集しない">
    `

    expect(collectEntryUnitHtml()).toBe('')
  })
})
