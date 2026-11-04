import { useEffect, useRef, useState } from 'react'

export interface Ranked {
  id: string
  rank?: number
}

/** Rank of each product: the API's `rank`, else its 1-based position. */
export const rankMap = (list: readonly Ranked[]): Map<string, number> => new Map(list.map((p, i) => [p.id, p.rank ?? i + 1]))

/** Positive = moved up that many places. Products that were not in `prev` (new launches) get no pill. */
export function diffRanks(prev: ReadonlyMap<string, number>, next: ReadonlyMap<string, number>): Record<string, number> {
  const out: Record<string, number> = {}
  next.forEach((rank, id) => {
    const was = prev.get(id)
    if (was !== undefined && was !== rank) out[id] = was - rank
  })
  return out
}

/** The move pills of a live list: each stays for `ttlMs` after the reorder that caused it. */
export function useRankMoves(list: readonly Ranked[], ttlMs = 8000): Record<string, number> {
  const prev = useRef<Map<string, number> | null>(null)
  const timers = useRef<ReturnType<typeof setTimeout>[]>([])
  const tokens = useRef(new Map<string, number>())
  const counter = useRef(0)
  const [moves, setMoves] = useState<Record<string, number>>({})
  const orderKey = list.map((p) => `${p.id}:${p.rank ?? ''}`).join('|')

  useEffect(() => {
    const next = rankMap(list)
    const old = prev.current
    prev.current = next
    if (!old) return
    const delta = diffRanks(old, next)
    const ids = Object.keys(delta)
    if (!ids.length) return
    // Each pill gets its own token, so an older timer never clears a pill that a newer reorder set for the same product.
    const stamp = ++counter.current
    for (const id of ids) tokens.current.set(id, stamp)
    setMoves((m) => ({ ...m, ...delta }))
    timers.current.push(
      setTimeout(() => {
        const expired = ids.filter((id) => tokens.current.get(id) === stamp)
        for (const id of expired) tokens.current.delete(id)
        setMoves((m) => {
          const rest = { ...m }
          for (const id of expired) delete rest[id]
          return rest
        })
      }, ttlMs),
    )
    // `list` is read through `orderKey`: a new array with the same order is not a move.
  }, [orderKey, ttlMs]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => () => timers.current.forEach(clearTimeout), [])
  return moves
}
