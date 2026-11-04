import { QueryClientProvider, type QueryClient } from '@tanstack/react-query'
import { useEffect, type ReactNode } from 'react'
import { ToastProvider, useToast } from '@/components/ui'
import { SessionProvider, useSession } from '@/session/SessionProvider'
import { ThemeProvider } from '@/theme/ThemeProvider'

/** One place for notices the session raises on its own (a suspended account loses its session mid-use). */
function SessionNotices() {
  const { notice, clearNotice } = useSession()
  const toast = useToast()
  useEffect(() => {
    if (notice !== 'account_unavailable') return
    toast({ tone: 'error', title: 'You were signed out', body: 'This account is unavailable.' })
    clearNotice()
  }, [notice, toast, clearNotice])
  return null
}

/**
 * Query cache > theme > session > toasts.
 */
export function AppProviders({ client, children }: { client: QueryClient; children: ReactNode }) {
  return (
    <QueryClientProvider client={client}>
      <ThemeProvider>
        <SessionProvider>
          <ToastProvider>
            <SessionNotices />
            {children}
          </ToastProvider>
        </SessionProvider>
      </ThemeProvider>
    </QueryClientProvider>
  )
}
