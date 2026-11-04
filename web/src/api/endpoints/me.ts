import { api } from '../client'
import type { ApiItem, ApiList, EmailPrefDto, LinkKind, MeDto } from '../types'

export const getMe = () => api.get<ApiItem<MeDto>>('/me').then((r) => r.data)

export interface ProfilePatch {
  name?: string
  handle?: string
  headline?: string | null
  links?: { kind: LinkKind; label: string | null; url: string }[]
}

export const updateProfile = (patch: ProfilePatch) => api.patch<ApiItem<MeDto>>('/me', { body: patch }).then((r) => r.data)

export const uploadAvatar = (file: File, onProgress?: (pct: number) => void) => {
  const form = new FormData()
  form.append('avatar', file)
  return api.upload<ApiItem<MeDto>>('/me/avatar', form, { onProgress }).then((r) => r.data)
}

export const removeAvatar = () => api.delete<void>('/me/avatar')

export const changePassword = (input: { current_password: string; password: string }) => api.put<void>('/me/password', { body: input })

export const requestEmailChange = (input: { email: string; current_password?: string }) => api.post<{ message: string }>('/me/email', { body: input })

export const deleteAccount = (input: { current_password?: string }) => api.delete<void>('/me', { body: input })

export const getEmailPreferences = () => api.get<ApiList<EmailPrefDto>>('/me/email-preferences').then((r) => r.data)

export const updateEmailPreferences = (preferences: Record<string, boolean>) =>
  api.patch<ApiList<EmailPrefDto>>('/me/email-preferences', { body: { preferences } }).then((r) => r.data)

/** The unsubscribe link's query string, forwarded verbatim. Public. */
export const unsubscribe = (rawQuery: string) => api.post<{ type: string; enabled: boolean }>('/email/unsubscribe', { rawQuery })
