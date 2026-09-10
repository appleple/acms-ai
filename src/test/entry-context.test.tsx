import { act, fireEvent, render, screen } from '@testing-library/react'
import { StrictMode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useEntryContext } from '../stores/entry-context'
import { EntryContextProvider } from '../stores/use-entry'

const EntryTagProbe = () => {
  const { entryTag, addEntryTagData } = useEntryContext()

  return (
    <>
      <output>{entryTag.data.join('|')}</output>
      <button type="button" onClick={() => addEntryTagData('追加タグ')}>追加</button>
      <button type="button" onClick={() => addEntryTagData('京都')}>重複追加</button>
    </>
  )
}

class TestTagSelectElement extends HTMLElement {
  connectedCallback(): void {
    // a-blog cms コアと同じく、定義前に代入された value を公開 setter へ引き継ぐ。
    if (Object.prototype.hasOwnProperty.call(this, 'value')) {
      const value = this.value
      delete (this as Partial<TestTagSelectElement>).value
      this.value = value
    }
  }

  get value(): string {
    return this.querySelector<HTMLInputElement>('#entry-tag-value')?.value ?? ''
  }

  set value(value: string) {
    const input = this.querySelector<HTMLInputElement>('#entry-tag-value')
    if (input) {
      input.value = value
    }
    this.dataset.renderedValue = value
  }
}

describe('EntryContextProvider', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <div id="entry-tag-display">
        <acms-tag-select default-value="京都, 旅行">
          <input type="hidden" id="entry-tag-value">
        </acms-tag-select>
      </div>
    `
  })

  it('既存タグを初期値として読み、AI候補を重複なしで保存値へ反映する', () => {
    const input = document.getElementById('entry-tag-value') as HTMLInputElement
    const onInput = vi.fn()
    const onChange = vi.fn()
    input.addEventListener('input', onInput)
    input.addEventListener('change', onChange)

    render(
      <StrictMode>
        <EntryContextProvider><EntryTagProbe /></EntryContextProvider>
      </StrictMode>
    )
    if (!customElements.get('acms-tag-select')) {
      customElements.define('acms-tag-select', TestTagSelectElement)
    }

    expect(screen.getByText('京都|旅行')).toBeInTheDocument()
    expect(input.value).toBe('京都,旅行')
    expect(document.querySelector('acms-tag-select')).toHaveAttribute('data-rendered-value', '京都,旅行')
    onInput.mockClear()
    onChange.mockClear()
    fireEvent.click(screen.getByRole('button', { name: '重複追加' }))
    expect(input.value).toBe('京都,旅行')

    fireEvent.click(screen.getByRole('button', { name: '追加' }))
    expect(input.value).toBe('京都,旅行,追加タグ')
    expect(onInput).toHaveBeenCalledOnce()
    expect(onChange).toHaveBeenCalledOnce()
  })

  it('a-blog cms側のタグ変更イベントをContextへ同期する', () => {
    const input = document.getElementById('entry-tag-value') as HTMLInputElement
    render(
      <StrictMode>
        <EntryContextProvider><EntryTagProbe /></EntryContextProvider>
      </StrictMode>
    )

    act(() => {
      input.value = '更新後, タグ'
      input.dispatchEvent(new Event('acmsAdminTagChange', { bubbles: true }))
    })

    expect(screen.getByText('更新後|タグ')).toBeInTheDocument()
  })
})
