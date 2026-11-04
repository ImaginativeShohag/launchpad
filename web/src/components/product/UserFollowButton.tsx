import { isApiError } from '@/api/errors'
import { useFollowUser } from '@/api/hooks/useFollow'
import type { User } from '@/data/types'
import { useAuthGate } from '@/components/layout/AuthGate'
import { useCooldown } from '@/lib/useCooldown'
import { useSession } from '@/session/SessionProvider'
import { FollowButton } from './FollowButton'

/**
 * Follow / unfollow a member, from their loaded public profile (`isFollowing` is absent for a guest). Renders nothing for
 * yourself or a suspended member. A guest gets the sign-in prompt.
 */
export function UserFollowButton({ profile, size = 'md' }: { profile: User; size?: 'sm' | 'md' }) {
  const gate = useAuthGate()
  const { user } = useSession()
  const mutation = useFollowUser()
  const wait = useCooldown()
  if (user?.id === profile.id || profile.suspended) return null
  const following = profile.isFollowing ?? false

  return (
    <FollowButton
      size={size}
      following={following}
      disabled={mutation.isPending || wait.remaining > 0}
      label={profile.name}
      onToggle={() =>
        gate('follow makers', () =>
          mutation.mutate({ handle: profile.handle, follow: !following }, { onError: (e) => isApiError(e) && e.status === 429 && wait.start(e.retryAfter ?? 5) }),
        )
      }
    />
  )
}
