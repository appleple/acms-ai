import { useCallback, useState } from 'react'
import { postRequest } from '../../../api/fetcher'
import { usePromptContext } from '../../../stores/use-prompt'
import { UnitJoin } from '../../../utils'
import type { PromptResultType } from '../../../types/prompt-type'

export function useCreateTitle(initialLabel = 'ユニットからタイトルを生成') {
  const [displayLabel, setDisplayLabel] = useState(initialLabel)
  const { prompt: { results: promptResults, status }, setStatus, addResult, putResult, setMode, setError } = usePromptContext()

  const postPrompt = useCallback(async () => {
    setMode('createTitle')
    setError(null)
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
      if (!result) {
        setError('AI からの応答取得に失敗しました。時間をおいて再試行してください。')
        setStatus('error')
        return null
      }
      if (result.errorCode && result.errorCode === 500) {
        setError(typeof result.message === 'string' && result.message ? result.message : 'タイトル生成に失敗しました。')
        setStatus('error')
        return null
      }
      return result
    } catch {
      setError('通信に失敗しました。時間をおいて再試行してください。')
      setStatus('error')
      return null
    }
  }, [setMode, setStatus, setError])

  const createTitle = useCallback(async () => {
    const result = await postPrompt()
    if (!result) {
      // エラーメッセージ・status は postPrompt 側で設定済み。
      return
    }
    if (!result[0].content) {
      setError('生成結果が空でした。もう一度お試しください。')
      setStatus('error')
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
  }, [postPrompt, promptResults, putResult, addResult, setStatus, setError])

  return { status, displayLabel, createTitle }
}
