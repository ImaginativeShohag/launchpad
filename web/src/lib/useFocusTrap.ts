import { useEffect, type RefObject } from 'react'

const FOCUSABLE = 'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'

/**
 * While `open`: focus moves into the dialog, Tab and Shift+Tab wrap inside it, and closing returns focus to whatever had it
 * (the trigger). Closing on Esc stays with the dialog itself.
 */
export function useFocusTrap(open: boolean, ref: RefObject<HTMLElement | null>) {
  useEffect(() => {
    if (!open) return
    const el = ref.current
    if (!el) return
    const trigger = document.activeElement instanceof HTMLElement ? document.activeElement : null
    const items = () => Array.from(el.querySelectorAll<HTMLElement>(FOCUSABLE))
    ;(items()[0] ?? el).focus()
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== 'Tab') return
      const list = items()
      if (!list.length) {
        e.preventDefault()
        return
      }
      const active = document.activeElement
      const inside = !!active && el.contains(active)
      if (e.shiftKey && (!inside || active === list[0])) {
        e.preventDefault()
        list[list.length - 1].focus()
      } else if (!e.shiftKey && (!inside || active === list[list.length - 1])) {
        e.preventDefault()
        list[0].focus()
      }
    }
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('keydown', onKey)
      trigger?.focus()
    }
  }, [open, ref])
}
