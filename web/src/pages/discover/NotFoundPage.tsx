import { motion } from 'motion/react'
import { Home, Rocket, Search } from 'lucide-react'
import { useNavigate } from 'react-router'
import { Button } from '@/components/ui'

const stars = Array.from({ length: 18 }, (_, i) => ({
  left: `${(i * 37) % 100}%`,
  top: `${(i * 53) % 100}%`,
  size: 2 + (i % 3),
  delay: (i % 6) * 0.4,
}))

export default function NotFoundPage() {
  const navigate = useNavigate()
  return (
    <div className="surface relative mx-auto max-w-2xl overflow-hidden px-6 py-16 text-center sm:py-20">
      <div className="pointer-events-none absolute inset-0">
        {stars.map((s, i) => (
          <motion.span
            key={i}
            className="absolute rounded-full bg-brand-400/60 dark:bg-white/50"
            style={{ left: s.left, top: s.top, width: s.size, height: s.size }}
            animate={{ opacity: [0.2, 1, 0.2], scale: [1, 1.6, 1] }}
            transition={{ repeat: Infinity, duration: 2.4, delay: s.delay }}
          />
        ))}
        <div className="absolute -top-20 left-1/2 size-80 -translate-x-1/2 rounded-full bg-gradient-to-br from-brand-300/40 via-pink-300/30 to-violet-300/40 blur-3xl dark:from-brand-500/15 dark:to-violet-500/15" />
      </div>

      <div className="relative mx-auto flex h-40 w-64 items-center justify-center">
        <motion.span initial={{ scale: 0.6, opacity: 0 }} animate={{ scale: 1, opacity: 1 }} transition={{ type: 'spring', stiffness: 200, damping: 14 }} className="font-display text-8xl font-bold text-gradient sm:text-9xl">
          404
        </motion.span>
        <motion.div className="absolute inset-0" animate={{ rotate: 360 }} transition={{ repeat: Infinity, duration: 9, ease: 'linear' }}>
          <motion.span
            className="absolute -top-2 left-1/2 flex size-11 -translate-x-1/2 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-400 to-pink-500 text-white shadow-glow"
            animate={{ rotate: -360 }}
            transition={{ repeat: Infinity, duration: 9, ease: 'linear' }}
          >
            <Rocket className="size-5 rotate-45" />
          </motion.span>
        </motion.div>
      </div>

      <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.2 }} className="relative">
        <h1 className="mt-6 text-2xl font-bold sm:text-3xl">This page drifted out of orbit</h1>
        <p className="mx-auto mt-2 max-w-sm text-ink-500">The link might be broken, or this product hasn&apos;t launched yet. Nobody sees a launch before its day.</p>
        <div className="mt-8 flex flex-col justify-center gap-2 sm:flex-row">
          <Button icon={<Home className="size-4" />} onClick={() => navigate('/')}>
            Back to today
          </Button>
          <Button variant="outline" icon={<Search className="size-4" />} onClick={() => navigate('/search')}>
            Search products
          </Button>
        </div>
      </motion.div>
    </div>
  )
}
