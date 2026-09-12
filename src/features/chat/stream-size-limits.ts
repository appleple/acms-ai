export interface StreamSizeLimits {
  responseBytes: number
  lineBytes: number
  generatedTextBytes: number
  events: number
}

export const DEFAULT_STREAM_SIZE_LIMITS: StreamSizeLimits = {
  responseBytes: 4 * 1024 * 1024,
  lineBytes: 1024 * 1024,
  generatedTextBytes: 2 * 1024 * 1024,
  events: 50_000,
}

export class StreamSizeError extends Error {
  constructor() {
    super('AI応答が許容サイズを超えました。')
    this.name = 'StreamSizeError'
  }
}
