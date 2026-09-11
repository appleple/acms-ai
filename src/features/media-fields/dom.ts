export type MediaGeneratedFields = Partial<{
  file_name: string
  caption: string
  alt: string
  memo: string
  tags: string[]
}>

const FIELD_IDS = {
  caption: 'media-modal-caption-',
  alt: 'media-modal-alt-',
  memo: 'media-modal-memo-',
} as const

function setFormValue(element: HTMLInputElement | HTMLTextAreaElement, value: string): void {
  element.value = value
  element.dispatchEvent(new Event('input', { bubbles: true }))
  element.dispatchEvent(new Event('change', { bubbles: true }))
}

export function applyTextFields(
  mediaId: number,
  fields: MediaGeneratedFields,
  root: ParentNode = document
): void {
  if (typeof fields.file_name === 'string' && fields.file_name !== '') {
    const input = root.querySelector<HTMLInputElement>(`#media-modal-filename-${mediaId}`)
    if (input) {
      const dot = input.value.lastIndexOf('.')
      const extension = dot > 0 ? input.value.slice(dot) : ''
      setFormValue(input, `${fields.file_name}${extension}`)
    }
  }

  for (const [key, prefix] of Object.entries(FIELD_IDS)) {
    const value = fields[key as keyof typeof FIELD_IDS]
    if (typeof value !== 'string' || value === '') continue
    const input = root.querySelector<HTMLInputElement | HTMLTextAreaElement>(`#${prefix}${mediaId}`)
    if (input) setFormValue(input, value)
  }
}

function tagNames(value: string): string[] {
  return value.split(',').map((tag) => tag.trim()).filter(Boolean)
}

function wait(milliseconds: number): Promise<void> {
  return new Promise((resolve) => window.setTimeout(resolve, milliseconds))
}

/**
 * a-blog cms 標準の creatable React Select を通してタグを追加する。
 * 既存タグは消去せず、1件ごとに hidden input への反映を確認する。
 */
export async function appendTags(root: HTMLElement, generatedTags: string[]): Promise<void> {
  const hidden = root.querySelector<HTMLInputElement>('input[name="media_label"]')
  const select = hidden?.parentElement?.querySelector<HTMLInputElement>('input[role="combobox"]')
  if (!hidden || !select) {
    throw new Error('タグ入力欄を取得できませんでした。')
  }

  const known = new Set(tagNames(hidden.value))
  for (const tag of generatedTags) {
    if (known.has(tag)) continue

    select.focus()
    select.value = tag
    select.dispatchEvent(new Event('input', { bubbles: true }))
    await wait(50)
    select.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', code: 'Enter', bubbles: true }))
    select.dispatchEvent(new KeyboardEvent('keyup', { key: 'Enter', code: 'Enter', bubbles: true }))
    await wait(100)

    if (!tagNames(hidden.value).includes(tag)) {
      throw new Error(`タグ「${tag}」を入力欄に反映できませんでした。`)
    }
    known.add(tag)
  }
  select.blur()
}

export function isGeneratedFields(value: unknown): value is { fields: MediaGeneratedFields } {
  if (!value || typeof value !== 'object' || !('fields' in value)) return false
  const fields = (value as { fields: unknown }).fields
  if (!fields || typeof fields !== 'object' || Array.isArray(fields)) return false

  return Object.entries(fields).every(([key, item]) => {
    if (key === 'tags') return Array.isArray(item) && item.every((tag) => typeof tag === 'string')
    return ['file_name', 'caption', 'alt', 'memo'].includes(key) && typeof item === 'string'
  })
}

export function responseError(value: unknown): string | null {
  if (!value || typeof value !== 'object' || !('message' in value)) return null
  const message = (value as { message?: unknown }).message
  return typeof message === 'string' && message.trim() !== '' ? message : null
}
