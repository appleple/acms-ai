import { createContext, ReactNode, useCallback, useContext, useMemo, useState } from 'react'
import type { PromptType, PromptResultType } from '../types/prompt-type'

interface PromptContextProviderType {
  children: ReactNode,
  prompt?: PromptType
}

const defaultPrompt: PromptType = {
  isPrompt: true,
  status: 'default',
  results: [],
  insertSelector: '',
  mode: '',
  error: null
}

export const PromptContext = createContext<{
  prompt: PromptType,
  setIsPrompt: (isPrompt: boolean) => void
  setStatus: (status: PromptType["status"]) => void
  setResults: (results: PromptResultType[]) => void
  addResult: (result: PromptResultType) => void
  putResult: (result: PromptResultType) => PromptResultType | null;
  setMode: (mode: string) => void
  setInsertSelector: (insertId: string) => void
  setError: (error: string | null) => void
}>({
  prompt: defaultPrompt,
  setIsPrompt: () => {},
  setStatus: () => {},
  setResults: () => {},
  addResult: () => {},
  putResult: () => null,
  setMode: () => {},
  setInsertSelector: () => {},
  setError: () => {}
});

export function PromptContextProvider({
  children,
  prompt: promptProp = defaultPrompt
}: PromptContextProviderType) {
  const [prompt, setPrompt] = useState(promptProp)

  const setIsPrompt = useCallback(
    (isPrompt: boolean) => setPrompt((prev) => ({ ...prev, isPrompt })),
    []
  )
  const setStatus = useCallback(
    (status: PromptType["status"]) => setPrompt((prev) => ({ ...prev, status })),
    []
  )
  const setMode = useCallback(
    (mode: string) => setPrompt((prev) => ({ ...prev, mode })),
    []
  )
  const setResults = useCallback(
    (results: PromptResultType[]) => setPrompt((prev) => ({ ...prev, results })),
    []
  )
  const addResult = useCallback(
    (result: PromptResultType) => setPrompt((prev) => ({ ...prev, results: [...prev.results, result] })),
    []
  )
  const putResult = useCallback((result: PromptResultType): PromptResultType | null => {
    let found = false
    setPrompt((prev) => {
      const index = prev.results.findIndex((r) => r.id === result.id)
      if (index < 0) return prev
      found = true
      const newResults = [...prev.results]
      newResults[index] = { ...newResults[index], ...result }
      return { ...prev, results: newResults }
    })
    return found ? result : null
  }, [])
  const setInsertSelector = useCallback(
    (insertSelector: string) => setPrompt((prev) => ({ ...prev, insertSelector })),
    []
  )
  const setError = useCallback(
    (error: string | null) => setPrompt((prev) => ({ ...prev, error })),
    []
  )


  const value = useMemo(() => ({
    prompt,
    setIsPrompt,
    setStatus,
    setMode,
    setResults,
    addResult,
    putResult,
    setInsertSelector,
    setError
  }), [prompt, setIsPrompt, setStatus, setMode, setResults, addResult, putResult, setInsertSelector, setError])

  return <PromptContext.Provider value={value}>{children}</PromptContext.Provider>
}

export const usePromptContext = () => useContext(PromptContext);
