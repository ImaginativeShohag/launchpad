import { useCallback, useState } from 'react'
import { isApiError, type FieldErrors } from '@/api/errors'

/** 422 `errors` keyed by field name, shown on the inputs; typing in a field clears its error. */
export function useServerErrors() {
  const [fields, setFields] = useState<FieldErrors>({})
  const get = useCallback((name: string) => fields[name]?.[0], [fields])
  const set = useCallback((e: unknown) => setFields(isApiError(e) ? e.fields : {}), [])
  const clear = useCallback((name: string) => setFields((f) => (name in f ? Object.fromEntries(Object.entries(f).filter(([k]) => k !== name)) : f)), [])
  const reset = useCallback(() => setFields({}), [])
  return { fields, get, set, clear, reset }
}
