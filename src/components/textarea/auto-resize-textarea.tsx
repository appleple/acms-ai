import React, { useState, useRef, useEffect, useCallback, forwardRef, useImperativeHandle, TextareaHTMLAttributes } from 'react';
import { cn } from '../../utils'
import styles from '../../css/styles.module.css'

export interface AutoResizeTextareaRef {
  focus: () => void
  blur: () => void
  getHeight: () => number
  recalculateHeight: () => void
  value: string
  setValue: (value: string) => void
}

interface AutoResizeTextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  value?: string
  onChange?: (e: React.ChangeEvent<HTMLTextAreaElement>) => void
  className?: string
  lineHeight?: number
  defaultRows?: number
  maxRows?: number
}

/**
 * 入力内容に応じて高さが自動調整されるテキストエリアコンポーネント
 *
 * @component
 * @example
 * ```tsx
 * import { useRef } from 'react';
 * import AutoResizeTextarea, { AutoResizeTextareaRef } from './auto-resize-textarea';
 *
 * function Example() {
 *   const textareaRef = useRef<AutoResizeTextareaRef>(null);
 *
 *   return (
 *     <AutoResizeTextarea
 *       ref={textareaRef}
 *       value="初期値"
 *       onChange={(e) => console.log(e.target.value)}
 *       lineHeight={24}    // 1行の高さ: 24px
 *       defaultRows={3}    // 初期行数: 3行
 *       maxRows={10}       // 最大行数: 10行
 *       placeholder="入力してください"
 *     />
 *   );
 * }
 * ```
 *
 * @param props - コンポーネントのプロパティ
 * @param {string} [props.value] - テキストエリアの値
 * @param {function} [props.onChange] - 値変更時のコールバック関数
 * @param {string} [props.className] - 追加のクラス名
 * @param {number} [props.lineHeight=24] - 1行の高さ（ピクセル単位）
 * @param {number} [props.defaultRows=1] - 初期表示時の行数
 * @param {number} [props.maxRows=6] - 最大表示行数
 *
 * @returns 自動リサイズ対応のテキストエリアコンポーネント
 */

const AutoResizeTextarea = forwardRef<AutoResizeTextareaRef | null, AutoResizeTextareaProps>((
  {
    value: externalValue,
    onChange: externalOnChange,
    className = "",
    lineHeight = 24, // ピクセル単位で指定
    defaultRows = 1,
    maxRows = 6,
    ...props
  },
  ref
) => {
  const [internalValue, setInternalValue] = useState('');
  const textareaRef = useRef<HTMLTextAreaElement>(null);

  const value = externalValue !== undefined ? externalValue : internalValue;

  useImperativeHandle(ref, () => ({
    focus: () => textareaRef.current?.focus(),
    blur: () => textareaRef.current?.blur(),
    getHeight: () => textareaRef.current?.offsetHeight || 0,
    recalculateHeight: calculateHeight,
    value: value,
    setValue: (newValue: string) => {
      if (externalValue === undefined) {
        setInternalValue(newValue);
      }
      if (textareaRef.current) {
        textareaRef.current.value = newValue;
      }
    }
  }));

  const calculateHeight = useCallback(() => {
    const textarea = textareaRef.current;
    if (!textarea) return;

    // 一時的に高さをautoにして実際のコンテンツの高さを取得
    textarea.style.height = 'auto';

    // scrollHeightを取得（実際のコンテンツの高さ）
    const scrollHeight = textarea.scrollHeight;

    const minHeight = defaultRows * lineHeight;
    const maxHeight = maxRows * lineHeight;

    // スクロールの高さを基に、制限内で新しい高さを設定
    const newHeight = Math.min(Math.max(scrollHeight, minHeight), maxHeight);
    textarea.style.height = `${newHeight}px`;
  }, [defaultRows, lineHeight, maxRows]);

  useEffect(() => {
    calculateHeight();
  }, [value, calculateHeight]);

  const handleChange = useCallback((e: React.ChangeEvent<HTMLTextAreaElement>) => {
    const newValue = e.target.value;
    if (externalValue === undefined) {
      setInternalValue(newValue);
    }
    if (externalOnChange) {
      externalOnChange(e);
    }
  }, [externalValue, externalOnChange]);

  return (
    <textarea
      ref={textareaRef}
      value={value}
      onChange={handleChange}
      className={cn(`${styles.AutoResizeTextarea}`, className)}
      style={{
        minHeight: `${defaultRows * lineHeight}px`,
        maxHeight: `${maxRows * lineHeight}px`,
        lineHeight: `${lineHeight}px`,
        ...props.style
      }}
      rows={defaultRows}
      {...props}
    />
  );
});
AutoResizeTextarea.displayName = 'AutoResizeTextarea';

export default AutoResizeTextarea;
