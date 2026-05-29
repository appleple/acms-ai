import { ChangeEvent, memo, useCallback, useState } from 'react'
import type { PromptResultType, PromptResponseType } from '../../../types/prompt-type'
import Insert from './insert'

const Result = memo((props: PromptResultType) => {
  const { data } = props
  const [selectedValue, setSelectedValue] = useState('')

  const handleRadioChange = useCallback((event: ChangeEvent<HTMLInputElement>) => {
    setSelectedValue(event.target.value)
  }, [])

  if (!data) return null

  return (
    <>
      <ul>
        {data
          .filter((object: PromptResponseType) => object.content.trim() !== '')
          .map((object: PromptResponseType) => {
            const radioId = `resultPromptRadio-${encodeURIComponent(object.content)}`
            return (
              <li
                className="acms-admin-form-radio"
                style={{ listStyle: 'none', display: 'block' }}
                key={object.content}
              >
                <input
                  id={radioId}
                  name='promptRadio'
                  type="radio"
                  value={object.content}
                  checked={selectedValue === object.content}
                  onChange={handleRadioChange}
                  data-prompt-result='radio'
                />
                <label htmlFor={radioId}>
                  <i className="acms-admin-ico-radio"></i>
                  {object.content}
                </label>
              </li>
            )
          })}
      </ul>
      <Insert data={selectedValue} />
    </>
  )
})

Result.displayName = 'Result'

export default Result
