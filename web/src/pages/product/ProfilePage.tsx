import { motion } from 'motion/react'
import { Ban, CalendarDays, Package, Settings, UserX } from 'lucide-react'
import { Link, useParams } from 'react-router'
import { isApiError } from '@/api/errors'
import { useUserProducts } from '@/api/hooks/useUserProducts'
import { useUserProfile } from '@/api/hooks/useUserProfile'
import { LinkPill } from '@/components/product/LinkPill'
import { ProductRow } from '@/components/product/ProductRow'
import { UserFollowButton } from '@/components/product/UserFollowButton'
import { AnimatedNumber, Avatar, Badge, Button, EmptyState, ErrorState, LoadMore, Skeleton } from '@/components/ui'
import { formatDay } from '@/lib/date'
import { useSession } from '@/session/SessionProvider'
import { PageState } from './components/shared'

export default function ProfilePage() {
  const { handle } = useParams()
  const { user: me } = useSession()
  const profile = useUserProfile(handle)
  const user = profile.data
  const suspended = !!user?.suspended
  const products = useUserProducts(handle, !!user && !suspended)

  if (profile.isPending) {
    return (
      <div className="mx-auto max-w-4xl space-y-4" aria-busy="true" aria-label="Loading profile">
        <Skeleton className="h-36 w-full rounded-3xl sm:h-52" />
        <Skeleton className="h-24 w-full rounded-2xl" />
      </div>
    )
  }
  if (profile.isError) {
    if (isApiError(profile.error) && profile.error.status === 404) {
      return (
        <PageState
          icon={<UserX />}
          title="Member not found"
          body={`We couldn’t find anyone with the handle @${handle}. They may have changed it.`}
          action={<Link to="/"><Button>Back to launches</Button></Link>}
        />
      )
    }
    return <ErrorState error={profile.error} onRetry={() => void profile.refetch()} title="Could not load this profile" />
  }
  if (!user) return null

  const isSelf = me?.id === user.id

  return (
    <div className="mx-auto max-w-4xl">
      {/* Cover */}
      <motion.div
        initial={{ opacity: 0, scale: 0.98 }}
        animate={{ opacity: 1, scale: 1 }}
        transition={{ type: 'spring', stiffness: 200, damping: 26 }}
        className="relative h-36 overflow-hidden rounded-3xl sm:h-52"
        style={{ background: `linear-gradient(120deg, hsl(${user.hue} 85% 62%), hsl(${(user.hue + 40) % 360} 85% 55%) 55%, hsl(${(user.hue + 90) % 360} 80% 60%))` }}
      >
        <motion.div
          aria-hidden
          className="absolute -top-16 -left-10 size-64 rounded-full bg-white/25 blur-3xl"
          animate={{ x: [0, 40, 0], y: [0, 20, 0] }}
          transition={{ repeat: Infinity, duration: 12, ease: 'easeInOut' }}
        />
        <motion.div
          aria-hidden
          className="absolute -right-10 -bottom-24 size-72 rounded-full blur-3xl"
          style={{ background: `hsl(${(user.hue + 180) % 360} 90% 70% / 0.35)` }}
          animate={{ x: [0, -50, 0], y: [0, -10, 0] }}
          transition={{ repeat: Infinity, duration: 14, ease: 'easeInOut' }}
        />
        <div aria-hidden className="absolute inset-0 bg-[radial-gradient(rgb(255_255_255/0.18)_1px,transparent_1px)] [background-size:18px_18px]" />
      </motion.div>

      {/* Identity */}
      <div className="px-2 sm:px-6">
        <div className="-mt-12 flex items-end justify-between gap-3 sm:-mt-14">
          <motion.div initial={{ scale: 0.5, opacity: 0 }} animate={{ scale: 1, opacity: 1 }} transition={{ type: 'spring', stiffness: 320, damping: 18, delay: 0.1 }}>
            <Avatar name={user.name} hue={user.hue} src={user.avatarUrl} size="xl" className="shadow-lift ring-4 ring-white dark:ring-ink-950" />
          </motion.div>
          <div className="pb-1">
            {isSelf ? (
              <Link to="/settings">
                <Button variant="outline" icon={<Settings className="size-4" />}>
                  Edit profile
                </Button>
              </Link>
            ) : (
              <UserFollowButton profile={user} />
            )}
          </div>
        </div>

        <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.15 }} className="mt-4">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-2xl font-bold sm:text-3xl">{user.name}</h1>
            {suspended && (
              <Badge tone="red" icon={<Ban className="size-3" />}>
                Suspended
              </Badge>
            )}
          </div>
          <p className="text-ink-500">@{user.handle}</p>
          {user.headline && !suspended && <p className="mt-2 max-w-xl text-[15px] text-ink-700 dark:text-ink-200">{user.headline}</p>}

          {!suspended && user.links.length > 0 && (
            <div className="mt-3 flex flex-wrap gap-2">
              {user.links.map((l, i) => (
                <LinkPill key={l.url + i} link={l} index={i} />
              ))}
            </div>
          )}

          <div className="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
            <Stat value={user.followersCount ?? 0} label={user.followersCount === 1 ? 'follower' : 'followers'} />
            <Stat value={user.followingCount ?? 0} label="following" />
            {user.joinedAt && (
              <span className="inline-flex items-center gap-1.5 text-ink-500">
                <CalendarDays className="size-4" /> Joined {formatDay(user.joinedAt, { month: 'long', year: 'numeric' })}
              </span>
            )}
          </div>
        </motion.div>
      </div>

      {suspended && (
        <motion.div
          initial={{ opacity: 0, y: 8 }}
          animate={{ opacity: 1, y: 0 }}
          className="mt-6 flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"
        >
          <Ban className="mt-0.5 size-4 shrink-0" />
          <div>
            <p className="font-semibold">This account is suspended</p>
            <p className="mt-0.5 opacity-80">This member broke our community guidelines. Their profile is hidden.</p>
          </div>
        </motion.div>
      )}

      {!suspended && (
        <div className="mt-8">
          <h2 className="text-lg font-bold">Products</h2>
          <div className="mt-4 space-y-3">
            {products.isPending ? (
              <div className="space-y-3" aria-busy="true" aria-label="Loading products">
                {Array.from({ length: 2 }, (_, i) => (
                  <Skeleton key={i} className="h-24 w-full rounded-2xl" />
                ))}
              </div>
            ) : products.isError && !products.data ? (
              <ErrorState error={products.error} onRetry={() => void products.refetch()} title="Could not load the products" />
            ) : products.items.length === 0 ? (
              <EmptyState
                icon={<Package className="size-6" />}
                title={isSelf ? 'You haven’t launched yet' : 'No launches yet'}
                body={isSelf ? 'Share what you’re building with the community.' : `${user.name.split(' ')[0]} hasn’t launched a product yet.`}
                action={isSelf ? <Link to="/submit"><Button>Submit a product</Button></Link> : undefined}
              />
            ) : (
              <>
                {products.items.map((p, i) => (
                  <motion.div key={p.id} initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: Math.min(i, 8) * 0.04 }}>
                    <ProductRow product={p} showVote={false} />
                  </motion.div>
                ))}
                <LoadMore hasNextPage={products.hasNextPage} loading={products.isFetchingNextPage} failed={products.isFetchNextPageError} onClick={() => void products.fetchNextPage()} />
              </>
            )}
          </div>
        </div>
      )}
    </div>
  )
}

function Stat({ value, label }: { value: number; label: string }) {
  return (
    <span className="text-ink-500">
      <AnimatedNumber value={value} className="font-bold text-ink-900 dark:text-white" /> {label}
    </span>
  )
}
