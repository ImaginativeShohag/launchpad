import { useEffect, useRef, useState } from 'react'
import { useLocation, useNavigate } from 'react-router'
import { isApiError } from '@/api/errors'

/** Keeps emailed-link pages from leaking the signed URL: no referrer while mounted. */
export function useNoReferrer() {
  useEffect(() => {
    const meta = document.createElement('meta')
    meta.name = 'referrer'
    meta.content = 'no-referrer'
    document.head.appendChild(meta)
    return () => {
      meta.remove()
    }
  }, [])
}

export type LinkState<T> = { phase: 'pending' } | { phase: 'done'; data: T } | { phase: 'failed'; error: unknown }

/**
 * One call per emailed link, even across a real remount (the address bar no longer shows the link by then, so the
 * entry is keyed by path and remembers the query it was started with).
 */
const started = new Map<string, { search: string; promise: Promise<unknown> }>()

/** For tests. */
export function resetEmailedLinks() {
  started.clear()
}

/**
 * Pages opened from an emailed link. Reads `location.search` once and removes it from the address bar in the same
 * effect (history.replaceState via the router); the call still gets the original query, verbatim. With `keepOn401`
 * the query stays in the address bar until a non-401 answer, so a guest can sign in and come back to it.
 */
export function useEmailedLink<T>(call: (search: string) => Promise<T>, options: { keepOn401?: boolean } = {}) {
  useNoReferrer()
  const location = useLocation()
  const navigate = useNavigate()
  const [initial] = useState(() => location.search)
  const [state, setState] = useState<LinkState<T>>({ phase: 'pending' })
  const callRef = useRef(call)
  useEffect(() => {
    callRef.current = call
  })
  const keepOn401 = !!options.keepOn401
  const pathname = location.pathname
  const search = initial || started.get(pathname)?.search || ''

  useEffect(() => {
    const strip = () => navigate(pathname, { replace: true })
    let entry = started.get(pathname)
    if (!entry || (initial && entry.search !== initial)) {
      entry = { search, promise: callRef.current(search) }
      started.set(pathname, entry)
    }
    if (!keepOn401 && initial) strip()
    let live = true
    ;(entry.promise as Promise<T>)
      .then((data) => {
        if (!live) return
        setState({ phase: 'done', data })
        if (keepOn401 && initial) strip()
      })
      .catch((error: unknown) => {
        const unauthorized = isApiError(error) && error.status === 401
        if (unauthorized) started.delete(pathname)
        if (!live) return
        setState({ phase: 'failed', error })
        if (keepOn401 && initial && !unauthorized) strip()
      })
    return () => {
      live = false
    }
  }, [search, initial, navigate, pathname, keepOn401])

  return { state, search }
}

/** For links that carry a secret but are not forwarded as-is (password reset): read it once, drop it from the URL at once. */
export function useStripQuery(): URLSearchParams {
  useNoReferrer()
  const location = useLocation()
  const navigate = useNavigate()
  const [params] = useState(() => new URLSearchParams(location.search))
  const { pathname, search } = location
  useEffect(() => {
    if (search) navigate(pathname, { replace: true })
  }, [search, pathname, navigate])
  return params
}
