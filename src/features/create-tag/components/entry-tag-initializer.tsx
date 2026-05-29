import { useEffect, useRef } from 'react'
import { useEntryContext } from '../../../stores/use-entry'
import { getTagArray } from '../../../utils'

/**
 * エントリー編集画面のタグ入力欄の初期値を EntryContext に同期するコンポーネント。
 * UI を持たず、マウント時に一度だけ実行する。
 */
const EntryTagInitializer = () => {
  const {
    entryTag: { ref },
    setEntryTagData
  } = useEntryContext()
  const isInitialized = useRef(false)

  useEffect(() => {
    if (ref?.current && !isInitialized.current) {
      const tagRefString = ref.current.value
      if (tagRefString) {
        const tagRefArray = getTagArray(tagRefString)
        setEntryTagData(tagRefArray)
        isInitialized.current = true
      }
    }
  }, [ref, setEntryTagData])

  return null
}

export default EntryTagInitializer
