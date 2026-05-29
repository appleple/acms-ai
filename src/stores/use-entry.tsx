import { createContext, ReactNode, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react'
import { EntryTagType, EntryTagRefTypeRef } from '../types/entry-tag-type.d'
import { getTagJoin } from '../utils'

interface EntryContextProviderType {
  children: ReactNode,
  entryTag?: EntryTagType
}

const defaultEntryTag: EntryTagType = {
  ref: null,
  data: []
}

export const EntryContext = createContext<{
  entryTag: EntryTagType
  addEntryTagRef: (ref: EntryTagRefTypeRef) => void
  addEntryTagData: (tag: string) => void
  setEntryTagData: (tagList: string[]) => void
  deleteEntryTagData: (tag: string) => void
}>({
  entryTag: defaultEntryTag,
  addEntryTagRef: () => {},
  addEntryTagData: () => {},
  setEntryTagData: () => {},
  deleteEntryTagData: () => {}
})

export function EntryContextProvider({
  children,
  entryTag: entryTagProp = defaultEntryTag
}: EntryContextProviderType) {
  const entryTagRef = useRef<HTMLElement | null>(null);
  const [entryTag, setEntryTag] = useState<EntryTagType>(entryTagProp);

  const addEntryTagRef = useCallback((ref: EntryTagRefTypeRef) => setEntryTag((prevState) => ({ ...prevState, ref })), [])
  const addEntryTagData = useCallback((tag: string) => setEntryTag((prevState) => ({...prevState, data: [...prevState.data, tag]})), [])
  const setEntryTagData = useCallback((tagList: string[]) => setEntryTag((prevState) => ({...prevState, data: tagList})), [])
  const deleteEntryTagData = useCallback((tag: string) => setEntryTag((prevState) => ({
    ...prevState,
    data: prevState.data.filter(item => item !== tag)
  })), []);

  // tagの設定値を監視
  useEffect(() => {
    const initElement = () => {
      const entryTagValue = document.querySelector<HTMLElement>('#entry-tag-value');
      if (entryTagValue) {
        entryTagRef.current = entryTagValue;
        addEntryTagRef(entryTagRef);

        const observer = new MutationObserver((mutations) => {
          mutations.forEach((mutation) => {
            // 値の変更も監視
            if (
              mutation.type === 'attributes' ||
              mutation.type === 'characterData' ||
              (entryTagValue instanceof HTMLInputElement && mutation.target === entryTagValue)
            ) {
              addEntryTagRef(entryTagRef)
            }
          });
        });

        // value の変更も監視するように設定
        observer.observe(entryTagValue, {
          attributes: true,
          characterData: true,
          childList: true,
          subtree: true,
          attributeFilter: ['value']
        });

        // input イベントも監視
        entryTagValue.addEventListener('input', () => {
          addEntryTagRef(entryTagRef);
        });

        return observer;
      }
      return null;
    };

    // DOMContentLoaded に対応
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => {
        const observer = initElement();
        return () => observer?.disconnect();
      });
    } else {
      const observer = initElement();
      return () => observer?.disconnect();
    }

    return () => {
      entryTagRef.current = null;
    };
  }, []);

  const updateEntryTagValue = useCallback(() => {
    const entryTagListString = getTagJoin(entryTag.data);
    if (entryTag.ref?.current) {  // nullチェックを追加
      if (entryTag.ref.current instanceof HTMLInputElement) {
        // input要素の場合
        entryTag.ref.current.value = entryTagListString;
      }
    }
  }, [entryTag.data, entryTag.ref]);

  // useEffectで使用
useEffect(() => {
  updateEntryTagValue();
}, [updateEntryTagValue]);


  const value = useMemo(() => ({
    entryTag,
    addEntryTagRef,
    addEntryTagData,
    setEntryTagData,
    deleteEntryTagData
  }), [
    entryTag,
    addEntryTagRef,
    addEntryTagData,
    setEntryTagData,
    deleteEntryTagData
  ]);

  return <EntryContext.Provider value={value}>{children}</EntryContext.Provider>
}

export const useEntryContext = () => useContext(EntryContext);
