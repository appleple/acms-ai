import { ReactNode, useCallback, useMemo, useState } from 'react'
import type { PromptType, PromptResultType } from '../types/prompt-type'
import { defaultPrompt, PromptContext } from './prompt-context'

interface PromptContextProviderType {
  children: ReactNode,
  prompt?: PromptType
}

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
    setInsertSelector,
    setError
  }), [prompt, setIsPrompt, setStatus, setMode, setResults, addResult, setInsertSelector, setError])

  return <PromptContext.Provider value={value}>{children}</PromptContext.Provider>
}
