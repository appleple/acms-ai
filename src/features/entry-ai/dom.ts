import styles from '../../css/styles.module.css'

export interface EntryAiSlots {
  titleButton: HTMLElement | null
  titleResult: HTMLElement | null
  tagButton: HTMLElement | null
  tagResult: HTMLElement | null
}

export interface EntryAiEnabledState {
  titleEnabled: boolean
  tagEnabled: boolean
}

const SLOT_IDS = {
  titleButton: 'entry-ai-title-button',
  titleResult: 'entry-ai-title-result',
  tagButton: 'entry-ai-tag-button',
  tagResult: 'entry-ai-tag-result',
} as const

/** data 属性がない旧テンプレートでは、後方互換のため両機能を有効として扱う。 */
export function readEntryAiEnabledState(root: HTMLElement): EntryAiEnabledState {
  return {
    titleEnabled: root.dataset.titleEnabled !== 'false',
    tagEnabled: root.dataset.tagEnabled !== 'false',
  }
}

function layoutFieldCell(cell: HTMLElement, control: HTMLElement | null): void {
  cell.classList.add(styles.entryAiFieldCell)
  control?.classList.add(styles.entryAiFieldControl)
}

function ensureButtonSlot(cell: HTMLElement, id: string): HTMLElement {
  const existing = document.getElementById(id)
  if (existing instanceof HTMLElement) {
    return existing
  }

  const slot = document.createElement('span')
  slot.id = id
  slot.className = styles.entryAiButtonSlot
  cell.appendChild(slot)
  return slot
}

function ensureResultSlot(row: HTMLElement, id: string): HTMLElement {
  const existing = document.getElementById(id)
  if (existing instanceof HTMLElement) {
    return existing
  }

  const resultRow = document.createElement('tr')
  resultRow.className = styles.entryAiResultRow

  const labelCell = document.createElement('th')
  labelCell.setAttribute('aria-hidden', 'true')

  const resultCell = document.createElement('td')
  resultCell.id = id
  resultCell.setAttribute('aria-live', 'polite')

  resultRow.append(labelCell, resultCell)
  row.after(resultRow)
  return resultCell
}

/**
 * エントリーフォームへポータル用スロットを構築する。
 * バンドルが誤って複数回実行されても、既存スロットを再利用して DOM を重複させない。
 */
export function buildEntryAiSlots({
  titleEnabled,
  tagEnabled,
}: EntryAiEnabledState): EntryAiSlots {
  const slots: EntryAiSlots = {
    titleButton: null,
    titleResult: null,
    tagButton: null,
    tagResult: null,
  }

  if (titleEnabled) {
    const input = document.getElementById('entry-title')
    const cell = input?.closest('td')
    const row = input?.closest('tr')
    if (input instanceof HTMLElement && cell instanceof HTMLElement && row instanceof HTMLElement) {
      layoutFieldCell(cell, input)
      slots.titleButton = ensureButtonSlot(cell, SLOT_IDS.titleButton)
      slots.titleResult = ensureResultSlot(row, SLOT_IDS.titleResult)
    }
  }

  if (tagEnabled) {
    const row = document.getElementById('entry-tag-display')
    const cell = row?.querySelector('td')
    if (row instanceof HTMLElement && cell instanceof HTMLElement) {
      const tagControl = cell.querySelector<HTMLElement>('acms-tag-select, .js-admin-tag-select')
      layoutFieldCell(cell, tagControl)
      slots.tagButton = ensureButtonSlot(cell, SLOT_IDS.tagButton)
      slots.tagResult = ensureResultSlot(row, SLOT_IDS.tagResult)
    }
  }

  return slots
}
