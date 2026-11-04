import { config } from '@/config'
import { api } from '../client'
import type { ApiItem, MeDto, MessageDto } from '../types'

export interface RegisterInput {
  name: string
  email: string
  password: string
  handle: string
}

export const register = (input: RegisterInput) => api.post<ApiItem<MeDto>>('/auth/register', { body: input }).then((r) => r.data)

export const login = (input: { email: string; password: string; remember?: boolean }) => api.post<ApiItem<MeDto>>('/auth/login', { body: input }).then((r) => r.data)

export const logout = () => api.post<void>('/auth/logout')

export const forgotPassword = (email: string) => api.post<MessageDto>('/auth/password/forgot', { body: { email } })

export const resetPassword = (input: { email: string; token: string; password: string }) => api.post<MessageDto>('/auth/password/reset', { body: input })

/** The emailed link's query string, forwarded verbatim. Needs a signed-in session. */
export const verifyEmail = (rawQuery: string) => api.post<ApiItem<MeDto>>('/auth/email/verify', { rawQuery }).then((r) => r.data)

export const resendVerification = () => api.post<void>('/auth/email/resend')

export const confirmEmailChange = (rawQuery: string) => api.post<MessageDto>('/auth/email/change/confirm', { rawQuery })

export const cancelEmailChange = (rawQuery: string) => api.post<MessageDto>('/auth/email/change/cancel', { rawQuery })

/** Browser flow: the page leaves the SPA; the API redirects back to WEB_URL. */
export const googleRedirectUrl = () => `${config.apiUrl}/api/v1/auth/google/redirect`
