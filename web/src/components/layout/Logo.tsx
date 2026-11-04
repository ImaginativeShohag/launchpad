import { motion } from 'motion/react'
import { Rocket } from 'lucide-react'
import { Link } from 'react-router'

export function Logo() {
  return (
    <Link to="/" className="group flex items-center gap-2" aria-label="Launchpad home">
      <motion.span
        whileHover={{ rotate: -12, y: -2 }}
        transition={{ type: 'spring', stiffness: 400, damping: 12 }}
        className="flex size-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-400 via-brand-500 to-pink-500 text-white shadow-glow"
      >
        <Rocket className="size-4.5" strokeWidth={2.5} />
      </motion.span>
      <span className="font-display text-xl font-extrabold tracking-tight">launchpad</span>
    </Link>
  )
}
