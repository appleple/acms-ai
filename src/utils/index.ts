export const isScrollable = (el: Element) => el.scrollHeight > el.clientHeight || el.scrollWidth > el.clientWidth;

const HIDDEN_UNIT_SELECTOR = '[data-unit-status="close"], .entryFormColumnItem-hidden';

export const collectEntryUnitHtml = () => {
  const fragments: string[] = [];

  // 旧ライトエディタとブロックエディタを一度に取得し、画面上のユニット順を維持する。
  // ブロックエディタは ProseMirror の表示 DOM ではなく、同期済みの hidden input を読む。
  document
    .querySelectorAll<HTMLElement>(
      '.entryFormLiteEditor, input[type="hidden"][name^="block-editor_html_"]'
    )
    .forEach((element) => {
      if (element.closest(HIDDEN_UNIT_SELECTOR)) {
        return;
      }

      const html = element instanceof HTMLInputElement ? element.value : element.innerHTML;
      if (html) {
        fragments.push(html);
      }
    });

  return fragments.length > 0 ? `${fragments.join('\n')}\n` : '';
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
