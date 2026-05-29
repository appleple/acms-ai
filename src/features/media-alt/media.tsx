/**
 * メディアユニットの alt テキスト自動生成を行うロジックのマウントポイント。
 * UI を持たず、acmsAdminMediaUnitChange イベント経由で mediaId・unitId を受け取り、
 * POST で alt 生成 API を呼び出す。
 * リクエスト中は alt テキスト挿入先にローディングインジケータを表示する。
 */

/**
 * Todo: CMSv3.3で対応予定のため、一旦コメントアウト
 */

// import { useEffect, useState } from 'react'
// import ReactDOM from 'react-dom'
// import { postRequest } from '../../api/fetcher'
// import styles from '../../css/styles.module.css'

// interface Props {
//   mediaId: number
//   unitId: string
// }

// const Media = ({ mediaId, unitId }: Props) => {
//   const [isLoading, setIsLoading] = useState(true)
//   const [containerEl, setContainerEl] = useState<HTMLElement | null>(null)

//   useEffect(() => {
//     const controller = new AbortController()

//     setIsLoading(true)

//     const altInput = document.getElementById(`unit-media-alt-text-${unitId}`) as HTMLTextAreaElement | null
//     const previousAltText = altInput?.value ?? ''
//     if (altInput) {
//       altInput.value = ''
//     }
//     if (altInput?.parentElement) {
//       altInput.parentElement.style.position = 'relative'
//       setContainerEl(altInput.parentElement)
//     }

//     const sendPostRequest = async () => {
//       try {
//         const response = await postRequest({
//           url: window.ACMS.Config.root,
//           data: {
//             mode: 'createUnitMedia',
//             prompt: [{ role: 'user', content: '' }],
//             mid: mediaId.toString()
//           },
//           exec: 'ACMS_POST_AI_Media_Alt',
//           formToken: window.csrfToken,
//           signal: controller.signal,
//         })

//         const altInputEl = document.getElementById(`unit-media-alt-text-${unitId}`) as HTMLTextAreaElement | null
//         if (altInputEl) {
//           if (response === null || !response.altText) {
//             console.error('ALTテキストの取得に失敗しました')
//             altInputEl.value = previousAltText
//             return
//           }
//           altInputEl.value = response.altText
//         }
//       } catch (error) {
//         if (error instanceof Error && error.name === 'AbortError') return
//         console.error('POST送信中にエラーが発生しました:', error)
//         const altInputEl = document.getElementById(`unit-media-alt-text-${unitId}`) as HTMLTextAreaElement | null
//         if (altInputEl) altInputEl.value = previousAltText
//       } finally {
//         if (!controller.signal.aborted) setIsLoading(false)
//       }
//     }

//     sendPostRequest()

//     return () => controller.abort()
//   }, [mediaId, unitId])

//   if (!isLoading || !containerEl) {
//     return <></>
//   }

//   return ReactDOM.createPortal(
//     <span style={{ position: 'absolute', top: '10px', left: '10px' }} className={styles.proofreadingResultModalLoading} />,
//     containerEl
//   )
// }

// export default Media
