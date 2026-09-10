import { memo, useCallback } from 'react'
import { useCreateTitle } from '../hooks/use-create-title'

interface Props {
  label?: string
}

const Create = memo(({ label }: Props) => {
  const { status, displayLabel, createTitle } = useCreateTitle(label)

  const onClickHandler = useCallback((event: React.MouseEvent<HTMLButtonElement>) => {
    event.preventDefault()
    event.stopPropagation()
    if (status === 'loading') return
    createTitle()
  }, [createTitle, status])

  return (
    <button
      type="button"
      className='acms-admin-btn acms-admin-inline-block'
      onClick={onClickHandler}
      disabled={status === 'loading'}
    >
      {/* label 指定時は常にその文言を固定表示（生成後に文言を変えない） */}
      {label ?? displayLabel}
    </button>
  )
})

export default Create
