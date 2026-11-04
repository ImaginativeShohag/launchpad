const KEY = 'launchpad-device-id'
let memo: string | null = null

function generate(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID()
  const bytes = new Uint8Array(16)
  if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') crypto.getRandomValues(bytes)
  else for (let i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256)
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
}

/** A random id kept in localStorage; sent as X-Device-Id (the API wants 16-64 chars of [A-Za-z0-9-]). */
export function getDeviceId(): string {
  if (memo) return memo
  try {
    const stored = localStorage.getItem(KEY)
    if (stored && /^[A-Za-z0-9-]{16,64}$/.test(stored)) return (memo = stored)
  } catch {
    /* storage blocked: fall back to a per-load id */
  }
  memo = generate()
  try {
    localStorage.setItem(KEY, memo)
  } catch {
    /* ignore */
  }
  return memo
}

export function resetDeviceIdCache() {
  memo = null
}
