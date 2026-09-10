import { ChangeEvent, memo, useCallback, useState } from 'react'
import type { PromptResultType } from '../../../types/prompt-type'
import Insert from './insert'
import styles from '../../../css/styles.module.css'
import { normalizePromptResponses } from '../../entry-ai/prompt-response'

const Result = memo((props: PromptResultType) => {
  const { data } = props
  const [selectedValue, setSelectedValue] = useState('')
  const [isClosed, setIsClosed] = useState(false)
  const options = normalizePromptResponses(data)

  const handleRadioChange = useCallback((event: ChangeEvent<HTMLInputElement>) => {
    setSelectedValue(event.target.value)
  }, [])

  const handleInserted = useCallback(() => {
    setIsClosed(true)
  }, [])

  // 適用せずに候補を閉じる（キャンセル）。DOMを取り除き、結果行に余白を残さない。
  const handleCancel = useCallback(() => {
    setIsClosed(true)
  }, [])

  if (options.length === 0 || isClosed) return null

  return (
    <>
      <ul className={`${styles.entryAiResultList} ${styles.entryAiResultListStack}`}>
        {options.map((object) => {
          const radioId = `resultPromptRadio-${encodeURIComponent(object.content)}`
          return (
            <li
              className="acms-admin-form-radio"
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
      <div className={styles.entryAiApplyRow}>
        <Insert data={selectedValue} onInserted={handleInserted} />
        <button
          type="button"
          className={styles.entryAiCancelButton}
          onClick={handleCancel}
          aria-label="候補を閉じる"
          title="候補を閉じる"
        >
          ×
        </button>
      </div>
    </>
  )
})

Result.displayName = 'Result'

export default Result
