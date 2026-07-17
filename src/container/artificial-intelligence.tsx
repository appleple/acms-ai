import { CreateTag, ResultTag, EntryTagInitializer } from '../features/create-tag'
import { CreateTitle, ResultTitle } from '../features/create-title'
import { usePromptContext } from '../stores/use-prompt'

// Context を直接購読するため memo による最適化効果がなく、不要なラップを避ける
const TitleResultRow = () => {
  const {
    prompt: { results, status, mode, error }
  } = usePromptContext()

  return (
    <>
      <CreateTitle />
      {mode === 'createTitle' && status === 'loading' && <p>生成中</p>}
      {mode === 'createTitle' && status === 'error' && error && (
        <p className="acms-admin-text-danger" role="alert">{error}</p>
      )}
      {results
        .filter((result) => result.byMode === 'createTitle')
        .map((result) => (
          <div key={result.id}>
            {result.resultType === 'radio' && <ResultTitle {...result} />}
          </div>
        ))}
    </>
  )
}

const TagResultRow = () => {
  const {
    prompt: { results, status, mode, error }
  } = usePromptContext()

  return (
    <>
      <CreateTag />
      <EntryTagInitializer />
      {mode === 'createTag' && status === 'loading' && <p>生成中</p>}
      {mode === 'createTag' && status === 'error' && error && (
        <p className="acms-admin-text-danger" role="alert">{error}</p>
      )}
      {results
        .filter((result) => result.byMode === 'createTag')
        .map((result) => (
          <div key={result.id}>
            {result.resultType === 'checkbox' && <ResultTag result={result} />}
          </div>
        ))}
    </>
  )
}

export const ArtificialIntelligence = () => (
  <table className="entryFormTable acms-admin-table-entry acms-admin-table">
    <tbody>
      <tr>
        <th>タイトル候補</th>
        <td>
          <TitleResultRow />
        </td>
      </tr>
      <tr>
        <th>タグ候補</th>
        <td>
          <TagResultRow />
        </td>
      </tr>
    </tbody>
  </table>
)
