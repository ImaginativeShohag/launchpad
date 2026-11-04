import { afterEach, describe, expect, it, vi } from 'vitest'
import { channelAuthorizer, createReverbEcho, wrapEcho, type RawEcho } from '@/realtime/echo'
import { json, mockApi } from '../helpers/mockApi'

// The real Echo class has connectionStatus() but keeps onConnectionChange() on its connector; this mirrors that shape.
vi.mock('laravel-echo', () => ({
  default: class {
    connector = { onConnectionChange: (cb: (s: string) => void) => (cb('connected'), () => {}) }
    channel = () => ({})
    private = () => ({})
    leaveChannel() {}
    connectionStatus = () => 'connecting'
    disconnect() {}
  },
}))
vi.mock('pusher-js', () => ({ default: class {} }))

afterEach(() => vi.restoreAllMocks())

describe('createReverbEcho', () => {
  it('exposes onConnectionChange from the connector, so wrapEcho can report the socket state', async () => {
    // Given the real Echo shape (the method lives on echo.connector)
    const raw = await createReverbEcho()
    const seen: boolean[] = []

    // When the wrapper asks for status updates
    const off = wrapEcho(raw).onStatus((c) => seen.push(c))

    // Then it works instead of throwing, and reports the current and the changed state
    expect(seen).toEqual([false, true])
    off()
  })
})

function raw() {
  const listen = vi.fn()
  const stopListening = vi.fn()
  const leaveChannel = vi.fn()
  const r: RawEcho = {
    channel: () => ({ listen, stopListening }) as never,
    private: () => ({ listen, stopListening }) as never,
    leaveChannel,
    connectionStatus: () => 'connected',
    onConnectionChange: () => () => {},
    disconnect: () => {},
  }
  return { r, listen, stopListening, leaveChannel }
}

describe('wrapEcho', () => {
  it('leaves a channel only when its last listener is gone, and an unsubscribe works once', () => {
    const { r, stopListening, leaveChannel } = raw()
    const echo = wrapEcho(r)
    const a = echo.listen('today', '.rank.updated', () => {})
    const b = echo.listen('today', '.rank.updated', () => {})

    a()
    a()
    expect(leaveChannel).not.toHaveBeenCalled()
    b()

    expect(stopListening).toHaveBeenCalledTimes(2)
    expect(leaveChannel).toHaveBeenCalledTimes(1)
    expect(leaveChannel).toHaveBeenCalledWith('today')
  })
})

describe('wrapEcho private channels', () => {
  it('joins private-<name> and leaves it by its wire name, apart from a public channel of the same name', () => {
    const { r, listen, leaveChannel } = raw()
    const echo = wrapEcho(r)
    const priv = echo.listen('user.1', '.notification.count', () => {}, { private: true })
    const pub = echo.listen('user.1', '.notification.count', () => {})

    expect(listen).toHaveBeenCalledTimes(2)
    priv()
    expect(leaveChannel).toHaveBeenCalledWith('private-user.1')
    pub()
    expect(leaveChannel).toHaveBeenLastCalledWith('user.1')
  })
})

describe('channelAuthorizer', () => {
  it('authorises a private channel through the API client with the cookie session', async () => {
    const api = mockApi({ 'POST /broadcasting/auth': json({ auth: 'key:signature' }) })
    const done = new Promise<{ error: Error | null; data: unknown }>((resolve) => {
      channelAuthorizer({ name: 'private-user.01J' }).authorize('123.456', (error, data) => resolve({ error, data }))
    })

    expect(await done).toEqual({ error: null, data: { auth: 'key:signature' } })
    const call = api.callsTo('POST /broadcasting/auth')[0]
    expect(call.body).toEqual({ socket_id: '123.456', channel_name: 'private-user.01J' })
    expect(call.credentials).toBe('include')
    expect(call.headers.get('X-XSRF-TOKEN')).toBeTruthy()
  })

  it('reports a refused channel as an error', async () => {
    mockApi({ 'POST /broadcasting/auth': { status: 403, body: { message: 'Forbidden' } } })
    const done = new Promise<Error | null>((resolve) => channelAuthorizer({ name: 'private-user.01J' }).authorize('1.2', (error) => resolve(error)))

    expect((await done)?.message).toBe('Forbidden')
  })
})
