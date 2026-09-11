import { postRequest } from '../../api/fetcher'
import { appendTags, applyTextFields, isGeneratedFields, responseError } from './dom'

interface MediaItem {
  media_id: number
  media_type: string
}

interface FillProps {
  item: MediaItem
}

const LABELS = {
  file_name: 'ファイル名',
  caption: 'キャプション',
  alt: '代替テキスト',
  memo: 'メモ',
  tags: 'タグ',
} as const

type Target = keyof typeof LABELS

function enabledTargets(): Target[] {
  const marker = document.getElementById('js-acms-ai-media')
  if (!marker) return []
  return (Object.keys(LABELS) as Target[]).filter((target) => {
    const dataKey = target === 'file_name' ? 'fileNameEnabled' : `${target}Enabled`
    return marker.dataset[dataKey] === 'on'
  })
}

function setStatus(row: HTMLElement, message: string, error = false): void {
  const status = row.querySelector<HTMLElement>('[data-acms-ai-media-status]')
  if (!status) return
  status.textContent = message
  status.className = error
    ? 'acms-admin-text-danger acms-admin-margin-top-mini'
    : 'acms-admin-text-success acms-admin-margin-top-mini'
}

async function generate(event: React.MouseEvent<HTMLButtonElement>, mediaId: number): Promise<void> {
  const button = event.currentTarget
  const row = button.closest<HTMLElement>('[data-acms-ai-media-row]')
  const modal = button.closest<HTMLElement>('.acms-admin-modal-content')
  if (!row || !modal) return

  const targets = Array.from(row.querySelectorAll<HTMLInputElement>('input[data-acms-ai-media-target]:checked'))
    .map((input) => input.value)
  if (targets.length === 0) {
    setStatus(row, '生成する項目を選択してください。', true)
    return
  }

  button.disabled = true
  button.setAttribute('aria-busy', 'true')
  const previousLabel = button.textContent
  button.textContent = '生成中…'
  setStatus(row, '')

  try {
    const response = await postRequest({
      url: window.ACMS.Config.root,
      data: { mode: 'generateMediaFields', mid: mediaId, targets: targets.join(',') },
      exec: 'ACMS_POST_AI_GenerateMediaFields',
      formToken: window.csrfToken,
    })
    if (!isGeneratedFields(response)) {
      throw new Error(responseError(response) ?? '生成結果の形式が正しくありません。')
    }

    // タグ連携が失敗しても、正常に生成されたテキスト項目は失わない。
    applyTextFields(mediaId, response.fields, modal)
    if (response.fields.tags?.length) await appendTags(modal, response.fields.tags)
    setStatus(row, '候補を入力しました。内容を確認し、メディアの更新ボタンで保存してください。')
  } catch (error) {
    setStatus(row, error instanceof Error ? error.message : '画像解析に失敗しました。', true)
  } finally {
    button.disabled = false
    button.removeAttribute('aria-busy')
    button.textContent = previousLabel
  }
}

export function MediaFields({ item }: FillProps) {
  const targets = enabledTargets()
  if (item.media_type !== 'image' || targets.length === 0) return null

  return (
    <table
      className="acms-admin-media-table-edit acms-admin-margin-top-small"
      data-acms-ai-media-row=""
    >
      <tbody>
        <tr>
          <th>
            画像からAI生成
            <i
              className="acms-admin-icon-tooltip acms-admin-margin-left-mini js-acms-tooltip-hover"
              data-acms-position="top"
              data-acms-tooltip="選択した項目の候補をAIで生成します。生成後は内容を確認し、メディアの更新ボタンで保存してください。"
            />
          </th>
          <td>
            <div className="acms-admin-form-checkbox">
              {targets.map((target) => {
                const id = `acms-ai-media-${target}-${item.media_id}`
                return (
                  <span key={target} className="acms-admin-margin-right-small">
                    <input
                      type="checkbox"
                      id={id}
                      value={target}
                      data-acms-ai-media-target=""
                      defaultChecked={target === 'alt'}
                    />
                    <label htmlFor={id}><i className="acms-admin-ico-checkbox" />{LABELS[target]}</label>
                  </span>
                )
              })}
            </div>
            <button
              type="button"
              className="acms-admin-btn acms-admin-btn-admin acms-admin-margin-top-mini"
              onClick={(event) => void generate(event, item.media_id)}
            >
              選択項目をAI生成
            </button>
            <p data-acms-ai-media-status="" aria-live="polite" />
          </td>
        </tr>
      </tbody>
    </table>
  )
}

export function registerMediaFields(): void {
  const plugins = window.ACMS?.plugins
  if (!document.getElementById('js-acms-ai-media') || !plugins || plugins.get('acms-ai-media-fields')) return

  plugins.register('acms-ai-media-fields', {
    version: '1.0.0',
    setup: ({ ui }) => {
      ui.fill('media.edit-modal.fields', plugins.withFillProps(MediaFields))
    },
  })
}
