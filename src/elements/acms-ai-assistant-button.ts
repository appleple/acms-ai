// <acms-ai-assistant-button> Web Component (Light DOM)
// ドロワーチャットをトリガーするカスタム要素のラッパー。
// Light DOM で <button> を扱うため、acms-admin.css 等の
// 外部スタイル（.btn, .spinner-border など）をそのまま利用できる。
//
// 属性:
//   target           (必須) 参照する textarea の CSS セレクター
//   insert-target    (任意) 挿入先の textarea セレクター (省略時は target と同じ)
//   prompt           (任意) 最初のメッセージとして送るプロンプト文字列
//   description      (任意) チャット欄の説明文
//   drawer-use       (任意) "false" を指定するとドロワーを開かずサイレント実行
//
// 例:
//   <acms-ai-assistant-button target="#body" prompt="英語に翻訳してください。">
//     <button type="button" class="btn btn-default">英訳する</button>
//   </acms-ai-assistant-button>
//
// 子要素に <button> があればそれを使い、無ければ自動で生成する。
// ローディング中は host に [loading] 属性が付与され、
// 内部にローディング表示用の <span class="acms-ai-assistant-button__loading"> が追加される。

import { openChatDrawer } from '../features/chat'

const LOADING_CLASS = 'acms-ai-assistant-button__loading'

class AcmsAiAssistantButton extends HTMLElement {
  private isLoading = false
  private button: HTMLButtonElement | null = null
  private loadingEl: HTMLElement | null = null

  connectedCallback() {
    // カスタム要素はパーサが開始タグを読んだ瞬間に connectedCallback が走ることがあり、
    // その時点では子要素（<button>）がまだ未パースで取得できない。
    // ドキュメントの読み込みが完了している場合は即実行、そうでなければ次のタイミングまで待つ。
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', this.init, { once: true })
    } else {
      // マイクロタスクで遅延させ、同期パース中の子要素挿入完了を待つ
      queueMicrotask(this.init)
    }
  }

  private init = () => {
    if (this.button) return // 二重初期化防止

    this.button = this.querySelector(':scope > button')
    if (!this.button) {
      const button = document.createElement('button')
      button.type = 'button'
      button.className = 'acms-admin-btn'
      while (this.firstChild) {
        button.appendChild(this.firstChild)
      }
      this.appendChild(button)
      this.button = button
    }

    this.loadingEl = this.button.querySelector(`.${LOADING_CLASS}`)
    if (!this.loadingEl) {
      const span = document.createElement('span')
      span.className = LOADING_CLASS
      span.hidden = true
      span.innerHTML =
        '<span class="acms-ai-assistant-button__spinner" role="status" aria-hidden="true"></span>' +
        '<span class="acms-ai-assistant-button__sr-only">Loading...</span>'
      this.button.appendChild(span)
      this.loadingEl = span
    }

    this.button.addEventListener('click', this.handleClick)
  }

  disconnectedCallback() {
    this.button?.removeEventListener('click', this.handleClick)
  }

  private dispatch(name: string) {
    this.dispatchEvent(new CustomEvent(name, { bubbles: true, composed: true }))
  }

  private setLoading(loading: boolean) {
    this.isLoading = loading
    this.toggleAttribute('loading', loading)
    if (this.button) {
      this.button.disabled = loading
      this.button.setAttribute('aria-busy', String(loading))
    }
    if (this.loadingEl) {
      this.loadingEl.hidden = !loading
    }
  }

  private handleClick = (e: Event) => {
    if (this.isLoading) {
      e.preventDefault()
      e.stopPropagation()
      return
    }
    e.preventDefault()
    e.stopPropagation()

    const targetSelector = this.getAttribute('target')
    if (!targetSelector) return

    const showDrawer = this.getAttribute('drawer-use') !== 'false'

    // サイレントモードのみボタン側でローディングを管理する。
    // ドロワーモードはドロワー内に独自のローディングUIがあるため不要。
    if (!showDrawer) {
      this.setLoading(true)
    }

    const started = openChatDrawer({
      targetSelector,
      insertSelector: this.getAttribute('insert-target'),
      prompt: this.getAttribute('prompt') ?? undefined,
      description: this.getAttribute('description') ?? undefined,
      showDrawer,
      onDone: showDrawer ? undefined : () => this.setLoading(false),
      onRequestStart: showDrawer ? undefined : () => this.dispatch('acms-ai:request-start'),
      onRequestEnd: showDrawer ? undefined : () => this.dispatch('acms-ai:request-end'),
      onBeforeInsert: showDrawer ? undefined : () => this.dispatch('acms-ai:before-insert'),
      onAfterInsert: showDrawer ? undefined : () => this.dispatch('acms-ai:after-insert'),
    })
    if (!showDrawer && !started) {
      this.setLoading(false)
    }
  }
}

export function defineAcmsAiAssistantButton(): void {
  if (!customElements.get('acms-ai-assistant-button')) {
    customElements.define('acms-ai-assistant-button', AcmsAiAssistantButton)
  }
}
