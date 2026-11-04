import type { RankPointDto, ReferrerSourceDto, SeriesPointDto } from '@/api/types'

const TEN_MIN = 600_000

/** Votes per slot, left to right. Slots in the future (the series is zero-filled to the end of the launch day) are dropped. */
export function votesPerSlot(points: SeriesPointDto[], now = Date.now()): number[] {
  return points.filter((p) => Date.parse(p.t) <= now).map((p) => p.votes)
}

/** One slot per 10-minute mark of the launch day up to `now`; a mark the API does not have (outside the top 500) is null. */
export function rankSlots(points: RankPointDto[], launchDate: string, now = Date.now()): (number | null)[] {
  const start = Date.parse(`${launchDate}T00:00:00Z`)
  if (Number.isNaN(start)) return []
  const last = Math.min(143, Math.max(0, Math.floor((now - start) / TEN_MIN)))
  const out: (number | null)[] = Array.from({ length: last + 1 }, () => null)
  for (const p of points) {
    const i = Math.floor((Date.parse(p.t) - start) / TEN_MIN)
    if (i >= 0 && i <= last) out[i] = p.rank
  }
  return out
}

const SOURCE_NAMES: Record<string, string> = {
  direct: 'Direct',
  launchpad: 'Launchpad',
  google: 'Google',
  bing: 'Bing',
  duckduckgo: 'DuckDuckGo',
  ecosia: 'Ecosia',
  yahoo: 'Yahoo',
  x: 'X / Twitter',
  facebook: 'Facebook',
  linkedin: 'LinkedIn',
  reddit: 'Reddit',
  hacker_news: 'Hacker News',
  youtube: 'YouTube',
  mastodon: 'Mastodon',
  bluesky: 'Bluesky',
  github: 'GitHub',
  other: 'Other',
}

/** A readable name for a referrer source key; an unknown key is a host and is shown as written (as text). */
export const sourceLabel = (r: Pick<ReferrerSourceDto, 'source'>) => SOURCE_NAMES[r.source] ?? r.source

const CATEGORY_COLORS: Record<string, string> = { direct: '#ff5112', search: '#0ea5e9', social: '#ec4899', internal: '#8b5cf6', other: '#9898a8' }
export const categoryColor = (category: string) => CATEGORY_COLORS[category] ?? CATEGORY_COLORS.other

export function formatBytes(n: number | null): string {
  if (n === null) return ''
  if (n < 1024) return `${n} B`
  if (n < 1024 * 1024) return `${Math.round(n / 1024)} KB`
  return `${(n / 1024 / 1024).toFixed(1)} MB`
}

/** YYYYMMDD of a UTC date, for the file name. */
export const compactDay = (d = new Date()) => d.toISOString().slice(0, 10).replace(/-/g, '')
