import type { PromptResponseType } from '../../types/prompt-type'

export function promptErrorMessage(value: unknown, fallback: string): string | null {
  if (typeof value !== 'object' || value === null || !('errorCode' in value)) {
    return null
  }

  const message = 'message' in value ? value.message : null
  return typeof message === 'string' && message.trim() !== '' ? message : fallback
}

/**
 * AI 応答から、空文字と重複を除いた候補だけを取り出す。
 * 想定外の JSON が返っても UI 側で例外にしない。
 */
export function normalizePromptResponses(value: unknown): PromptResponseType[] {
  if (!Array.isArray(value)) {
    return []
  }

  const seen = new Set<string>()
  const responses: PromptResponseType[] = []

  for (const item of value) {
    if (typeof item !== 'object' || item === null || !('content' in item) || typeof item.content !== 'string') {
      continue
    }

    const content = item.content.trim()
    if (content === '' || seen.has(content)) {
      continue
    }

    seen.add(content)
    responses.push({ content })
  }

  return responses
}
