import { useEffect, useState } from 'react'

/** Re-renders every `interval` ms and returns the current timestamp. */
export function useNow(interval = 1000): number {
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    const t = setInterval(() => setNow(Date.now()), interval)
    return () => clearInterval(t)
  }, [interval])
  return now
}

/** Time left until the next UTC midnight (the end of the launch day), split into h / m / s. */
export function useCountdownToMidnight() {
  const now = useNow(1000)
  const d = new Date(now)
  const midnight = Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate() + 1)
  const left = Math.max(0, Math.floor((midnight - now) / 1000))
  return { h: Math.floor(left / 3600), m: Math.floor((left % 3600) / 60), s: left % 60, total: left }
}
