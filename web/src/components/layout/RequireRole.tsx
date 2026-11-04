import { Lock, MailCheck, RotateCw } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link, Navigate, useLocation } from 'react-router'
import type { Role } from '@/data/types'
import { signInPath } from '@/lib/nextPath'
import { useSession } from '@/session/SessionProvider'
import { Button, EmptyState, Skeleton } from '@/components/ui'

/**
 * Guards a route by role. Guests go to sign-in (and come back), the wrong role gets a "not allowed" wall,
 * and `verified` routes (those the API protects with verified.api) ask an unverified member to confirm their email.
 */
export function RequireRole({ roles, verified, children }: { roles: Role[]; verified?: boolean; children: ReactNode }) {
  const session = useSession()
  const location = useLocation()

  if (session.status === 'loading') {
    return (
      <div className="space-y-4">
        <Skeleton className="h-10 w-64" />
        <Skeleton className="h-40 w-full" />
      </div>
    )
  }
  if (session.error) {
    return (
      <EmptyState
        icon={<RotateCw className="size-6" />}
        title="We couldn’t load your session"
        body={session.error.message}
        action={<Button onClick={() => void session.refresh()}>Try again</Button>}
      />
    )
  }
  if (!session.user) return <Navigate to={signInPath(location.pathname + location.search)} replace />

  if (!roles.includes(session.user.role)) {
    return (
      <EmptyState
        icon={<Lock className="size-6" />}
        title="Not allowed"
        body="You don’t have access to this page."
        action={<Link to="/"><Button variant="outline">Back to today</Button></Link>}
      />
    )
  }
  if (verified && !session.isVerified) {
    return (
      <EmptyState
        icon={<MailCheck className="size-6" />}
        title="Verify your email first"
        body="This page unlocks once you confirm your email address."
        action={<Link to="/verify-email"><Button>Verify email</Button></Link>}
      />
    )
  }
  return children
}
