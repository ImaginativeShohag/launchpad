import { useCallback, useEffect, useState } from 'react'

/** A countdown in whole seconds, for "try again in N s" and the buttons a 429 disabled. */
export function useCooldown(initial = 0) {
  const [until, setUntil] = useState(() => (initial > 0 ? Date.now() + initial * 1000 : 0))
  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    if (until <= Date.now()) return
    const t = setInterval(() => {
      const n = Date.now()
      setNow(n)
      if (n >= until) clearInterval(t)
    }, 500)
    return () => clearInterval(t)
  }, [until])

  const start = useCallback((seconds: number) => {
    const n = Date.now()
    setNow(n)
    setUntil(seconds > 0 ? n + seconds * 1000 : 0)
  }, [])

  return { remaining: Math.max(0, Math.ceil((until - now) / 1000)), start }
}
