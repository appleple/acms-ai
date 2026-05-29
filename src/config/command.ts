const isMac = typeof window !== 'undefined' ? navigator.platform.toUpperCase().indexOf('MAC') >= 0 : false;

export const commandList = [
  {
    id: 'Mod',
    command: isMac ? '⌘' : 'Ctrl',
    label: isMac ? 'Command' : 'Ctrl',
  },
  {
    id: 'Shift',
    command: '⇧',
    label: 'Shift',
  },
  {
    id: 'Alt',
    command: isMac ? '⌥' : 'Alt',
    label: isMac ? 'Option' : 'Alt',
  },
  {
    id: 'Backspace',
    command: '⌫',
    label: 'Backspace',
  },
  {
    id: 'Enter',
    command: '↵',
    label: 'Enter',
  },
  {
    id: 'Tab',
    command: '⇥',
    label: 'Tab',
  },
  {
    id: 'Space',
    command: '␣',
    label: 'Space',
  },
  {
    id: 'ArrowUp',
    command: '↑',
    label: 'Up',
  },
  {
    id: 'ArrowDown',
    command: '↓',
    label: 'Down',
  },
  {
    id: 'ArrowLeft',
    command: '←',
    label: 'Left',
  },
  {
    id: 'ArrowRight',
    command: '→',
    label: 'Right',
  },
];
