import { useMutation, useQueryClient } from '@tanstack/react-query'
import { forgotPassword, register, resendVerification, resetPassword, type RegisterInput } from '../endpoints/auth'
import { keys } from '../keys'
import { toUser } from '../mappers'

/** Registering signs the browser in (session cookie); the new member lands unverified. */
export function useRegister() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (input: RegisterInput) => register(input),
    onSuccess: (dto) => qc.setQueryData(keys.me, toUser(dto)),
  })
}

export const useForgotPassword = () => useMutation({ mutationFn: (email: string) => forgotPassword(email) })

export const useResetPassword = () => useMutation({ mutationFn: (input: { email: string; token: string; password: string }) => resetPassword(input) })

export const useResendVerification = () => useMutation({ mutationFn: () => resendVerification() })
