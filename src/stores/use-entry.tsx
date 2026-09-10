import { ReactNode, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { EntryTagType } from '../types/entry-tag-type.d'
import { getTagArray, getTagJoin } from '../utils'
import { EntryContext } from './entry-context'

interface EntryContextProviderType {
  children: ReactNode
  entryTag?: EntryTagType
}

interface AcmsTagSelectElement extends HTMLElement {
  value: string
}

const normalizeTags = (tags: string[]): string[] => {
  const normalized = new Set<string>()
  for (const tag of tags) {
    const value = tag.trim()
    if (value !== '') {
      normalized.add(value)
    }
  }
  return [...normalized]
}

const parseTags = (value: string): string[] => (
  value ? normalizeTags(getTagArray(value)) : []
)

const findTagSelect = (): AcmsTagSelectElement | null => (
  document.querySelector<AcmsTagSelectElement>('#entry-tag-display acms-tag-select')
)

const findTagInput = (): HTMLInputElement | null => (
  document.querySelector<HTMLInputElement>('#entry-tag-display #entry-tag-value')
)

const readInitialTags = (
  tagSelect: AcmsTagSelectElement | null,
  input: HTMLInputElement | null
): string[] => {
  const value = input?.value || tagSelect?.getAttribute('default-value') || ''
  return parseTags(value)
}

export function EntryContextProvider({
  children,
  entryTag: entryTagProp
}: EntryContextProviderType) {
  const initialTagSelect = findTagSelect()
  const initialInput = findTagInput()
  const tagSelectRef = useRef<AcmsTagSelectElement | null>(initialTagSelect)
  const entryTagInputRef = useRef<HTMLInputElement | null>(initialInput)
  const [entryTag, setEntryTag] = useState<EntryTagType>(() => (
    entryTagProp ?? { data: readInitialTags(initialTagSelect, initialInput) }
  ))

  const setEntryTagData = useCallback((tagList: string[]) => {
    const data = normalizeTags(tagList)
    setEntryTag((current) => (
      current.data.length === data.length && current.data.every((tag, index) => tag === data[index])
        ? current
        : { data }
    ))
  }, [])

  const addEntryTagData = useCallback((tag: string) => {
    setEntryTag((current) => {
      const data = normalizeTags([...current.data, tag])
      return data.length === current.data.length ? current : { data }
    })
  }, [])

  // a-blog cms のタグUIが変更された場合も Context へ反映する。
  useEffect(() => {
    const tagSelect = tagSelectRef.current
    const input = entryTagInputRef.current
    if (!tagSelect && !input) {
      return
    }

    const syncFromField = () => setEntryTagData(parseTags(tagSelect?.value ?? input?.value ?? ''))
    tagSelect?.addEventListener('change', syncFromField)
    input?.addEventListener('acmsAdminTagChange', syncFromField)
    input?.addEventListener('input', syncFromField)
    input?.addEventListener('change', syncFromField)

    return () => {
      tagSelect?.removeEventListener('change', syncFromField)
      input?.removeEventListener('acmsAdminTagChange', syncFromField)
      input?.removeEventListener('input', syncFromField)
      input?.removeEventListener('change', syncFromField)
    }
  }, [setEntryTagData])

  // AI候補の選択結果をタグUIと保存対象の hidden input へ反映する。
  useEffect(() => {
    const tagSelect = tagSelectRef.current
    const input = entryTagInputRef.current
    const entryTagListString = getTagJoin(entryTag.data)
    let cancelled = false
    if (!tagSelect && !input) {
      return
    }

    const syncToField = () => {
      if (cancelled) {
        return
      }

      const currentTags = parseTags(tagSelect?.value ?? input?.value ?? '')
      if (currentTags.length === entryTag.data.length && currentTags.every((tag, index) => tag === entryTag.data[index])) {
        return
      }

      // コアのカスタム要素登録より先にこのプラグインが初期化される場合にも、保存値を先に確定する。
      if (input) {
        input.value = entryTagListString
      }

      if (tagSelect) {
        // a-blog cms の公開 API を通すことで、React製タグUIと hidden input の両方を更新する。
        tagSelect.value = entryTagListString
        tagSelect.dispatchEvent(new Event('change', { bubbles: true }))
      }

      input?.dispatchEvent(new Event('input', { bubbles: true }))
      input?.dispatchEvent(new Event('change', { bubbles: true }))
    }

    syncToField()

    // a-blog cms 側のカスタム要素が後から定義される場合は、公開 API が使える時点でもう一度同期する。
    if (tagSelect && typeof customElements !== 'undefined') {
      void customElements.whenDefined('acms-tag-select').then(syncToField)
    }

    return () => {
      cancelled = true
    }
  }, [entryTag.data])

  const value = useMemo(() => ({
    entryTag,
    addEntryTagData,
    setEntryTagData
  }), [entryTag, addEntryTagData, setEntryTagData])

  return <EntryContext.Provider value={value}>{children}</EntryContext.Provider>
}
