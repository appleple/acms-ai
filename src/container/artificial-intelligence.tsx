import { createPortal } from 'react-dom'
import { CreateTag, ResultTag, EntryTagInitializer } from '../features/create-tag'
import { CreateTitle, ResultTitle } from '../features/create-title'
import { usePromptContext } from '../stores/use-prompt'
import styles from '../css/styles.module.css'

/**
 * エントリーフォームのタイトル/タグ各フィールドへ差し込む DOM スロット。
 * main.tsx が生成して渡す。null の場合はそのフィールドには何も描画しない。
 */
export interface EntryAiSlots {
  titleButton: HTMLElement | null
  titleResult: HTMLElement | null
  tagButton: HTMLElement | null
  tagResult: HTMLElement | null
}

// 生成中インジケータ。ボタン横ではなく結果表示エリアに出すことで、
// フィールド（入力欄）の幅が変化しないようにする。
const LoadingIndicator = () => (
  <span className={styles.entryAiLoading} aria-live="polite">
    <span className={styles.entryAiLoadingSpinner} aria-hidden="true" />
    <span>生成中</span>
  </span>
)

// Context を直接購読するため memo による最適化効果がなく、不要なラップを避ける
const TitlePortals = ({ slots }: { slots: EntryAiSlots }) => {
  const {
    prompt: { results, status, mode, error }
  } = usePromptContext()
  const loading = mode === 'createTitle' && status === 'loading'

  return (
    <>
      {slots.titleButton &&
        createPortal(
          <span className={styles.entryAiInlineAction}>
            {/* ラベルは常に「AI生成」固定（再生成へ変えない） */}
            <CreateTitle label="AI生成" />
          </span>,
          slots.titleButton
        )}
      {slots.titleResult &&
        createPortal(
          <>
            {loading && <LoadingIndicator />}
            {/* 生成失敗の理由（プロバイダが日本語化済み）を候補エリアに表示する */}
            {mode === 'createTitle' && status === 'error' && error && (
              <p className="acms-admin-text-danger" role="alert">{error}</p>
            )}
            {/* ラッパー div を挟むと、ResultTitle が閉じて null を返しても空 div が残り
                結果セルが :empty にならず行が潰れない。キーは ResultTitle に直接付ける。 */}
            {results
              .filter((result) => result.byMode === 'createTitle' && result.resultType === 'radio')
              .map((result) => (
                <ResultTitle key={result.id} {...result} />
              ))}
          </>,
          slots.titleResult
        )}
    </>
  )
}

const TagPortals = ({ slots }: { slots: EntryAiSlots }) => {
  const {
    prompt: { results, status, mode, error }
  } = usePromptContext()
  const loading = mode === 'createTag' && status === 'loading'

  return (
    <>
      {slots.tagButton &&
        createPortal(
          <span className={styles.entryAiInlineAction}>
            {/* ラベルは常に「AI生成」固定（追加生成へ変えない） */}
            <CreateTag label="AI生成" />
          </span>,
          slots.tagButton
        )}
      {slots.tagResult &&
        createPortal(
          <>
            <EntryTagInitializer />
            {loading && <LoadingIndicator />}
            {mode === 'createTag' && status === 'error' && error && (
              <p className="acms-admin-text-danger" role="alert">{error}</p>
            )}
            {results
              .filter((result) => result.byMode === 'createTag' && result.resultType === 'checkbox')
              .map((result) => (
                <ResultTag key={result.id} result={result} />
              ))}
          </>,
          slots.tagResult
        )}
    </>
  )
}

interface ArtificialIntelligenceProps {
  titleEnabled?: boolean
  tagEnabled?: boolean
  slots: EntryAiSlots
}

export const ArtificialIntelligence = ({
  titleEnabled = true,
  tagEnabled = true,
  slots,
}: ArtificialIntelligenceProps) => {
  // どちらも無効なら何も表示しない
  if (!titleEnabled && !tagEnabled) {
    return null
  }
  return (
    <>
      {titleEnabled && <TitlePortals slots={slots} />}
      {tagEnabled && <TagPortals slots={slots} />}
    </>
  )
}
