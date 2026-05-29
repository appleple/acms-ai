import { useState, useEffect, useCallback } from 'react'

const Insert = ({ data }: { data: string }) => {
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
    if (selectedElement && selectedElement.tagName.toLowerCase() === 'input') {
      selectedElement.value = data
      const entryTitleDisplay = document.getElementById('entryForm')
      if (entryTitleDisplay) {
        entryTitleDisplay.scrollIntoView({ behavior: 'smooth' })
      }
    }
  }, [selectedElement, data])

  return (
    <button
      type='button'
      className='acms-admin-btn acms-admin-inline-block'
      onClick={onInsertHandler}
    >
      適応
    </button>
  )
}

export default Insert
