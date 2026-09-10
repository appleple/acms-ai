import { fireEvent, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ResultTitle } from '../features/create-title'

describe('ResultTitle', () => {
  beforeEach(() => {
    document.body.innerHTML = '<input id="entry-title" value="元のタイトル">'
  })

  it('選択した候補をタイトルへ反映し、input/changeイベントを通知する', () => {
    const titleInput = document.getElementById('entry-title') as HTMLInputElement
    const onInput = vi.fn()
    const onChange = vi.fn()
    titleInput.addEventListener('input', onInput)
    titleInput.addEventListener('change', onChange)

    render(
      <ResultTitle
        id={1}
        data={[{ content: '新しいタイトル' }]}
        resultType="radio"
        byMode="createTitle"
      />
    )

    const applyButton = screen.getByRole('button', { name: 'このタイトルにする' })
    expect(applyButton).toBeDisabled()

    const radio = screen.getByRole('radio', { name: '新しいタイトル' })
    fireEvent.click(radio)
    fireEvent.click(applyButton)

    expect(titleInput.value).toBe('新しいタイトル')
    expect(onInput).toHaveBeenCalledOnce()
    expect(onChange).toHaveBeenCalledOnce()
    expect(screen.queryByRole('radio', { name: '新しいタイトル' })).not.toBeInTheDocument()
  })

  it('閉じる操作で候補DOMを取り除く', () => {
    render(
      <ResultTitle
        id={1}
        data={[{ content: '候補' }]}
        resultType="radio"
        byMode="createTitle"
      />
    )

    fireEvent.click(screen.getByRole('button', { name: '候補を閉じる' }))

    expect(screen.queryByRole('radio', { name: '候補' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '候補を閉じる' })).not.toBeInTheDocument()
  })

  it('空と重複を除いた候補だけを表示する', () => {
    render(
      <ResultTitle
        id={1}
        data={[{ content: '候補' }, { content: ' 候補 ' }, { content: '' }]}
        resultType="radio"
        byMode="createTitle"
      />
    )

    expect(screen.getAllByRole('radio')).toHaveLength(1)
  })
})
