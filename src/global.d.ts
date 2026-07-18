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
            onClick:(editor: any) => void
          }[]
        }
      }
      addListener: any
    }
    csrfToken: string
  }
}

export {};
