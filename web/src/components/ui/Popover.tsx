import { AnimatePresence, motion } from 'motion/react'
import { useEffect, useRef, useState, type FocusEvent, type ReactNode } from 'react'
import { cn } from './cn'

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

/**
 * Click-to-open floating panel anchored under its trigger. It closes on Esc, on a press outside and when keyboard focus
 * moves out of it. With a `label` the panel is a named dialog: focus moves into it on open and goes back to the trigger on close.
 */
export function Popover({ trigger, children, align = 'right', className, label }: { trigger: (open: boolean) => ReactNode; children: (close: () => void) => ReactNode; align?: 'left' | 'right'; className?: string; label?: string }) {
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)
  const panel = useRef<HTMLDivElement>(null)
  const named = !!label

  useEffect(() => {
    if (!open) return
    const onDown = (e: MouseEvent) => !ref.current?.contains(e.target as Node) && setOpen(false)
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false)
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDown)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  useEffect(() => {
    if (!open || !named) return
    const el = panel.current
    const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null
    ;(el?.querySelector<HTMLElement>(FOCUSABLE) ?? el)?.focus()
    return () => {
      // Back to the trigger, unless focus already went somewhere else on purpose (Tab out, a click elsewhere).
      const active = document.activeElement
      if (opener && (!active || active === document.body || el?.contains(active))) opener.focus()
    }
  }, [open, named])

  const onBlur = (e: FocusEvent<HTMLDivElement>) => {
    const next = e.relatedTarget
    // A null target (a click on plain text, the window losing focus) is not a keyboard move out.
    if (next instanceof Node && !ref.current?.contains(next)) setOpen(false)
  }

  return (
    <div ref={ref} className="relative" onBlur={onBlur}>
      <div onClick={() => setOpen((o) => !o)}>{trigger(open)}</div>
      <AnimatePresence>
        {open && (
          <motion.div
            ref={panel}
            role={named ? 'dialog' : undefined}
            aria-label={label}
            tabIndex={named ? -1 : undefined}
            initial={{ opacity: 0, y: -6, scale: 0.97 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: -6, scale: 0.97 }}
            transition={{ type: 'spring', stiffness: 500, damping: 34 }}
            style={{ transformOrigin: align === 'right' ? 'top right' : 'top left' }}
            className={cn(
              'absolute top-full z-40 mt-2 rounded-2xl bg-white p-1.5 shadow-lift ring-1 ring-ink-200/70 dark:bg-ink-900 dark:ring-white/10',
              align === 'right' ? 'right-0' : 'left-0',
              className,
            )}
          >
            {children(() => setOpen(false))}
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  )
}

export function MenuItem({ icon, children, onClick, danger }: { icon?: ReactNode; children: ReactNode; onClick?: () => void; danger?: boolean }) {
  return (
    <button
      onClick={onClick}
      className={cn(
        'flex w-full cursor-pointer items-center gap-2.5 rounded-xl px-3 py-2 text-left text-sm font-medium transition-colors',
        danger ? 'text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10' : 'text-ink-700 hover:bg-ink-100 dark:text-ink-200 dark:hover:bg-white/5',
      )}
    >
      {icon && <span className="text-ink-400 [&>svg]:size-4">{icon}</span>}
      {children}
    </button>
  )
}
