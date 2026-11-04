import { AnimatePresence, motion } from 'motion/react'
import { Link2, Plus, Trash2 } from 'lucide-react'
import type { UserLink } from '@/data/types'
import { Button, cn } from '@/components/ui'

export const MAX_LINKS = 5
const URL_RE = /^https:\/\/[^\s.]+\.[^\s]+$/i

export type EditableLink = UserLink & { key: string }
export const toEditable = (l: UserLink): EditableLink => ({ ...l, key: Math.random().toString(36).slice(2) })

export function linkError(l: UserLink): string | undefined {
  if (!l.label.trim() && !l.url.trim()) return undefined
  if (!l.label.trim()) return 'Add a label'
  if (!URL_RE.test(l.url.trim())) return 'Use a full URL starting with https://'
}

const field =
  'h-10 w-full rounded-xl border border-ink-200 bg-white px-3 text-sm placeholder:text-ink-400 transition focus:border-brand-400 focus:outline-none focus:ring-4 focus:ring-brand-500/15 dark:border-white/10 dark:bg-ink-950'

/** Editable list of profile links (label + URL). */
/** `serverErrors` are the API's messages by row (index into `links`). */
export function LinksEditor({ links, onChange, showErrors, serverErrors }: { links: EditableLink[]; onChange: (links: EditableLink[]) => void; showErrors?: boolean; serverErrors?: Record<number, string> }) {
  const patch = (i: number, p: Partial<EditableLink>) => onChange(links.map((l, j) => (j === i ? { ...l, ...p } : l)))
  return (
    <div className="space-y-2">
      <AnimatePresence initial={false}>
        {links.map((l, i) => {
          const err = serverErrors?.[i] ?? (showErrors ? linkError(l) : undefined)
          return (
            <motion.div
              key={l.key}
              layout
              initial={{ opacity: 0, height: 0 }}
              animate={{ opacity: 1, height: 'auto' }}
              exit={{ opacity: 0, height: 0, transition: { duration: 0.18 } }}
              transition={{ type: 'spring', stiffness: 420, damping: 34 }}
              className="overflow-hidden"
            >
              <div className="flex items-start gap-2 pb-0.5">
                <span className="mt-2.5 hidden text-ink-400 sm:block">
                  <Link2 className="size-4" />
                </span>
                <div className="grid flex-1 gap-2 sm:grid-cols-[9rem_1fr]">
                  <input aria-label={`Link ${i + 1} label`} placeholder="Label" value={l.label} onChange={(e) => patch(i, { label: e.target.value })} className={field} maxLength={24} />
                  <input
                    aria-label={`Link ${i + 1} URL`}
                    aria-invalid={!!err}
                    placeholder="https://"
                    inputMode="url"
                    value={l.url}
                    onChange={(e) => patch(i, { url: e.target.value })}
                    className={cn(field, err && 'border-red-400')}
                  />
                </div>
                <motion.button
                  type="button"
                  whileHover={{ scale: 1.08 }}
                  whileTap={{ scale: 0.9 }}
                  aria-label={`Remove link ${i + 1}`}
                  onClick={() => onChange(links.filter((_, j) => j !== i))}
                  className="flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-xl text-ink-400 transition-colors hover:bg-red-50 hover:text-red-500 dark:hover:bg-red-500/10"
                >
                  <Trash2 className="size-4" />
                </motion.button>
              </div>
              {err && <p className="mt-1 text-xs font-medium text-red-500 sm:ml-6">{err}</p>}
            </motion.div>
          )
        })}
      </AnimatePresence>
      {links.length < MAX_LINKS ? (
        <Button type="button" variant="ghost" size="sm" icon={<Plus className="size-4" />} onClick={() => onChange([...links, toEditable({ label: '', url: 'https://' })])}>
          Add link
        </Button>
      ) : (
        <p className="text-xs text-ink-400">You can add up to {MAX_LINKS} links.</p>
      )}
    </div>
  )
}
