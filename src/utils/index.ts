export const isScrollable = (el: Element) => el.scrollHeight > el.clientHeight || el.scrollWidth > el.clientWidth;

export const UnitJoin = () => {
  let text = '';

  // 旧ライトエディタ（テキストユニット）の本文。innerHTML を連結する。
  document.querySelectorAll('.entryFormLiteEditor').forEach((item) => {
    if (!item.closest('.entryFormColumnItem-hidden')) {
      text += `${item.innerHTML}\n`;
    }
  });

  // ブロックエディタの本文。各ユニットがシリアライズ済みHTMLを hidden
  // input[name^="block-editor_html_"] に保持しているため、その値（実HTML）を連結する。
  // ProseMirror の DOM を直接読むとツールバー等のノイズが混ざるため hidden を使う。
  document
    .querySelectorAll<HTMLInputElement>('input[name^="block-editor_html_"]')
    .forEach((input) => {
      if (!input.closest('.entryFormColumnItem-hidden') && input.value) {
        text += `${input.value}\n`;
      }
    });

  return text;
};

export const tagAdd = (tagString: string) => {
  const entryTag = document.getElementById('entry-tag')
  if(entryTag && entryTag.tagName.toLowerCase() === 'input') {
    const destinationElement = entryTag as HTMLInputElement
    destinationElement.value = `${destinationElement.value},${tagString}`
  }
};

export const getTagArray = (tagString: string): string[] => {
  return tagString.split(',');
}

export const getTagJoin = (tagList: string[]): string => {
  return tagList.join(',');
}

/**
 * クラス名を結合するユーティリティ関数
 * @param args - クラス名（文字列、オブジェクト、配列、undefined、null）
 * @returns 結合されたクラス名の文字列
 */
export function cn(...args: (string | Record<string, boolean> | (string | undefined | null)[] | undefined | null)[]): string {
  const classes: string[] = [];

  args.forEach(arg => {
    if (!arg) return;

    if (typeof arg === 'string') {
      classes.push(arg);
    } else if (Array.isArray(arg)) {
      classes.push(cn(...arg));
    } else if (typeof arg === 'object') {
      Object.entries(arg).forEach(([key, value]) => {
        if (value) {
          classes.push(key);
        }
      });
    }
  });

  return classes.join(' ').trim().replace(/\s+/g, ' ');
}
