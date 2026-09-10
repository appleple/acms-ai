import { useCallback, useState } from 'react'
import { postRequest } from '../../../api/fetcher'
import { usePromptContext } from '../../../stores/prompt-context'
import { collectEntryUnitHtml } from '../../../utils'
import type { PromptResponseType } from '../../../types/prompt-type'
import { normalizePromptResponses, promptErrorMessage } from '../../entry-ai/prompt-response'

export function useCreateTag(addPrompt?: string, initialLabel = 'ユニットからタグを生成') {
  const [displayLabel, setDisplayLabel] = useState(initialLabel)
  const { prompt: { results: promptResults, status }, setStatus, addResult, setMode, setError } = usePromptContext()

  const postPrompt = useCallback(async () => {
    setMode('createTag')
    setError(null)
    setStatus('loading')
    const article = collectEntryUnitHtml()

    const createTagResults = promptResults.filter((r: { byMode: string }) => r.byMode === 'createTag')
    const alreadyGeneratedTags = createTagResults.flatMap((result) => (
      result.data.map((tag: PromptResponseType) => tag.content)
    ))

    const postData = {
      mode: 'createTag',
      article,
      addPrompt: addPrompt ?? '',
      alreadyGeneratedTags: JSON.stringify(alreadyGeneratedTags)
    }

    try {
      const result = await postRequest({
        url: window.ACMS.Config.root,
        data: postData,
        exec: 'ACMS_POST_AI_Tag',
        formToken: window.csrfToken
      })
      if (!result) {
        setError('AI からの応答取得に失敗しました。時間をおいて再試行してください。')
        setStatus('error')
        return null
      }
      const responseError = promptErrorMessage(result, 'タグ生成に失敗しました。')
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
  }, [promptResults, addPrompt, setMode, setStatus, setError])

  const createTag = useCallback(async () => {
    const result = await postPrompt()
    if (!result) {
      // エラーメッセージ・status は postPrompt 側で設定済み。
      return
    }
    const createTagResults = promptResults.filter((r: { byMode: string }) => r.byMode === 'createTag')
    const excludedTagContents = new Set(
      createTagResults.flatMap((promptResult) => promptResult.data.map((tag) => tag.content))
    )
    for (const tag of (addPrompt ?? '').split(',')) {
      const normalizedTag = tag.trim()
      if (normalizedTag !== '') {
        excludedTagContents.add(normalizedTag)
      }
    }
    const filterTags = result.filter((obj) => !excludedTagContents.has(obj.content))
    if (filterTags.length === 0) {
      setError('新しいタグ候補がありませんでした。もう一度お試しください。')
      setStatus('error')
      return
    }
    const newId = promptResults.length > 0
      ? promptResults.reduce((max, promptResult) => Math.max(max, promptResult.id), 0) + 1
      : 1

    addResult({ id: newId, data: filterTags, resultType: 'checkbox', byMode: 'createTag' })
    setDisplayLabel('追加生成')
    setStatus('result')
  }, [postPrompt, promptResults, addPrompt, addResult, setStatus, setError])

  return { status, displayLabel, createTag }
}
