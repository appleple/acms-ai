import { describe, expect, it, vi } from 'vitest'
import { processSSEStream } from '../features/chat/hooks/use-chat'
import { StreamSizeError, type StreamSizeLimits } from '../features/chat/stream-size-limits'

const encoder = new TextEncoder()

function readerFromBytes(...chunks: Uint8Array[]): ReadableStreamDefaultReader<Uint8Array> {
  return new ReadableStream<Uint8Array>({
    start(controller) {
      chunks.forEach((chunk) => controller.enqueue(chunk))
      controller.close()
    },
  }).getReader()
}

function limits(overrides: Partial<StreamSizeLimits> = {}): StreamSizeLimits {
  return {
    responseBytes: 1024,
    lineBytes: 512,
    generatedTextBytes: 256,
    events: 10,
    ...overrides,
  }
}

describe('processSSEStream size limits', () => {
  it('UTF-8文字の途中でチャンクが分かれても日本語を破損しない', async () => {
    const bytes = encoder.encode('data: {"type":"delta","text":"あい"}\n\ndata: {"type":"completed"}\n\n')
    const split = bytes.indexOf(0xe3) + 2
    const onDelta = vi.fn()
    const onCompleted = vi.fn()

    await processSSEStream(
      readerFromBytes(bytes.slice(0, split), bytes.slice(split)),
      onDelta,
      onCompleted,
      vi.fn(),
      limits({ generatedTextBytes: 6 }),
    )

    expect(onDelta).toHaveBeenCalledWith('あい')
    expect(onCompleted).toHaveBeenCalledWith('あい', undefined)
  })

  it('改行のない単一行が上限を超えると拒否する', async () => {
    await expect(processSSEStream(
      readerFromBytes(encoder.encode('data: 123456')),
      vi.fn(),
      vi.fn(),
      vi.fn(),
      limits({ lineBytes: 10 }),
    )).rejects.toBeInstanceOf(StreamSizeError)
  })

  it('delta本文は日本語のバイト境界を超える前に拒否する', async () => {
    const stream = [
      'data: {"type":"delta","text":"あ"}\n\n',
      'data: {"type":"delta","text":"い"}\n\n',
      'data: {"type":"delta","text":"う"}\n\n',
    ].join('')

    await expect(processSSEStream(
      readerFromBytes(encoder.encode(stream)),
      vi.fn(),
      vi.fn(),
      vi.fn(),
      limits({ generatedTextBytes: 6 }),
    )).rejects.toBeInstanceOf(StreamSizeError)
  })

  it('受信全体とイベント数をそれぞれ上限で拒否する', async () => {
    await expect(processSSEStream(
      readerFromBytes(encoder.encode('123456')),
      vi.fn(),
      vi.fn(),
      vi.fn(),
      limits({ responseBytes: 5 }),
    )).rejects.toBeInstanceOf(StreamSizeError)

    const events = 'data: {"type":"completed"}\n\n'.repeat(3)
    await expect(processSSEStream(
      readerFromBytes(encoder.encode(events)),
      vi.fn(),
      vi.fn(),
      vi.fn(),
      limits({ events: 2 }),
    )).rejects.toBeInstanceOf(StreamSizeError)
  })
})
