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
  status: 'default' | 'loading' | 'result'
  results: PromptResultType[]
  insertSelector: string
  mode: string
}
