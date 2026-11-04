import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { EmailPref } from '@/data/types'
import { getEmailPreferences, updateEmailPreferences } from '../endpoints/me'
import { keys } from '../keys'
import type { EmailPrefDto } from '../types'

export type PrefMap = Partial<Record<EmailPref, boolean>>

const toMap = (list: EmailPrefDto[]): PrefMap => Object.fromEntries(list.map((p) => [p.type, p.enabled]))

export function useEmailPrefs() {
  return useQuery({ queryKey: keys.emailPrefs, staleTime: 0, queryFn: async () => toMap(await getEmailPreferences()) })
}

const MUTATION_KEY = ['email-prefs', 'set'] as const

/** Optimistic per key: a failure puts back only its own key, and the list is re-read once the last toggle settles. */
export function useSetEmailPref() {
  const qc = useQueryClient()
  return useMutation({
    mutationKey: MUTATION_KEY,
    mutationFn: ({ type, enabled }: { type: EmailPref; enabled: boolean }) => updateEmailPreferences({ [type]: enabled }),
    onMutate: async ({ type, enabled }) => {
      await qc.cancelQueries({ queryKey: keys.emailPrefs })
      const previous = qc.getQueryData<PrefMap>(keys.emailPrefs)?.[type]
      qc.setQueryData<PrefMap>(keys.emailPrefs, (old) => ({ ...old, [type]: enabled }))
      return { previous }
    },
    onError: (_e, { type }, ctx) => {
      qc.setQueryData<PrefMap>(keys.emailPrefs, (old) => ({ ...old, ...(ctx?.previous === undefined ? {} : { [type]: ctx.previous }) }))
    },
    onSettled: () => {
      if (qc.isMutating({ mutationKey: MUTATION_KEY }) <= 1) return qc.invalidateQueries({ queryKey: keys.emailPrefs })
    },
  })
}
