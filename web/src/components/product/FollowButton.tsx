import { AnimatePresence, motion } from 'motion/react'
import { Check, UserMinus, UserPlus } from 'lucide-react'
import { useState } from 'react'
import { cn } from '@/components/ui'

/** Follow toggle that morphs width/colour between states; hovering "Following" previews "Unfollow". */
export function FollowButton({ following, onToggle, size = 'md', className, disabled, label }: { following: boolean; onToggle: () => void; size?: 'sm' | 'md'; className?: string; disabled?: boolean; label?: string }) {
  const [hover, setHover] = useState(false)
  const state = following ? (hover ? 'unfollow' : 'following') : 'follow'
  const content = {
    follow: { icon: <UserPlus className="size-4" />, label: 'Follow' },
    following: { icon: <Check className="size-4" strokeWidth={3} />, label: 'Following' },
    unfollow: { icon: <UserMinus className="size-4" />, label: 'Unfollow' },
  }[state]

  return (
    <motion.button
      layout
      type="button"
      onClick={(e) => {
        e.preventDefault()
        e.stopPropagation()
        setHover(false)
        if (!disabled) onToggle()
      }}
      onHoverStart={() => setHover(true)}
      onHoverEnd={() => setHover(false)}
      whileTap={{ scale: 0.94 }}
      aria-pressed={following}
      aria-label={label ? `${following ? 'Unfollow' : 'Follow'} ${label}` : undefined}
      aria-disabled={disabled || undefined}
      transition={{ type: 'spring', stiffness: 500, damping: 32 }}
      className={cn(
        'relative inline-flex shrink-0 cursor-pointer items-center justify-center overflow-hidden rounded-full font-semibold transition-colors',
        size === 'sm' ? 'h-8 px-3 text-xs' : 'h-10 px-4 text-sm',
        state === 'follow' && 'bg-ink-900 text-white hover:bg-ink-800 dark:bg-white dark:text-ink-900 dark:hover:bg-ink-100',
        state === 'following' && 'border border-ink-200 bg-white text-ink-800 dark:border-white/10 dark:bg-ink-900 dark:text-ink-100',
        state === 'unfollow' && 'border border-red-200 bg-red-50 text-red-600 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-400',
        disabled && 'opacity-60',
        className,
      )}
    >
      <AnimatePresence mode="popLayout" initial={false}>
        <motion.span
          key={state}
          initial={{ y: 14, opacity: 0, filter: 'blur(4px)' }}
          animate={{ y: 0, opacity: 1, filter: 'blur(0px)' }}
          exit={{ y: -14, opacity: 0, filter: 'blur(4px)' }}
          transition={{ type: 'spring', stiffness: 600, damping: 34 }}
          className="inline-flex items-center gap-1.5 whitespace-nowrap"
        >
          {content.icon}
          {content.label}
        </motion.span>
      </AnimatePresence>
    </motion.button>
  )
}
