import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  changePassword,
  deleteAccount,
  removeAvatar,
  requestEmailChange,
  updateProfile,
  uploadAvatar,
  type ProfilePatch,
} from '../endpoints/me'
import { keys } from '../keys'
import { toUser } from '../mappers'
import type { User } from '@/data/types'
import type { MeDto } from '../types'

/** Writes the server's authoritative `me` back to the cache (no refetch, so nothing flickers). */
function useWriteMe() {
  const qc = useQueryClient()
  return (dto: MeDto) => qc.setQueryData(keys.me, toUser(dto))
}

export function useUpdateProfile() {
  const write = useWriteMe()
  return useMutation({ mutationFn: (patch: ProfilePatch) => updateProfile(patch), onSuccess: write })
}

export function useUploadAvatar() {
  const write = useWriteMe()
  return useMutation({ mutationFn: ({ file, onProgress }: { file: File; onProgress?: (pct: number) => void }) => uploadAvatar(file, onProgress), onSuccess: write })
}

export function useRemoveAvatar() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: () => removeAvatar(),
    onSuccess: () => qc.setQueryData<User | null>(keys.me, (u) => (u ? { ...u, avatarUrl: undefined } : u)),
  })
}

export const useChangePassword = () => useMutation({ mutationFn: (input: { current_password: string; password: string }) => changePassword(input) })

export function useRequestEmailChange() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (input: { email: string; current_password?: string }) => requestEmailChange(input),
    onSuccess: () => qc.invalidateQueries({ queryKey: keys.me }),
  })
}

/** The API ends the session on success; the caller navigates away first and then drops the cache (see AccountTab). */
export function useDeleteAccount() {
  return useMutation({ mutationFn: (input: { current_password?: string }) => deleteAccount(input) })
}
