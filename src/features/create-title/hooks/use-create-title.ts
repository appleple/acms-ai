import { useCallback, useState } from 'react'
import { postRequest } from '../../../api/fetcher'
import { usePromptContext } from '../../../stores/prompt-context'
import { collectEntryUnitHtml } from '../../../utils'
import type { PromptResultType } from '../../../types/prompt-type'
import { normalizePromptResponses, promptErrorMessage } from '../../entry-ai/prompt-response'

export function useCreateTitle(initialLabel = 'ユニットからタイトルを生成') {
  const [displayLabel, setDisplayLabel] = useState(initialLabel)
  const { prompt: { results: promptResults, status }, setStatus, setResults, setMode, setError } = usePromptContext()

  const postPrompt = useCallback(async () => {
    setMode('createTitle')
    setError(null)
    setStatus('loading')
    const article = collectEntryUnitHtml()

    const postData = {
      mode: 'createTitle',
      article
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
      const responseError = promptErrorMessage(result, 'タイトル生成に失敗しました。')
      if (responseError !== null) {
        setError(responseError)
        setStatus('error')
        return null
      }
      const responses = normalizePromptResponses(result)
      if (responses.length === 0) {
        setError('生成結果が空でした。もう一度お試しください。')
        setStatus('error')
        return null
      }
      return responses
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
    const newId = promptResults.length > 0
      ? promptResults.reduce((max, promptResult) => Math.max(max, promptResult.id), 0) + 1
      : 1
    const nextResult: PromptResultType = {
      id: newId,
      data: result,
      resultType: 'radio',
      byMode: 'createTitle'
    }
    setResults([
      ...promptResults.filter((promptResult) => promptResult.byMode !== 'createTitle'),
      nextResult,
    ])
    setDisplayLabel('再生成')
    setStatus('result')
  }, [postPrompt, promptResults, setResults, setStatus])

  return { status, displayLabel, createTitle }
}
