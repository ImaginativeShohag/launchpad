import { vi } from 'vitest'
import { setEchoFactory, type RawEcho } from '@/realtime/echo'

type Handler = (payload: unknown) => void

/** A stand-in for laravel-echo: records who listens where, and lets a test push frames and connection changes. */
export function installFakeEcho(initial = 'connected') {
  const listeners = new Map<string, Map<string, Set<Handler>>>()
  const left: string[] = []
  const listens: string[] = []
  const statusCbs = new Set<(s: string) => void>()
  let status = initial
  const factory = vi.fn()

  const open = (name: string) => raw.channel(name)
  const raw: RawEcho = {
    private: (name) => open(`private-${name}`),
    channel(name) {
      return {
        listen(event, handler) {
          listens.push(name)
          const byEvent = listeners.get(name) ?? new Map<string, Set<Handler>>()
          listeners.set(name, byEvent)
          byEvent.set(event, (byEvent.get(event) ?? new Set()).add(handler))
          return this
        },
        stopListening(event, handler) {
          const set = listeners.get(name)?.get(event)
          if (!set) return this
          if (handler) set.delete(handler)
          else set.clear()
          if (!set.size) listeners.get(name)?.delete(event)
          return this
        },
      }
    },
    leaveChannel: (name) => {
      left.push(name)
      listeners.delete(name)
    },
    connectionStatus: () => status,
    onConnectionChange(cb) {
      statusCbs.add(cb)
      return () => statusCbs.delete(cb)
    },
    disconnect: () => {},
  }
  factory.mockImplementation(() => raw)
  setEchoFactory(factory)

  return {
    factory,
    left,
    /** Every channel a listener was attached to, in order (a join per entry). */
    listens,
    /** Number of handlers on `channel` / `event`. */
    count: (channel: string, event: string) => listeners.get(channel)?.get(event)?.size ?? 0,
    channels: () => [...listeners.keys()].filter((c) => (listeners.get(c)?.size ?? 0) > 0),
    emit(channel: string, event: string, payload: unknown) {
      for (const h of [...(listeners.get(channel)?.get(event) ?? [])]) h(payload)
    },
    setStatus(next: string) {
      status = next
      for (const cb of [...statusCbs]) cb(next)
    },
  }
}

export const removeFakeEcho = () => setEchoFactory(null)
