import { useQueryClient } from '@tanstack/react-query'
import { useCallback, useEffect, useRef, useState } from 'react'
import type { FounderProduct } from '@/data/types'
import type { DraftForm } from '@/lib/productForm'
import { DraftSaver, type SaveResult, type SaveState } from '../draftSaver'
import type { FieldErrors } from '../errors'

/**
 * The wizard's autosave: `schedule(form)` on every edit (debounced 1.2 s), `flush()` before leaving, uploading or submitting.
 * `errors` are the API's 422 fields of the last failed save; `failure` is any other error (a lock, a rate limit, the network).
 */
export function useProductDraft(product: FounderProduct | undefined, onCreated: (p: FounderProduct) => void) {
  const qc = useQueryClient()
  const [state, setState] = useState<SaveState>(product ? 'saved' : 'idle')
  const [savedAt, setSavedAt] = useState<string | null>(product?.updatedAt || null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [failure, setFailure] = useState<unknown>(null)
  const created = useRef(onCreated)
  useEffect(() => {
    created.current = onCreated
  })

  const [saver] = useState(() => new DraftSaver(qc, product, { state: () => {}, saved: () => {}, errors: () => {}, failure: () => {}, created: () => {} }))
  useEffect(() => {
    saver.bind({ state: setState, saved: setSavedAt, errors: setErrors, failure: setFailure, created: (p) => created.current(p) })
    return () => {
      // Unsaved work (a waiting edit, or a save that failed) gets one more try, not a silent drop, when the member leaves the page.
      const pending = saver.pending
      saver.dispose()
      if (pending) void saver.flush().then(() => qc.invalidateQueries({ queryKey: ['my-products'] }))
    }
  }, [saver, qc])

  // Closing the tab or switching away: best effort (the client has no keepalive path, so this is an ordinary request).
  useEffect(() => {
    const leave = () => {
      if (saver.pending) void saver.flush()
    }
    const hidden = () => document.visibilityState === 'hidden' && leave()
    window.addEventListener('pagehide', leave)
    document.addEventListener('visibilitychange', hidden)
    return () => {
      window.removeEventListener('pagehide', leave)
      document.removeEventListener('visibilitychange', hidden)
    }
  }, [saver])

  const schedule = useCallback(
    (form: DraftForm) => {
      setFailure(null)
      saver.schedule(form)
    },
    [saver],
  )
  const flush = useCallback((): Promise<SaveResult> => saver.flush(), [saver])
  const clearError = useCallback((name: string) => setErrors((f) => (name in f ? Object.fromEntries(Object.entries(f).filter(([k]) => k !== name)) : f)), [])
  const id = () => saver.id

  return { state, savedAt, errors, failure, schedule, flush, clearError, getId: id, setServerErrors: setErrors }
}
