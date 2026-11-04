import { motion } from 'motion/react'
import { AtSign, Briefcase, CodeXml, Globe } from 'lucide-react'

export interface PillLink {
  label: string
  url: string
}

function linkIcon(l: PillLink) {
  const s = `${l.label} ${l.url}`.toLowerCase()
  if (s.includes('github')) return <CodeXml className="size-3.5" />
  if (s.includes('x.com') || s.includes('twitter') || l.label.toLowerCase() === 'x') return <AtSign className="size-3.5" />
  if (s.includes('linkedin')) return <Briefcase className="size-3.5" />
  return <Globe className="size-3.5" />
}

/** An outside link as a pill: only http(s) is linked, anything else is dropped. */
export function LinkPill({ link, index, rel = 'noopener noreferrer me' }: { link: PillLink; index: number; rel?: string }) {
  let host = link.url
  let safe = false
  try {
    const u = new URL(link.url)
    host = u.host.replace(/^www\./, '')
    safe = u.protocol === 'https:' || u.protocol === 'http:'
  } catch {
    /* not a URL: shown as text, never linked */
  }
  if (!safe) return null
  return (
    <motion.a
      href={link.url}
      target="_blank"
      rel={rel}
      initial={{ opacity: 0, scale: 0.8 }}
      animate={{ opacity: 1, scale: 1 }}
      transition={{ delay: 0.2 + index * 0.05, type: 'spring', stiffness: 400, damping: 22 }}
      whileHover={{ y: -2 }}
      whileTap={{ scale: 0.95 }}
      className="inline-flex items-center gap-1.5 rounded-full border border-ink-200 bg-white px-3 py-1.5 text-sm font-medium text-ink-700 shadow-soft transition-colors hover:border-brand-300 hover:text-brand-600 dark:border-white/10 dark:bg-ink-900 dark:text-ink-200"
    >
      {linkIcon(link)}
      {host}
    </motion.a>
  )
}
