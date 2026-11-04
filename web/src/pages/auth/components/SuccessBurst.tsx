import { motion } from 'motion/react'
import { Check } from 'lucide-react'

const colors = ['#ff5112', '#ec4899', '#8b5cf6', '#10b981', '#f59e0b', '#0ea5e9']

/** Animated check mark with a confetti ring. */
export function SuccessBurst() {
  const pieces = Array.from({ length: 14 }, (_, i) => {
    const angle = (i / 14) * Math.PI * 2
    const dist = 58 + (i % 3) * 12
    return { x: Math.cos(angle) * dist, y: Math.sin(angle) * dist, color: colors[i % colors.length], rotate: i * 47 }
  })
  return (
    <div className="relative mx-auto flex size-24 items-center justify-center" aria-hidden>
      {pieces.map((p, i) => (
        <motion.span
          key={i}
          className="absolute size-2 rounded-[2px]"
          style={{ backgroundColor: p.color }}
          initial={{ x: 0, y: 0, opacity: 0, scale: 0 }}
          animate={{ x: p.x, y: p.y, opacity: [0, 1, 1, 0], scale: [0, 1.2, 1, 0.6], rotate: p.rotate }}
          transition={{ duration: 1.1, delay: 0.25, ease: 'easeOut' }}
        />
      ))}
      <motion.span
        className="absolute inset-0 rounded-full bg-emerald-400/30"
        initial={{ scale: 0.6, opacity: 0 }}
        animate={{ scale: [0.6, 1.5], opacity: [0.8, 0] }}
        transition={{ duration: 0.9, delay: 0.15 }}
      />
      <motion.span
        className="relative flex size-20 items-center justify-center rounded-full bg-gradient-to-br from-emerald-400 to-teal-500 text-white shadow-[0_10px_30px_-8px_rgb(16_185_129/0.6)]"
        initial={{ scale: 0, rotate: -45 }}
        animate={{ scale: 1, rotate: 0 }}
        transition={{ type: 'spring', stiffness: 320, damping: 14 }}
      >
        <motion.span initial={{ scale: 0 }} animate={{ scale: 1 }} transition={{ type: 'spring', stiffness: 500, damping: 12, delay: 0.2 }}>
          <Check className="size-10" strokeWidth={3} />
        </motion.span>
      </motion.span>
    </div>
  )
}
