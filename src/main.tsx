import { ArtificialIntelligence, type EntryAiSlots } from './container/artificial-intelligence'
import { PromptContextProvider } from './stores/use-prompt'
import { EntryContextProvider } from './stores/use-entry'
import { render } from './utils/react'
import { DispatchLiteEditorChatDrawer } from './dispatch/dispatch-lite-editor-chat-drawer'
import { defineAcmsAiAssistantButton } from './elements/acms-ai-assistant-button'
import './elements/acms-ai-assistant-button.css'
import styles from './css/styles.module.css'

// カスタム要素はできるだけ早く登録する（どの管理画面でも <acms-ai-assistant-button> を使えるように）
defineAcmsAiAssistantButton()

/**
 * エントリーフォームのフィールド行末に「AI生成」ボタン用スロットを差し込み、
 * その直下に結果表示用の行を挿入する。React はこれらのスロットへポータルで描画する。
 */
function buildEntryAiSlots(titleEnabled: boolean, tagEnabled: boolean): EntryAiSlots {
  const slots: EntryAiSlots = {
    titleButton: null,
    titleResult: null,
    tagButton: null,
    tagResult: null,
  }

  // フィールドの td を「入力欄 + ボタン」が同じ行に並ぶよう flex 化する
  const layoutFieldCell = (cell: HTMLElement, mainEl: HTMLElement | null) => {
    cell.style.display = 'flex'
    cell.style.alignItems = 'center'
    cell.style.flexWrap = 'wrap'
    cell.style.gap = '0.5rem'
    if (mainEl) {
      mainEl.style.flex = '1 1 16rem'
      mainEl.style.width = 'auto'
      mainEl.style.minWidth = '0'
    }
  }

  // 対象行の直下に「空ラベル + 結果」の行を1つ挿入し、結果用 td を返す
  const insertResultRow = (row: HTMLElement, id: string): HTMLElement => {
    const existing = document.getElementById(id)
    if (existing) {
      return existing
    }
    const tr = document.createElement('tr')
    // 候補が無い間はセル余白で空白行ができてしまうため、専用クラスで余白を0にし
    // 中身が入ったときだけ高さを持つようにする。
    tr.className = styles.entryAiResultRow
    const th = document.createElement('th')
    const td = document.createElement('td')
    td.id = id
    tr.appendChild(th)
    tr.appendChild(td)
    row.after(tr)
    return td
  }

  if (titleEnabled) {
    const input = document.getElementById('entry-title')
    const cell = input?.closest('td') as HTMLElement | null
    const row = input?.closest('tr') as HTMLElement | null
    if (input && cell && row) {
      layoutFieldCell(cell, input as HTMLElement)
      const btn = document.createElement('span')
      cell.appendChild(btn)
      slots.titleButton = btn
      slots.titleResult = insertResultRow(row, 'entry-ai-title-result')
    }
  }

  if (tagEnabled) {
    const row = document.getElementById('entry-tag-display')
    const cell = row?.querySelector('td') as HTMLElement | null
    if (row && cell) {
      const tagWrap = cell.querySelector('.js-admin-tag-select') as HTMLElement | null
      layoutFieldCell(cell, tagWrap)
      const btn = document.createElement('span')
      cell.appendChild(btn)
      slots.tagButton = btn
      slots.tagResult = insertResultRow(row, 'entry-ai-tag-result')
    }
  }

  return slots
}

// エントリー編集のタイトル/タグ生成 UI は #js-acms-ai がある画面でのみマウントする
// （Web Component 単体で読み込まれる他の管理画面には存在しないため）
const acmsAIRoot = document.getElementById('js-acms-ai')
if (acmsAIRoot) {
  // 「有効」設定（ai_title_valid / ai_tag_valid）に応じて、各機能の表示を切り替える。
  // data 属性が無い場合は後方互換で両方表示する。
  const titleEnabled = acmsAIRoot.dataset.titleEnabled !== 'false'
  const tagEnabled = acmsAIRoot.dataset.tagEnabled !== 'false'
  const slots = buildEntryAiSlots(titleEnabled, tagEnabled)
  render(
    <PromptContextProvider>
      <EntryContextProvider>
        <ArtificialIntelligence titleEnabled={titleEnabled} tagEnabled={tagEnabled} slots={slots} />
      </EntryContextProvider>
    </PromptContextProvider>,
    acmsAIRoot
  )
}

// ライトエディタのAIアシスタントボタンは、ライトエディタ設定がある画面でのみ追加する
window.ACMS.Ready(() => {
  if (window.ACMS?.Config?.LiteEditorConf?.btnOptions) {
    DispatchLiteEditorChatDrawer()
  }
})
