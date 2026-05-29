import { useCallback, useEffect, useRef, useState } from 'react'

const DRAWER_MIN_WIDTH = 320
const DRAWER_MAX_WIDTH_RATIO = 0.6
const DRAG_THRESHOLD = 4
const KEYBOARD_RESIZE_STEP = 20

interface UseDrawerResizeOptions {
  isOpen: boolean
  storageKey?: string
}

export function useDrawerResize({ isOpen, storageKey = 'acms-ai-drawer-width' }: UseDrawerResizeOptions) {
  const drawerRef = useRef<HTMLDivElement>(null)
  const resizeStateRef = useRef<{ startX: number; containerRight: number; dragging: boolean } | null>(null)
  const [drawerWidth, setDrawerWidth] = useState<number | null>(() => {
    const saved = localStorage.getItem(storageKey)
    return saved ? Number(saved) : null
  })
  const [isResizing, setIsResizing] = useState(false)

  const handleResizeStart = useCallback((e: React.MouseEvent) => {
    e.preventDefault()
    const containerRight = drawerRef.current?.getBoundingClientRect().right ?? 0
    resizeStateRef.current = { startX: e.clientX, containerRight, dragging: false }
    document.body.style.cursor = 'ew-resize'
    document.body.style.userSelect = 'none'
  }, [])

  useEffect(() => {
    if (!isOpen) return

    const handleMouseMove = (e: MouseEvent) => {
      const state = resizeStateRef.current
      if (!state) return
      if (!state.dragging && Math.abs(e.clientX - state.startX) < DRAG_THRESHOLD) return
      if (!state.dragging) {
        state.dragging = true
        setIsResizing(true)
      }
      const adminMain = document.getElementById('acms-admin-main')
      const maxWidth = adminMain ? adminMain.clientWidth * DRAWER_MAX_WIDTH_RATIO : Infinity
      const newWidth = Math.min(Math.max(state.containerRight - e.clientX, DRAWER_MIN_WIDTH), maxWidth)
      setDrawerWidth(newWidth)
      localStorage.setItem(storageKey, String(newWidth))
    }

    const handleMouseUp = () => {
      const state = resizeStateRef.current
      if (!state) return
      resizeStateRef.current = null
      if (state.dragging) setIsResizing(false)
      document.body.style.cursor = ''
      document.body.style.userSelect = ''
    }

    document.addEventListener('mousemove', handleMouseMove)
    document.addEventListener('mouseup', handleMouseUp)

    return () => {
      document.removeEventListener('mousemove', handleMouseMove)
      document.removeEventListener('mouseup', handleMouseUp)
      document.body.style.cursor = ''
      document.body.style.userSelect = ''
    }
  }, [isOpen, storageKey])

  const handleResizeKeyDown = useCallback((e: React.KeyboardEvent) => {
    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return
    e.preventDefault()
    const adminMain = document.getElementById('acms-admin-main')
    const maxWidth = adminMain ? adminMain.clientWidth * DRAWER_MAX_WIDTH_RATIO : Infinity
    const currentWidth = drawerRef.current?.offsetWidth ?? DRAWER_MIN_WIDTH
    const delta = e.key === 'ArrowLeft' ? KEYBOARD_RESIZE_STEP : -KEYBOARD_RESIZE_STEP
    const newWidth = Math.min(Math.max(currentWidth + delta, DRAWER_MIN_WIDTH), maxWidth)
    setDrawerWidth(newWidth)
    localStorage.setItem(storageKey, String(newWidth))
  }, [storageKey])

  return { drawerRef, drawerWidth, isResizing, handleResizeStart, handleResizeKeyDown }
}
