import { fireEvent, render, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { postRequest } from '../api/fetcher'
import { MediaFields } from '../features/media-fields'

vi.mock('../api/fetcher')

describe('MediaFields', () => {
  beforeEach(() => {
    vi.mocked(postRequest).mockReset()
    window.ACMS = {
      Ready: vi.fn(),
      Config: { root: '/', bid: 1 },
      addListener: vi.fn(),
    }
    window.csrfToken = 'token'
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

    const aiTable = mediaEdit.querySelector(
      ':scope > table.acms-admin-media-table-edit.acms-admin-margin-top-small[data-acms-ai-media-row]'
    )
    expect(aiTable).not.toBeNull()
    expect(aiTable?.querySelector(':scope > tbody > tr > th')).toHaveTextContent('画像からAI生成')
    const tooltip = aiTable?.querySelector('th > .acms-admin-icon-tooltip.js-acms-tooltip-hover')
    expect(tooltip).toHaveClass('acms-admin-margin-left-mini')
    expect(tooltip).toHaveAttribute('data-acms-position', 'top')
    expect(tooltip).toHaveAttribute('data-acms-tooltip', expect.stringContaining('AIで生成します'))
    expect(mediaEdit.querySelector(':scope > tr')).toBeNull()
    expect(document.querySelector('tr[data-acms-ai-media-row]')).toBeNull()
  })

  it.each([
    {
      enabled: ['file_name', 'tags', 'caption', 'alt', 'memo'],
      expected: ['ファイル名', 'タグ', 'キャプション', '代替テキスト', 'メモ'],
    },
    {
      enabled: ['file_name', 'caption', 'memo'],
      expected: ['ファイル名', 'キャプション', 'メモ'],
    },
  ])('有効な生成対象をメディアフィールド順に表示する', ({ enabled, expected }) => {
    const marker = document.getElementById('js-acms-ai-media')
    const mediaEdit = document.getElementById('media-edit')
    if (!marker || !mediaEdit) throw new Error('テスト用の要素を取得できません。')

    marker.removeAttribute('data-alt-enabled')
    for (const target of enabled) {
      const dataKey = target === 'file_name' ? 'fileNameEnabled' : `${target}Enabled`
      marker.dataset[dataKey] = 'on'
    }

    render(<MediaFields item={{ media_id: 42, media_type: 'image' }} />, {
      container: mediaEdit,
    })

    const labels = Array.from(mediaEdit.querySelectorAll<HTMLLabelElement>('label'))
      .map((label) => label.textContent)
    expect(labels).toEqual(expected)
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

  it('タグ入力欄への反映が失敗しても生成済みテキストを反映する', async () => {
    document.body.innerHTML = `
      <div id="js-acms-ai-media" data-alt-enabled="on" data-tags-enabled="on"></div>
      <div class="acms-admin-modal-content">
        <textarea id="media-modal-alt-42">変更前</textarea>
        <div id="media-edit"></div>
      </div>
    `
    vi.mocked(postRequest).mockResolvedValue({
      fields: { alt: '生成した代替テキスト', tags: ['生成タグ'] },
    })
    const mediaEdit = document.getElementById('media-edit')
    if (!mediaEdit) throw new Error('テスト用のメディア編集領域を取得できません。')

    render(<MediaFields item={{ media_id: 42, media_type: 'image' }} />, { container: mediaEdit })
    const checkboxes = mediaEdit.querySelectorAll<HTMLInputElement>('input[data-acms-ai-media-target]')
    const button = mediaEdit.querySelector<HTMLButtonElement>('button')
    if (!button) throw new Error('テスト対象の実行ボタンを取得できません。')
    for (const checkbox of checkboxes) {
      if (!checkbox.checked) fireEvent.click(checkbox)
    }
    fireEvent.click(button)

    await waitFor(() => {
      expect(document.querySelector<HTMLTextAreaElement>('#media-modal-alt-42')?.value)
        .toBe('生成した代替テキスト')
      expect(mediaEdit.querySelector('[data-acms-ai-media-status]'))
        .toHaveTextContent('タグ入力欄を取得できませんでした。')
    })
  })
})
