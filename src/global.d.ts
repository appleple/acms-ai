declare global {
  interface Window {
    ACMS: {
      Ready,
      Config: {
        root: string,
        bid?: string | number,
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
