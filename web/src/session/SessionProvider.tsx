import { useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query'
import { createContext, use, useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { setAccountUnavailableHandler, setUnauthorizedHandler } from '@/api/client'
import { ApiError, isApiError } from '@/api/errors'
import { keys } from '@/api/keys'
import { login, logout } from '@/api/endpoints/auth'
import { getMe } from '@/api/endpoints/me'
import { toUser } from '@/api/mappers'
import type { User } from '@/data/types'

export type Session = {
  status: 'loading' | 'guest' | 'member'
  user: User | null
  isStaff: boolean
  isAdmin: boolean
  isVerified: boolean
  /** Set when `/me` failed for a reason other than "not signed in" (network, 5xx). The status is then 'guest' but pages should offer Retry. */
  error: ApiError | null
  /** Set once when the server ended the session because the account is suspended or deleted. */
  notice: 'account_unavailable' | null
  clearNotice(): void
  signIn(email: string, password: string, remember: boolean): Promise<void>
  signOut(): Promise<void>
  refresh(): Promise<void>
}

const SessionContext = createContext<Session | null>(null)

/** Everything private goes; `me` becomes "guest". Used by sign-out and by a 401 from any call. */
export async function dropSession(qc: QueryClient) {
  await qc.cancelQueries()
  qc.setQueryData(keys.me, null)
  qc.removeQueries({ predicate: (q) => q.queryKey[0] !== 'me' })
}

export function SessionProvider({ children }: { children: ReactNode }) {
  const qc = useQueryClient()
  const [notice, setNotice] = useState<Session['notice']>(null)

  const me = useQuery({
    queryKey: keys.me,
    staleTime: 0,
    queryFn: async (): Promise<User | null> => {
      try {
        return toUser(await getMe())
      } catch (e) {
        // 401 is the normal guest state. A suspended account is a guest too (the server already ended the session).
        if (isApiError(e) && (e.status === 401 || e.code === 'account_unavailable')) return null
        throw e
      }
    },
  })

  useEffect(() => {
    setUnauthorizedHandler(() => void dropSession(qc))
    setAccountUnavailableHandler(() => {
      setNotice('account_unavailable')
      void dropSession(qc)
    })
    return () => {
      setUnauthorizedHandler(() => {})
      setAccountUnavailableHandler(() => {})
    }
  }, [qc])

  const refresh = useCallback(async () => {
    await qc.refetchQueries({ queryKey: keys.me })
  }, [qc])

  const signIn = useCallback(
    async (email: string, password: string, remember: boolean) => {
      const dto = await login({ email, password, remember })
      qc.setQueryData(keys.me, toUser(dto))
      // `/me` carries the links that the login body lacks; it also proves the session cookie really stuck.
      await qc.refetchQueries({ queryKey: keys.me })
      if (!qc.getQueryData(keys.me)) {
        throw new ApiError({ status: 0, code: 'session_not_kept', message: 'Signed in, but the browser did not keep the session. Open the site on the same host as the API (localhost, not 127.0.0.1).' })
      }
    },
    [qc],
  )

  const signOut = useCallback(async () => {
    try {
      await logout()
    } catch {
      /* already signed out, or offline: the local session goes either way */
    }
    await dropSession(qc)
  }, [qc])

  const clearNotice = useCallback(() => setNotice(null), [])

  const user = me.data ?? null
  const status: Session['status'] = me.isPending ? 'loading' : user ? 'member' : 'guest'
  const error = !user && isApiError(me.error) ? me.error : null

  const value = useMemo<Session>(
    () => ({
      status,
      user,
      isStaff: user?.role === 'reviewer' || user?.role === 'admin',
      isAdmin: user?.role === 'admin',
      isVerified: !!user?.emailVerified,
      error,
      notice,
      clearNotice,
      signIn,
      signOut,
      refresh,
    }),
    [status, user, error, notice, clearNotice, signIn, signOut, refresh],
  )

  return <SessionContext value={value}>{children}</SessionContext>
}

export function useSession(): Session {
  const ctx = use(SessionContext)
  if (!ctx) throw new Error('useSession must be used inside SessionProvider')
  return ctx
}

/** The API session's member (null for guests and while loading). */
export function useCurrentUser(): User | null {
  return useSession().user
}

export function useIsStaff(): boolean {
  return useSession().isStaff
}
