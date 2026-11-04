import { motion, type Variants } from 'motion/react'
import type { ReactNode } from 'react'
import { cn } from '@/components/ui'

const container: Variants = {
  hidden: {},
  show: { transition: { staggerChildren: 0.06, delayChildren: 0.05 } },
}

export const fadeUp: Variants = {
  hidden: { opacity: 0, y: 12 },
  show: { opacity: 1, y: 0, transition: { type: 'spring', stiffness: 380, damping: 30 } },
}

/** Staggers its direct `<Reveal>` children in. */
export function Stagger({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <motion.div variants={container} initial="hidden" animate="show" className={className}>
      {children}
    </motion.div>
  )
}

export function Reveal({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <motion.div variants={fadeUp} className={className}>
      {children}
    </motion.div>
  )
}

interface AuthCardProps {
  icon?: ReactNode
  title: ReactNode
  subtitle?: ReactNode
  children: ReactNode
  footer?: ReactNode
  className?: string
}

/** Card frame shared by every auth screen. */
export function AuthCard({ icon, title, subtitle, children, footer, className }: AuthCardProps) {
  return (
    <Stagger className="w-full">
      <Reveal className={cn('surface p-6 sm:p-8', className)}>
        <Stagger>
          {icon && <Reveal className="mb-5">{icon}</Reveal>}
          <Reveal>
            <h1 className="text-[1.75rem] leading-tight font-extrabold">{title}</h1>
            {subtitle && <p className="mt-1.5 text-sm text-ink-500 dark:text-ink-400">{subtitle}</p>}
          </Reveal>
          <div className="mt-6">{children}</div>
        </Stagger>
      </Reveal>
      {footer && (
        <Reveal className="mt-5 text-center text-sm text-ink-500 dark:text-ink-400">{footer}</Reveal>
      )}
    </Stagger>
  )
}

/** Rounded gradient badge used as an auth-card hero icon. */
export function AuthIcon({ children, tone = 'brand' }: { children: ReactNode; tone?: 'brand' | 'green' | 'violet' }) {
  const tones = {
    brand: 'from-brand-400 to-pink-500 shadow-glow',
    green: 'from-emerald-400 to-teal-500 shadow-[0_8px_30px_-6px_rgb(16_185_129/0.45)]',
    violet: 'from-violet-400 to-fuchsia-500 shadow-[0_8px_30px_-6px_rgb(139_92_246/0.45)]',
  }
  return (
    <motion.span
      initial={{ scale: 0.6, rotate: -12 }}
      animate={{ scale: 1, rotate: 0 }}
      whileHover={{ rotate: -8, scale: 1.05 }}
      transition={{ type: 'spring', stiffness: 400, damping: 14 }}
      className={cn('inline-flex size-12 items-center justify-center rounded-2xl bg-gradient-to-br text-white [&>svg]:size-6', tones[tone])}
    >
      {children}
    </motion.span>
  )
}

export function OrDivider({ label = 'or' }: { label?: string }) {
  return (
    <div className="my-5 flex items-center gap-3 text-xs font-semibold tracking-wider text-ink-400 uppercase">
      <span className="h-px flex-1 bg-ink-200 dark:bg-white/10" />
      {label}
      <span className="h-px flex-1 bg-ink-200 dark:bg-white/10" />
    </div>
  )
}

/** Shake wrapper: bump `trigger` to replay the shake (e.g. on a failed submit). */
export function Shake({ trigger, children }: { trigger: number; children: ReactNode }) {
  return (
    <motion.div key={trigger} animate={trigger ? { x: [0, -8, 7, -5, 3, 0] } : undefined} transition={{ duration: 0.4 }}>
      {children}
    </motion.div>
  )
}
