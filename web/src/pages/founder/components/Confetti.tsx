import { motion } from 'motion/react'
import { useMemo } from 'react'

const COLORS = ['#ff5112', '#ec4899', '#8b5cf6', '#f59e0b', '#10b981', '#0ea5e9']

/** Lightweight confetti burst made of motion spans. Mount it to fire once. */
export function Confetti({ count = 46 }: { count?: number }) {
  const pieces = useMemo(
    () =>
      Array.from({ length: count }, (_, i) => {
        const angle = (Math.random() - 0.5) * Math.PI * 1.1 - Math.PI / 2
        const dist = 140 + Math.random() * 220
        return {
          id: i,
          x: Math.cos(angle) * dist,
          y: Math.sin(angle) * dist,
          rotate: Math.random() * 720 - 360,
          color: COLORS[i % COLORS.length],
          w: 6 + Math.random() * 6,
          h: 8 + Math.random() * 10,
          round: Math.random() > 0.6,
          delay: Math.random() * 0.15,
        }
      }),
    [count],
  )
  return (
    <div aria-hidden className="pointer-events-none absolute inset-x-0 top-24 flex justify-center">
      {pieces.map((p) => (
        <motion.span
          key={p.id}
          className="absolute"
          style={{ width: p.w, height: p.round ? p.w : p.h, background: p.color, borderRadius: p.round ? 999 : 2 }}
          initial={{ x: 0, y: 0, opacity: 1, rotate: 0, scale: 0.4 }}
          animate={{ x: p.x, y: [0, p.y, p.y + 260], opacity: [1, 1, 0], rotate: p.rotate, scale: 1 }}
          transition={{ duration: 2.2, delay: p.delay, ease: [0.16, 0.84, 0.44, 1], times: [0, 0.45, 1] }}
        />
      ))}
    </div>
  )
}
