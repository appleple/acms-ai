import { useRef, useEffect, useCallback } from 'react'

interface InsertProps {
  data: string
  onInserted?: () => void
}

const Insert = ({ data, onInserted }: InsertProps) => {
  const selectedElement = useRef<HTMLInputElement | null>(null)

  useEffect(() => {
    // #entry-title はページ初期描画時に存在し、動的に追加されることはないためマウント時のみ取得する
    selectedElement.current = document.querySelector<HTMLInputElement>('#entry-title')
  }, [])

  const onInsertHandler = useCallback((e: { preventDefault: () => void }) => {
    e.preventDefault()
    // 未選択（空）のときは適用しない（タイトルを空で上書きしないため）
    if (!data) return
    const input = selectedElement.current
    if (input) {
      input.value = data
      input.dispatchEvent(new Event('input', { bubbles: true }))
      input.dispatchEvent(new Event('change', { bubbles: true }))
      const entryTitleDisplay = document.getElementById('entryForm')
      if (entryTitleDisplay) {
        entryTitleDisplay.scrollIntoView({ behavior: 'smooth' })
      }
      onInserted?.()
    }
  }, [data, onInserted])

  return (
    <button
      type='button'
      className='acms-admin-btn acms-admin-btn-admin-info acms-admin-inline-block'
      onClick={onInsertHandler}
      disabled={!data}
    >
      このタイトルにする
    </button>
  )
}

export default Insert
