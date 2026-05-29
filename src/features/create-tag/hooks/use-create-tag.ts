import { useCallback, useState } from 'react'
import { postRequest } from '../../../api/fetcher'
import { usePromptContext } from '../../../stores/use-prompt'
import { UnitJoin } from '../../../utils'
import type { PromptResponseType } from '../../../types/prompt-type'

export function useCreateTag(addPrompt?: string, initialLabel = 'ユニットからタグを生成') {
  const [displayLabel, setDisplayLabel] = useState(initialLabel)
  const { prompt: { results: promptResults, status }, setStatus, addResult, setMode } = usePromptContext()

  const postPrompt = useCallback(async () => {
    setMode('createTag')
    setStatus('loading')
    const unitJoin = UnitJoin()

    const createTagResults = promptResults.filter((r: { byMode: string }) => r.byMode === 'createTag')
    const alreadyGeneratedTags = createTagResults.reduce<PromptResponseType[]>((acc, r) => {
      return [...acc, ...r.data]
    }, []).map((tag) => tag.content)

    const postData = {
      mode: 'createTag',
      article: unitJoin,
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
      if (!result) return null
      if (result.errorCode && result.errorCode === 500) return null
      return result
    } catch {
      setStatus('default')
      return null
    }
  }, [promptResults, addPrompt, setMode, setStatus])

  const createTag = useCallback(async () => {
    const result = await postPrompt()
    if (!result) {
      console.error('取得に失敗しました。')
      return
    }
    if (!result[0].content) {
      console.error('生成に失敗しました。')
      return
    }

    const createTagResults = promptResults.filter((r: { byMode: string }) => r.byMode === 'createTag')
    const length = createTagResults.length
    const mergeTags = createTagResults.reduce<PromptResponseType[]>((acc, r) => [...acc, ...r.data], [])
    const mergeTagContents = mergeTags.map((tag) => tag.content)
    const filterTags = (result as PromptResponseType[]).filter((obj) => !mergeTagContents.includes(obj.content))

    addResult({ id: length + 1, data: filterTags, resultType: 'checkbox', byMode: 'createTag' })
    setDisplayLabel('追加生成')
    setStatus('result')
  }, [postPrompt, promptResults, addResult, setStatus])

  return { status, displayLabel, createTag }
}
