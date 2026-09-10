import { ChangeEvent, memo, useCallback, useMemo } from 'react'
import type { PromptResultType, PromptResponseType } from '../../../types/prompt-type'
import { useEntryContext } from '../../../stores/entry-context'
import styles from '../../../css/styles.module.css'
import { normalizePromptResponses } from '../../entry-ai/prompt-response'

interface Props {
  result: PromptResultType
}

const Result = memo(({ result: { id, data } }: Props) => {
  const { entryTag, addEntryTagData, setEntryTagData } = useEntryContext()
  const options = normalizePromptResponses(data)
  const selectedTags = useMemo(() => new Set(entryTag.data), [entryTag.data])

  const onCheckHandler = useCallback((e: ChangeEvent<HTMLInputElement>) => {
    if (e.target.checked) {
      addEntryTagData(e.target.value)
    } else {
      const newEntryTagList = entryTag.data.filter(tag => tag !== e.target.value)
      setEntryTagData(newEntryTagList)
    }
  }, [addEntryTagData, setEntryTagData, entryTag.data])

  return (
    <>
      {options.length > 0 && (
        <ul className={`${styles.entryAiResultList} ${styles.entryAiResultListInline}`}>
          {options.map((object: PromptResponseType) => {
              const checkboxId = `resultPromptCheckbox-${id}-${encodeURIComponent(object.content)}`
              return (
                <li key={object.content} className="acms-admin-form-checkbox">
                  <input
                    id={checkboxId}
                    type="checkbox"
                    value={object.content}
                    checked={selectedTags.has(object.content)}
                    onChange={onCheckHandler}
                    data-prompt-result='createTag'
                  />
                  <label htmlFor={checkboxId}>
                    <i className="acms-admin-ico-checkbox"></i>
                    {object.content}
                  </label>
                </li>
              )
            })}
        </ul>
      )}
    </>
  )
})

Result.displayName = 'Result'

export default Result
