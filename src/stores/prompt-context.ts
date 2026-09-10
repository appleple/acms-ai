import { createContext, useContext } from 'react'
import type { PromptType, PromptResultType } from '../types/prompt-type'

export const defaultPrompt: PromptType = {
  isPrompt: true,
  status: 'default',
  results: [],
  insertSelector: '',
  mode: '',
  error: null
}

export const PromptContext = createContext<{
  prompt: PromptType
  setIsPrompt: (isPrompt: boolean) => void
  setStatus: (status: PromptType['status']) => void
  setResults: (results: PromptResultType[]) => void
  addResult: (result: PromptResultType) => void
  setMode: (mode: string) => void
  setInsertSelector: (insertId: string) => void
  setError: (error: string | null) => void
}>({
  prompt: defaultPrompt,
  setIsPrompt: () => {},
  setStatus: () => {},
  setResults: () => {},
  addResult: () => {},
  setMode: () => {},
  setInsertSelector: () => {},
  setError: () => {}
})

export const usePromptContext = () => useContext(PromptContext)
