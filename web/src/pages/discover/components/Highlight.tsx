import { Fragment } from 'react'

const escape = (s: string) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

export function tokenize(q: string): string[] {
  return q.trim().toLowerCase().split(/\s+/).filter(Boolean)
}

/** Wraps every occurrence of any token in a <mark>. */
export function Highlight({ text, tokens }: { text: string; tokens: string[] }) {
  if (!tokens.length) return <>{text}</>
  const re = new RegExp(`(${tokens.map(escape).join('|')})`, 'gi')
  const parts = text.split(re)
  return (
    <>
      {parts.map((part, i) =>
        i % 2 === 1 ? (
          <mark key={i} className="rounded bg-brand-100 px-0.5 text-brand-800 dark:bg-brand-500/25 dark:text-brand-200">
            {part}
          </mark>
        ) : (
          <Fragment key={i}>{part}</Fragment>
        ),
      )}
    </>
  )
}

/** A short excerpt around the first match. */
export function snippet(text: string, tokens: string[], radius = 50): string | null {
  const lower = text.toLowerCase()
  let at = -1
  for (const t of tokens) {
    const i = lower.indexOf(t)
    if (i >= 0 && (at < 0 || i < at)) at = i
  }
  if (at < 0) return null
  const start = Math.max(0, at - radius)
  const end = Math.min(text.length, at + radius * 2)
  return `${start > 0 ? '…' : ''}${text.slice(start, end).replace(/\s+/g, ' ')}${end < text.length ? '…' : ''}`
}
