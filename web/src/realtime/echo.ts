import { api } from '@/api/client'
import { config } from '@/config'

/** The part of laravel-echo this app uses. Tests inject a fake with this shape through `setEchoFactory`. */
export interface RawChannel {
  listen(event: string, handler: (payload: unknown) => void): unknown
  stopListening(event: string, handler?: (payload: unknown) => void): unknown
}

export interface RawEcho {
  channel(name: string): RawChannel
  /** A private channel (`private-` is added on the wire); joining it calls the authorizer. */
  private(name: string): RawChannel
  leaveChannel(name: string): void
  connectionStatus(): string
  onConnectionChange(cb: (status: string) => void): () => void
  disconnect(): void
}

export type LiveHandler = (payload: unknown) => void

export interface ListenOptions {
  /** Join `private-<channel>` (authorised through the API) instead of a public channel. */
  private?: boolean
}

export interface LiveEcho {
  /** Listens for `event` (with the leading dot for a broadcastAs() name) on `channel`; returns the unsubscribe. */
  listen(channel: string, event: string, handler: LiveHandler, options?: ListenOptions): () => void
  /** Called now with the current state and again on every change. */
  onStatus(cb: (connected: boolean) => void): () => void
  disconnect(): void
}

/** Wraps Echo so a channel is joined once however many listeners it has, and left when the last one goes. */
export function wrapEcho(echo: RawEcho): LiveEcho {
  const refs = new Map<string, number>()
  return {
    listen(channel, event, handler, options) {
      // A public and a private channel can share a name, so the wire name (with its prefix) is the key.
      const wire = options?.private ? `private-${channel}` : channel
      const open = () => (options?.private ? echo.private(channel) : echo.channel(channel))
      open().listen(event, handler)
      refs.set(wire, (refs.get(wire) ?? 0) + 1)
      let done = false
      return () => {
        if (done) return
        done = true
        open().stopListening(event, handler)
        const left = (refs.get(wire) ?? 1) - 1
        if (left <= 0) {
          refs.delete(wire)
          echo.leaveChannel(wire)
        } else refs.set(wire, left)
      }
    },
    onStatus(cb) {
      cb(echo.connectionStatus() === 'connected')
      return echo.onConnectionChange((s) => cb(s === 'connected'))
    },
    disconnect: () => echo.disconnect(),
  }
}

/** Private channels authorise through the API client (cookie session + CSRF), not through Echo's default `/broadcasting/auth`. */
export function channelAuthorizer(channel: { name: string }) {
  return {
    authorize(socketId: string, callback: (error: Error | null, data: { auth: string; channel_data?: string } | null) => void) {
      api
        .post<{ auth: string; channel_data?: string }>('/broadcasting/auth', { body: { socket_id: socketId, channel_name: channel.name } })
        .then((data) => callback(null, data))
        .catch((e: unknown) => callback(e instanceof Error ? e : new Error('Channel authorisation failed'), null))
    },
  }
}

/** laravel-echo and pusher-js are loaded only when a key is configured, so a build or a test without Reverb opens no socket. */
export async function createReverbEcho(): Promise<RawEcho> {
  const [{ default: Echo }, { default: Pusher }] = await Promise.all([import('laravel-echo'), import('pusher-js')])
  const { host, port, scheme, appKey } = config.reverb
  const echo = new Echo({
    broadcaster: 'reverb',
    key: appKey,
    wsHost: host,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
    Pusher,
    authorizer: channelAuthorizer,
  })
  // The Echo class has `connectionStatus()` but not `onConnectionChange()`: that one lives on its connector.
  const connector = echo.connector as unknown as { onConnectionChange(cb: (status: string) => void): () => void }
  return {
    channel: (name) => echo.channel(name),
    private: (name) => echo.private(name),
    leaveChannel: (name) => echo.leaveChannel(name),
    connectionStatus: () => echo.connectionStatus(),
    onConnectionChange: (cb) => connector.onConnectionChange(cb),
    disconnect: () => echo.disconnect(),
  }
}

type Factory = () => RawEcho | null | Promise<RawEcho | null>
let factory: Factory | null = null
let instance: Promise<LiveEcho | null> | null = null

/** Test hook: use this instead of the real Echo (pass null to restore). Also forgets the current connection. */
export function setEchoFactory(f: Factory | null) {
  factory = f
  instance = null
}

/** The shared connection, or null when live updates are off (no `VITE_REVERB_APP_KEY`) or Echo could not load. */
export function getLiveEcho(): Promise<LiveEcho | null> {
  if (instance) return instance
  const make: Factory | null = factory ?? (config.reverb.appKey ? createReverbEcho : null)
  if (typeof window === 'undefined' || !make) return Promise.resolve(null)
  const attempt: Promise<LiveEcho | null> = Promise.resolve()
    .then(make)
    .then((raw) => (raw ? wrapEcho(raw) : null))
    .catch(() => {
      // A flaky dynamic import must not disable live updates for the whole session: the next subscribe tries again.
      if (instance === attempt) instance = null
      return null
    })
  instance = attempt
  return attempt
}

/** Listens on a channel as soon as Echo is ready; the returned function works before and after that. */
export function subscribeLive(channel: string, event: string, handler: LiveHandler, options?: ListenOptions): () => void {
  let stopped = false
  let off: (() => void) | undefined
  void getLiveEcho().then((echo) => {
    if (echo && !stopped) off = echo.listen(channel, event, handler, options)
  })
  return () => {
    stopped = true
    off?.()
    off = undefined
  }
}

/** Reports whether the socket is connected (false while it is off or down). */
export function subscribeStatus(cb: (connected: boolean) => void): () => void {
  let stopped = false
  let off: (() => void) | undefined
  void getLiveEcho().then((echo) => {
    if (echo && !stopped) off = echo.onStatus(cb)
  })
  return () => {
    stopped = true
    off?.()
    off = undefined
  }
}
