import { ChangeEvent, memo, TransitionEvent, useCallback, useEffect, useState } from 'react'
import type { PromptResultType, PromptResponseType } from '../../../types/prompt-type'
import Insert from './insert'
import styles from '../../../css/styles.module.css'

const Result = memo((props: PromptResultType) => {
  const { data } = props
  const [selectedValue, setSelectedValue] = useState('')
  const [isCollapsing, setIsCollapsing] = useState(false)
  const [isHidden, setIsHidden] = useState(false)

  useEffect(() => {
    setSelectedValue('')
    setIsCollapsing(false)
    setIsHidden(false)
  }, [data])

  const handleRadioChange = useCallback((event: ChangeEvent<HTMLInputElement>) => {
    setSelectedValue(event.target.value)
  }, [])

  const handleInserted = useCallback(() => {
    setIsCollapsing(true)
  }, [])

  // 適用せずに候補を閉じる（キャンセル）。折りたたみアニメーション後に非表示にする。
  const handleCancel = useCallback(() => {
    setIsCollapsing(true)
  }, [])

  const handleTransitionEnd = useCallback((event: TransitionEvent<HTMLDivElement>) => {
    if (event.currentTarget !== event.target || !isCollapsing) return
    if (event.propertyName !== 'grid-template-rows') return
    setIsHidden(true)
  }, [isCollapsing])

  if (!data || isHidden) return null

  return (
    <div
      className={`${styles.entryAiTitleChoicePanel} ${
        isCollapsing ? styles.entryAiTitleChoicePanelCollapsed : ''
      }`}
      aria-hidden={isCollapsing}
      onTransitionEnd={handleTransitionEnd}
    >
      <div className={styles.entryAiTitleChoicePanelInner}>
        <ul className={`${styles.entryAiResultList} ${styles.entryAiResultListStack}`}>
          {data
            .filter((object: PromptResponseType) => object.content.trim() !== '')
            .map((object: PromptResponseType) => {
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
      </div>
    </div>
  )
})

Result.displayName = 'Result'

export default Result
