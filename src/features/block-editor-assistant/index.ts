import { BlockEditorAssistant } from './component'

export function registerBlockEditorAssistant(): void {
  const plugins = window.ACMS?.plugins
  if (!plugins || plugins.get('acms-ai-block-editor-assistant')) return

  plugins.register('acms-ai-block-editor-assistant', {
    version: '1.0.0',
    setup: ({ ui }) => {
      ui.fill('block-editor.menus', plugins.withFillProps(BlockEditorAssistant))
    },
  })
}
