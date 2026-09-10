import { createContext, useContext } from 'react'
import type { EntryTagType } from '../types/entry-tag-type.d'

export const EntryContext = createContext<{
  entryTag: EntryTagType
  addEntryTagData: (tag: string) => void
  setEntryTagData: (tagList: string[]) => void
}>({
  entryTag: { data: [] },
  addEntryTagData: () => {},
  setEntryTagData: () => {}
})

export const useEntryContext = () => useContext(EntryContext)
