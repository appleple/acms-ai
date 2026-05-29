import { memo, useCallback } from 'react'
import { useCreateTag } from '../hooks/use-create-tag'

interface Props {
  addPrompt?: string
  label?: string
}

const Create = memo(({ addPrompt, label }: Props) => {
  const { status, displayLabel, createTag } = useCreateTag(addPrompt, label)

  const onClickHandler = useCallback((event: React.MouseEvent<HTMLButtonElement>) => {
    event.preventDefault()
    event.stopPropagation()
    if (status === 'loading') return
    createTag()
  }, [createTag, status])

  return (
    <button
      type="button"
      className='acms-admin-btn acms-admin-inline-block'
      onClick={onClickHandler}
      disabled={status === 'loading'}
    >
      {displayLabel}
    </button>
  )
})

export default Create
