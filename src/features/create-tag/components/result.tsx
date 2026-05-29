import { ChangeEvent, memo, useCallback } from 'react'
import type { PromptResultType, PromptResponseType } from '../../../types/prompt-type'
import { useEntryContext } from '../../../stores/use-entry'

interface Props {
  result: PromptResultType
}

const Result = memo(({ result: { id, data } }: Props) => {
  const { entryTag, addEntryTagData, setEntryTagData } = useEntryContext()

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
      {data && (
        <ul>
          {data
            .filter((object: PromptResponseType) => object.content.trim() !== '')
            .map((object: PromptResponseType) => {
              const checkboxId = `resultPromptCheckbox-${id}-${encodeURIComponent(object.content)}`
              return (
                <li key={object.content} className="acms-admin-form-checkbox">
                  <input
                    id={checkboxId}
                    type="checkbox"
                    value={object.content}
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
