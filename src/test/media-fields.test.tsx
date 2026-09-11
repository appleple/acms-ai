import { fireEvent, render } from '@testing-library/react'
import { beforeEach, describe, expect, it } from 'vitest'
import { MediaFields } from '../features/media-fields'

describe('MediaFields', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <div id="js-acms-ai-media" data-alt-enabled="on"></div>
      <div class="acms-admin-modal-content">
        <div id="media-edit"></div>
      </div>
    `
  })

  it('Slot直下に有効なテーブル構造を描画する', () => {
    const mediaEdit = document.getElementById('media-edit')
    if (!mediaEdit) throw new Error('テスト用のメディア編集領域を取得できません。')

    render(<MediaFields item={{ media_id: 42, media_type: 'image' }} />, {
      container: mediaEdit,
    })

    const aiTable = mediaEdit.querySelector(':scope > table.acms-admin-media-table-edit[data-acms-ai-media-row]')
    expect(aiTable).not.toBeNull()
    expect(aiTable?.querySelector(':scope > tbody > tr > th')).toHaveTextContent('画像からAI生成')
    expect(mediaEdit.querySelector(':scope > tr')).toBeNull()
    expect(document.querySelector('tr[data-acms-ai-media-row]')).toBeNull()
  })

  it('新しいテーブルルートを起点に選択エラーを表示する', () => {
    const mediaEdit = document.getElementById('media-edit')
    if (!mediaEdit) throw new Error('テスト用のメディア編集領域を取得できません。')

    render(<MediaFields item={{ media_id: 42, media_type: 'image' }} />, {
      container: mediaEdit,
    })

    const checkbox = mediaEdit.querySelector<HTMLInputElement>('input[data-acms-ai-media-target]')
    const button = mediaEdit.querySelector<HTMLButtonElement>('button')
    if (!checkbox || !button) throw new Error('テスト対象の入力要素を取得できません。')

    fireEvent.click(checkbox)
    fireEvent.click(button)

    expect(mediaEdit.querySelector('[data-acms-ai-media-status]')).toHaveTextContent(
      '生成する項目を選択してください。'
    )
  })

  it('モーダルを開き直してもUIが重複しない', () => {
    const mediaEdit = document.getElementById('media-edit')
    if (!mediaEdit) throw new Error('テスト用のメディア編集領域を取得できません。')

    const firstRender = render(<MediaFields item={{ media_id: 42, media_type: 'image' }} />, {
      container: mediaEdit,
    })
    firstRender.unmount()

    const reopenedMediaEdit = document.createElement('div')
    reopenedMediaEdit.id = 'media-edit'
    mediaEdit.replaceWith(reopenedMediaEdit)
    render(<MediaFields item={{ media_id: 42, media_type: 'image' }} />, {
      container: reopenedMediaEdit,
    })

    expect(document.querySelectorAll('table[data-acms-ai-media-row]')).toHaveLength(1)
  })
})
