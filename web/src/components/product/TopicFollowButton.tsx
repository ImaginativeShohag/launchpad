import { isApiError } from '@/api/errors'
import { useFollowTopic } from '@/api/hooks/useFollow'
import { useFollowedTopics } from '@/api/hooks/useMyFollows'
import type { Topic } from '@/data/types'
import { useAuthGate } from '@/components/layout/AuthGate'
import { useCooldown } from '@/lib/useCooldown'
import { FollowButton } from './FollowButton'

/** Follow / unfollow a topic. A guest gets the sign-in prompt; the button waits for the member's list and for a rate limit. */
export function TopicFollowButton({ topic, size = 'md', className }: { topic: Topic; size?: 'sm' | 'md'; className?: string }) {
  const gate = useAuthGate()
  const followed = useFollowedTopics()
  const mutation = useFollowTopic()
  const wait = useCooldown()
  const following = followed.data?.some((t) => t.slug === topic.slug) ?? false
  // A signed-in member's list must be known first, or "Follow" could be shown for a topic that is already followed.
  const unknown = followed.fetchStatus !== 'idle' && followed.isPending
  const disabled = unknown || mutation.isPending || wait.remaining > 0

  return (
    <FollowButton
      size={size}
      className={className}
      following={following}
      disabled={disabled}
      label={topic.name}
      onToggle={() =>
        gate('follow topics', () =>
          mutation.mutate({ topic, follow: !following }, { onError: (e) => isApiError(e) && e.status === 429 && wait.start(e.retryAfter ?? 5) }),
        )
      }
    />
  )
}
