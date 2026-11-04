import { AnimatePresence, motion } from 'motion/react'
import { CheckCircle2, Info, TriangleAlert } from 'lucide-react'
import { createContext, use, useCallback, useState, type ReactNode } from 'react'

type Tone = 'success' | 'info' | 'error'
interface ToastItem {
  id: number
  tone: Tone
  title: string
  body?: string
}

const ToastContext = createContext<((t: Omit<ToastItem, 'id'>) => void) | null>(null)

const icons = {
  success: <CheckCircle2 className="size-5 text-emerald-500" />,
  info: <Info className="size-5 text-sky-500" />,
  error: <TriangleAlert className="size-5 text-red-500" />,
}

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([])
  const push = useCallback((t: Omit<ToastItem, 'id'>) => {
    const id = Date.now() + Math.random()
    setItems((s) => [...s, { ...t, id }])
    setTimeout(() => setItems((s) => s.filter((x) => x.id !== id)), 3800)
  }, [])

  return (
    <ToastContext value={push}>
      {children}
      <div className="pointer-events-none fixed inset-x-0 bottom-20 z-[60] flex flex-col items-center gap-2 px-4 md:bottom-6">
        <AnimatePresence>
          {items.map((t) => (
            <motion.div
              key={t.id}
              layout
              initial={{ opacity: 0, y: 24, scale: 0.9 }}
              animate={{ opacity: 1, y: 0, scale: 1 }}
              exit={{ opacity: 0, scale: 0.9, transition: { duration: 0.15 } }}
              transition={{ type: 'spring', stiffness: 500, damping: 32 }}
              className="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-2xl bg-white p-4 shadow-lift ring-1 ring-ink-200/70 dark:bg-ink-800 dark:ring-white/10"
            >
              {icons[t.tone]}
              <div className="min-w-0">
                <p className="text-sm font-semibold">{t.title}</p>
                {t.body && <p className="mt-0.5 text-sm text-ink-500 dark:text-ink-300">{t.body}</p>}
              </div>
            </motion.div>
          ))}
        </AnimatePresence>
      </div>
    </ToastContext>
  )
}

export function useToast() {
  const ctx = use(ToastContext)
  if (!ctx) throw new Error('useToast must be used inside ToastProvider')
  return ctx
}
