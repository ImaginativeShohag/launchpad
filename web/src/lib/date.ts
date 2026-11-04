const DAY = 86_400_000

/** Local YYYY-MM-DD for a date. */
export function toISODate(d: Date): string {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

/** The server's launch day is the UTC day. */
export function today(): string {
  return new Date().toISOString().slice(0, 10)
}

/** Calendar arithmetic in UTC, so DST never moves a date by a day. */
export function addDays(iso: string, n: number): string {
  const [y, m, d] = iso.split('-').map(Number)
  return new Date(Date.UTC(y, m - 1, d + n)).toISOString().slice(0, 10)
}

export function parseISODate(iso: string): Date {
  const [y, m, d] = iso.split('-').map(Number)
  return new Date(y, m - 1, d)
}

export function formatDay(iso: string, opts: Intl.DateTimeFormatOptions = { weekday: 'long', month: 'long', day: 'numeric' }): string {
  return parseISODate(iso).toLocaleDateString(undefined, opts)
}

export function relativeDay(iso: string): string {
  const diff = Math.round((parseISODate(iso).getTime() - parseISODate(today()).getTime()) / DAY)
  if (diff === 0) return 'Today'
  if (diff === 1) return 'Tomorrow'
  if (diff === -1) return 'Yesterday'
  return formatDay(iso, { weekday: 'short', month: 'short', day: 'numeric' })
}

export function timeAgo(isoDateTime: string): string {
  const s = Math.max(1, Math.round((Date.now() - new Date(isoDateTime).getTime()) / 1000))
  if (s < 60) return 'just now'
  const m = Math.round(s / 60)
  if (m < 60) return `${m}m ago`
  const h = Math.round(m / 60)
  if (h < 24) return `${h}h ago`
  const d = Math.round(h / 24)
  if (d < 30) return `${d}d ago`
  return new Date(isoDateTime).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}
