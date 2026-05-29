import { useCallback, useState } from 'react'
import { postRequest } from '../../../api/fetcher'
import { usePromptContext } from '../../../stores/use-prompt'
import { UnitJoin } from '../../../utils'
import type { PromptResultType } from '../../../types/prompt-type'

export function useCreateTitle(initialLabel = 'ユニットからタイトルを生成') {
  const [displayLabel, setDisplayLabel] = useState(initialLabel)
  const { prompt: { results: promptResults, status }, setStatus, addResult, putResult, setMode } = usePromptContext()

  const postPrompt = useCallback(async () => {
    setMode('createTitle')
    setStatus('loading')
    const unitJoin = UnitJoin()

    const postData = {
      mode: 'createTitle',
      article: unitJoin
    }

    try {
      const result = await postRequest({
        url: window.ACMS.Config.root,
        data: postData,
        exec: 'ACMS_POST_AI_Title',
        formToken: window.csrfToken
      })
      if (!result) return null
      if (result.errorCode && result.errorCode === 500) return null
      return result
    } catch {
      setStatus('default')
      return null
    }
  }, [setMode, setStatus])

  const createTitle = useCallback(async () => {
    const result = await postPrompt()
    if (!result) {
      console.error('取得に失敗しました。')
      return
    }
    if (!result[0].content) {
      console.error('生成に失敗しました。')
      return
    }

    const createTitleResults = promptResults.filter((r: PromptResultType) => r.byMode === 'createTitle')
    if (createTitleResults.length) {
      putResult({
        id: createTitleResults[0].id,
        data: result,
        resultType: 'radio',
        byMode: 'createTitle'
      })
    } else {
      const newId = promptResults.length > 0
        ? promptResults.reduce((max, r) => Math.max(max, r.id), 0) + 1
        : 1
      addResult({
        id: newId,
        data: result,
        resultType: 'radio',
        byMode: 'createTitle'
      })
      setDisplayLabel('再生成')
    }
    setStatus('result')
  }, [postPrompt, promptResults, putResult, addResult, setStatus])

  return { status, displayLabel, createTitle }
}
