import { ReactNode, StrictMode } from 'react';
import { createRoot, type Container, type Root, type RootOptions } from 'react-dom/client';

export type ReactRootContainer = Container & {
  _reactRoot?: Root;
};

/**
 * 指定されたコンテナ要素に React コンポーネントツリーをレンダリングします。
 * もしコンテナに既存の React コンポーネントがマウントされている場合は、新しいコンポーネントをレンダリングする前に、既存のコンポーネントをアンマウントします。
 * また、自動的に StrictMode でラップされます。
 *
 * @param {ReactNode} children - コンテナ内にレンダリングする React 要素。
 * @param {ReactRootContainer} container - React コンポーネントツリーのコンテナとなる DOM 要素。
 * @param {RootOptions} [options] - ルートのオプション。
 * @returns {Root} - 作成された React ルートのインスタンス。
 */
export function render(children: ReactNode, container: ReactRootContainer, options?: RootOptions): Root {
  if (container._reactRoot) {
    // 既存の root を使い回してレンダリングする
    // unmount → createRoot するとfocus-trapなどのcleanupが完了する前に
    // 新しいコンポーネントがマウントされ、null要素へのaddEventListenerエラーが発生する
    container._reactRoot.render(<StrictMode>{children}</StrictMode>);
    return container._reactRoot;
  }

  const root = createRoot(container, options);
  container._reactRoot = root;
  root.render(<StrictMode>{children}</StrictMode>);

  return root;
}
