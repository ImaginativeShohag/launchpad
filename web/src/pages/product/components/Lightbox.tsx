import { AnimatePresence, motion, type PanInfo } from 'motion/react'
import { ChevronLeft, ChevronRight, X } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { useFocusTrap } from '@/lib/useFocusTrap'
import { createPortal } from 'react-dom'
import type { Screenshot } from '@/data/types'
import { cn } from '@/components/ui'

const slide = {
  enter: (dir: number) => ({ x: dir * 120, opacity: 0, scale: 0.94 }),
  center: { x: 0, opacity: 1, scale: 1 },
  exit: (dir: number) => ({ x: dir * -120, opacity: 0, scale: 0.94 }),
}

/** Full-screen screenshot viewer: arrows, keyboard (← → Esc), swipe, and thumbnails. */
export function Lightbox({ open, index, onIndex, onClose, shots, title }: { open: boolean; index: number; onIndex: (i: number) => void; onClose: () => void; shots: Screenshot[]; title: string }) {
  const [dir, setDir] = useState(1)
  const dialogRef = useRef<HTMLDivElement>(null)
  useFocusTrap(open, dialogRef)
  const n = shots.length

  const go = useCallback(
    (delta: number) => {
      if (n < 2) return
      setDir(delta)
      onIndex((index + delta + n) % n)
    },
    [index, n, onIndex],
  )

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
      if (e.key === 'ArrowRight') go(1)
      if (e.key === 'ArrowLeft') go(-1)
    }
    document.addEventListener('keydown', onKey)
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = ''
    }
  }, [open, go, onClose])

  const onDragEnd = (_: unknown, info: PanInfo) => {
    const swipe = info.offset.x + info.velocity.x * 0.2
    if (swipe < -80) go(1)
    else if (swipe > 80) go(-1)
    else if (Math.abs(info.offset.y) > 120) onClose()
  }

  return createPortal(
    <AnimatePresence>
      {open && (
        <motion.div
          ref={dialogRef}
          tabIndex={-1}
          role="dialog"
          aria-modal="true"
          aria-label={`${title} screenshots`}
          className="fixed inset-0 z-[70] flex flex-col bg-ink-950/92 backdrop-blur-xl"
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
        >
          <div className="flex items-center justify-between px-4 pt-[max(1rem,env(safe-area-inset-top))] text-white">
            <div className="min-w-0">
              <p className="truncate text-sm font-semibold">{title}</p>
              <p className="text-xs text-white/60 tabular-nums">
                {index + 1} / {n}
              </p>
            </div>
            <motion.button whileTap={{ scale: 0.9 }} whileHover={{ rotate: 90 }} onClick={onClose} aria-label="Close" className="cursor-pointer rounded-full bg-white/10 p-2.5 hover:bg-white/20">
              <X className="size-5" />
            </motion.button>
          </div>

          <div className="relative flex flex-1 items-center justify-center overflow-hidden px-4" onClick={onClose}>
            <AnimatePresence initial={false} custom={dir} mode="popLayout">
              <motion.div
                key={index}
                custom={dir}
                variants={slide}
                initial="enter"
                animate="center"
                exit="exit"
                transition={{ type: 'spring', stiffness: 320, damping: 32 }}
                drag
                dragSnapToOrigin
                dragElastic={0.6}
                onDragEnd={onDragEnd}
                onClick={(e) => e.stopPropagation()}
                className="w-full max-w-4xl cursor-grab touch-none active:cursor-grabbing"
              >
                <img src={shots[index]?.url} alt={shots[index]?.alt ?? ''} draggable={false} className="pointer-events-none mx-auto max-h-[70dvh] w-auto max-w-full rounded-2xl object-contain shadow-2xl ring-1 ring-white/10" />
              </motion.div>
            </AnimatePresence>

            {n > 1 && (
              <>
                <NavArrow side="left" onClick={() => go(-1)} />
                <NavArrow side="right" onClick={() => go(1)} />
              </>
            )}
          </div>

          <div className="flex justify-center gap-2 px-4 pt-3 pb-[max(1.25rem,env(safe-area-inset-bottom))]">
            {shots.map((shot, i) => (
              <button
                key={shot.id}
                onClick={() => {
                  setDir(i > index ? 1 : -1)
                  onIndex(i)
                }}
                aria-label={`Show screenshot ${i + 1}`}
                aria-current={i === index || undefined}
                className={cn('relative w-16 cursor-pointer overflow-hidden rounded-lg transition sm:w-20', i === index ? 'opacity-100' : 'opacity-40 hover:opacity-80')}
              >
                <img src={shot.url} alt="" aria-hidden className="aspect-[16/10] w-full rounded-lg object-cover" />
                {i === index && <motion.span layoutId="lightbox-thumb" className="absolute inset-0 rounded-lg ring-2 ring-white" />}
              </button>
            ))}
          </div>
        </motion.div>
      )}
    </AnimatePresence>,
    document.body,
  )
}

function NavArrow({ side, onClick }: { side: 'left' | 'right'; onClick: () => void }) {
  const Icon = side === 'left' ? ChevronLeft : ChevronRight
  return (
    <motion.button
      whileHover={{ scale: 1.08 }}
      whileTap={{ scale: 0.9 }}
      onClick={(e) => {
        e.stopPropagation()
        onClick()
      }}
      aria-label={side === 'left' ? 'Previous screenshot' : 'Next screenshot'}
      className={cn(
        'absolute top-1/2 hidden -translate-y-1/2 cursor-pointer rounded-full bg-white/10 p-3 text-white ring-1 ring-white/15 backdrop-blur hover:bg-white/20 sm:block',
        side === 'left' ? 'left-4' : 'right-4',
      )}
    >
      <Icon className="size-6" />
    </motion.button>
  )
}
