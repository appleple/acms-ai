import type { LiteEditorLike } from './features/chat/utils/lite-editor'

declare global {
  interface Window {
    ACMS: {
      Ready,
      Config: {
        root: string,
        bid?: string | number,
        // テキストユニットで「ソース系」として扱うタグの上書き（未指定なら内蔵の既定を使用）
        LiteEditorSourceModeTags?: RegExp,
        // ライトエディタを使わない管理画面には存在しない
        LiteEditorConf?: {
          btnOptions: {
            label: string,
            group: string,
            action: string,
            onClick: (editor: LiteEditorLike) => void
          }[]
        }
      }
      addListener: any
      plugins?: {
        register: (name: string, plugin: {
          version: string
          setup: (context: {
            ui: { fill: (slot: string, component: unknown) => void }
          }) => void
        }) => void
        get: (name: string) => unknown
        withFillProps: (component: unknown) => unknown
      }
    }
    csrfToken: string
  }
}

export {};
