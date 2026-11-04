const KEY = 'launchpad-recent-searches'
const MAX = 6

/** Recent searches live in localStorage. Storage can be blocked, full or hold junk: every access is guarded. */
export function loadRecent(): string[] {
  try {
    const parsed: unknown = JSON.parse(localStorage.getItem(KEY) ?? '[]')
    return Array.isArray(parsed) ? parsed.filter((x): x is string => typeof x === 'string' && x.trim().length >= 2).slice(0, MAX) : []
  } catch {
    return []
  }
}

function save(list: string[]) {
  try {
    if (list.length) localStorage.setItem(KEY, JSON.stringify(list))
    else localStorage.removeItem(KEY)
  } catch {
    /* storage unavailable: the list just lives for this visit */
  }
}

export function rememberSearch(list: string[], term: string): string[] {
  const t = term.trim().slice(0, 100)
  if (t.length < 2) return list
  const next = [t, ...list.filter((x) => x.toLowerCase() !== t.toLowerCase())].slice(0, MAX)
  save(next)
  return next
}

export function clearRecent(): string[] {
  save([])
  return []
}
