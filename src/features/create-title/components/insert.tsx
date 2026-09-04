import { useState, useEffect, useCallback } from 'react'

interface InsertProps {
  data: string
  onInserted?: () => void
}

const Insert = ({ data, onInserted }: InsertProps) => {
  const [selectedElement, setSelectedElement] = useState<HTMLInputElement | null>(null)

  useEffect(() => {
    // #entry-title はページ初期描画時に存在し、動的に追加されることはないためマウント時のみ取得する
    const el = document.querySelector('#entry-title')
    if (el && el.tagName.toLowerCase() === 'input') {
      setSelectedElement(el as HTMLInputElement)
    }
  }, [])

  const onInsertHandler = useCallback((e: { preventDefault: () => void }) => {
    e.preventDefault()
    // 未選択（空）のときは適用しない（タイトルを空で上書きしないため）
    if (!data) return
    if (selectedElement && selectedElement.tagName.toLowerCase() === 'input') {
      selectedElement.value = data
      selectedElement.dispatchEvent(new Event('input', { bubbles: true }))
      selectedElement.dispatchEvent(new Event('change', { bubbles: true }))
      const entryTitleDisplay = document.getElementById('entryForm')
      if (entryTitleDisplay) {
        entryTitleDisplay.scrollIntoView({ behavior: 'smooth' })
      }
      onInserted?.()
    }
  }, [selectedElement, data, onInserted])

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
