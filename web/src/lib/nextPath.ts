/** Only same-origin relative paths survive: "/x" yes; "//evil.com", "/\evil.com", "https://evil.com", "javascript:" no. */
export function safeNext(raw: string | null | undefined, fallback = '/'): string {
  if (!raw || typeof raw !== 'string') return fallback
  if (!raw.startsWith('/') || raw.startsWith('//') || raw.includes('\\')) return fallback
  for (let i = 0; i < raw.length; i++) {
    const c = raw.charCodeAt(i)
    if (c < 32 || c === 127) return fallback
  }
  try {
    const base = 'http://launchpad.invalid'
    if (new URL(raw, base).origin !== base) return fallback
  } catch {
    return fallback
  }
  return raw
}

export function signInPath(next: string): string {
  return next === '/' ? '/signin' : `/signin?next=${encodeURIComponent(next)}`
}
