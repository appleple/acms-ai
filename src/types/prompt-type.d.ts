export interface PromptResponseType {
  content: string
}

export interface PromptResultType {
  id: number
  data: PromptResponseType[]
  resultType: 'raw' | 'checkbox' | 'radio'
  byMode: string
}

export interface PromptType {
  isPrompt: boolean
  status: 'default' | 'loading' | 'result' | 'error'
  results: PromptResultType[]
  insertSelector: string
  mode: string
  /** 直近の生成が失敗したときの利用者向けメッセージ。成功・未実行時は null。 */
  error: string | null
}
