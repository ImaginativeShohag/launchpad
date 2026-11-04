import { motion } from 'motion/react'
import { ExternalLink } from 'lucide-react'
import type { UserLink } from '@/data/types'
import { Avatar } from '@/components/ui'

interface Props {
  name: string
  handle: string
  headline: string
  hue: number
  avatarUrl?: string
  links: UserLink[]
}

/** Live preview of how the public profile header will look. */
export function ProfilePreview({ name, handle, headline, hue, avatarUrl, links }: Props) {
  const visibleLinks = links.filter((l) => l.label.trim() && l.url.trim())
  return (
    <div className="surface overflow-hidden">
      <motion.div
        className="h-20"
        animate={{ background: `linear-gradient(120deg, hsl(${hue} 85% 70%), hsl(${(hue + 60) % 360} 80% 60%))` }}
        transition={{ duration: 0.4 }}
      />
      <div className="-mt-10 px-5 pb-5">
        <motion.div key={hue} initial={{ scale: 0.85 }} animate={{ scale: 1 }} transition={{ type: 'spring', stiffness: 500, damping: 14 }} className="inline-block">
          <Avatar name={name || '?'} hue={hue} src={avatarUrl} size="lg" className="ring-4" />
        </motion.div>
        <p className="mt-2 truncate font-display text-lg font-bold">{name || 'Your name'}</p>
        <p className="truncate text-sm text-ink-500">@{handle || 'handle'}</p>
        <p className="mt-2 text-sm text-ink-700 dark:text-ink-200">{headline || <span className="text-ink-400 italic">Your one-liner goes here</span>}</p>
        {visibleLinks.length > 0 && (
          <div className="mt-3 flex flex-wrap gap-1.5">
            {visibleLinks.map((l, i) => (
              <motion.span
                key={`${l.label}-${i}`}
                initial={{ opacity: 0, scale: 0.8 }}
                animate={{ opacity: 1, scale: 1 }}
                className="inline-flex items-center gap-1 rounded-full bg-ink-100 px-2.5 py-1 text-xs font-semibold text-ink-700 dark:bg-white/8 dark:text-ink-200"
              >
                {l.label}
                <ExternalLink className="size-3" />
              </motion.span>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}
