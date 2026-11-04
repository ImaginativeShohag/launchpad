import { AnimatePresence, motion } from 'motion/react'
import { BellOff, CheckCheck, Inbox } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Link } from 'react-router'
import { useMarkRead } from '@/api/hooks/useMarkRead'
import { MAX_NOTIFICATION_PAGES, useNotifications } from '@/api/hooks/useNotifications'
import { unreadLabel, useUnreadCount } from '@/api/hooks/useUnreadCount'
import type { AppNotification } from '@/data/types'
import { NotificationIcon } from '@/components/layout/NotificationIcon'
import { timeAgo } from '@/lib/date'
import { notificationText } from '@/lib/notificationText'
import { Button, EmptyState, ErrorState, LoadMore, Skeleton, Tabs, cn } from '@/components/ui'

type Filter = 'all' | 'unread'

function isToday(iso: string) {
  const d = new Date(iso)
  const n = new Date()
  return d.getFullYear() === n.getFullYear() && d.getMonth() === n.getMonth() && d.getDate() === n.getDate()
}

const itemClass = (read: boolean) =>
  cn(
    'group flex w-full gap-3.5 rounded-2xl p-3.5 text-left transition-colors sm:p-4',
    read ? 'hover:bg-ink-50 dark:hover:bg-white/5' : 'bg-brand-50/60 hover:bg-brand-50 dark:bg-brand-500/8 dark:hover:bg-brand-500/12',
  )

function NotificationItem({ n, index, onOpen }: { n: AppNotification; index: number; onOpen: () => void }) {
  const { title, body } = notificationText(n.messageKey, n.params)
  const inner = (
    <>
      <motion.span
        whileHover={{ rotate: -10, scale: 1.08 }}
        className="mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-full bg-white shadow-soft ring-1 ring-ink-200/60 dark:bg-ink-800 dark:ring-white/10 [&>svg]:size-5"
      >
        <NotificationIcon type={n.type} />
      </motion.span>
      <span className="min-w-0 flex-1">
        <span className="flex items-start justify-between gap-3">
          <span className={cn('text-sm', n.read ? 'font-semibold text-ink-700 dark:text-ink-200' : 'font-bold')}>{title}</span>
          <span className="shrink-0 text-xs text-ink-400">{timeAgo(n.createdAt)}</span>
        </span>
        {body && <span className="mt-0.5 block text-sm text-ink-500 dark:text-ink-400">{body}</span>}
      </span>
      <AnimatePresence>
        {!n.read && (
          <motion.span
            initial={{ scale: 0 }}
            animate={{ scale: 1 }}
            exit={{ scale: 0 }}
            transition={{ type: 'spring', stiffness: 600, damping: 18 }}
            className="mt-2 size-2.5 shrink-0 rounded-full bg-brand-500 shadow-[0_0_0_4px_rgb(255_81_18/0.15)]"
            role="img"
            aria-label="Unread"
          />
        )}
      </AnimatePresence>
    </>
  )
  return (
    <motion.li
      layout
      initial={{ opacity: 0, y: 12 }}
      animate={{ opacity: 1, y: 0, transition: { delay: Math.min(index, 10) * 0.04, type: 'spring', stiffness: 420, damping: 32 } }}
      exit={{ opacity: 0, x: 24, transition: { duration: 0.18 } }}
    >
      {n.href ? (
        <Link to={n.href} onClick={onOpen} className={itemClass(n.read)}>
          {inner}
        </Link>
      ) : (
        <button onClick={onOpen} className={cn(itemClass(n.read), 'cursor-pointer')}>
          {inner}
        </button>
      )}
    </motion.li>
  )
}

function Group({ title, items, offset, onOpen }: { title: string; items: AppNotification[]; offset: number; onOpen: (n: AppNotification) => void }) {
  if (!items.length) return null
  return (
    <motion.section layout className="surface p-2 sm:p-3">
      <h2 className="px-2 pt-1.5 pb-2 text-xs font-bold tracking-wider text-ink-400 uppercase">{title}</h2>
      <ul className="space-y-1">
        <AnimatePresence initial={false} mode="popLayout">
          {items.map((n, i) => (
            <NotificationItem key={n.id} n={n} index={offset + i} onOpen={() => onOpen(n)} />
          ))}
        </AnimatePresence>
      </ul>
    </motion.section>
  )
}

export default function NotificationsPage() {
  const [filter, setFilter] = useState<Filter>('all')
  const list = useNotifications({ unread: filter === 'unread' })
  const count = useUnreadCount()
  const mark = useMarkRead()

  const unreadCount = count.data?.unread_count ?? 0
  const unreadText = unreadLabel(count.data)
  const items = useMemo(() => {
    const seen = new Set<string>()
    // A row read in this tab leaves the Unread tab at once; the server list catches up on the next read.
    return list.items.filter((n) => !seen.has(n.id) && (seen.add(n.id), filter === 'all' || !n.read))
  }, [list.items, filter])
  const today = items.filter((n) => isToday(n.createdAt))
  const earlier = items.filter((n) => !isToday(n.createdAt))
  const open = (n: AppNotification) => {
    if (!n.read) mark.mutate({ ids: [n.id] })
  }

  return (
    <div className="mx-auto max-w-2xl pb-6">
      <motion.header initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} className="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-3xl font-extrabold">Notifications</h1>
          <p className="text-sm text-ink-500">
            {count.data &&
              (unreadCount ? (
                <>
                  You have <span className="font-semibold text-brand-600 dark:text-brand-400">{unreadText} unread</span>
                </>
              ) : (
                'You’re all caught up'
              ))}
          </p>
        </div>
        <AnimatePresence>
          {unreadCount > 0 && (
            <motion.div initial={{ opacity: 0, scale: 0.9 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.9 }}>
              <Button variant="outline" size="sm" icon={<CheckCheck className="size-4" />} onClick={() => mark.mutate({ all: true })}>
                Mark all read
              </Button>
            </motion.div>
          )}
        </AnimatePresence>
      </motion.header>

      <Tabs
        className="mb-5"
        value={filter}
        onChange={setFilter}
        items={[
          { value: 'all', label: 'All' },
          { value: 'unread', label: 'Unread', count: count.data ? unreadCount : undefined },
        ]}
      />

      {list.isPending ? (
        <div className="space-y-3" aria-busy="true">
          <Skeleton className="h-20 w-full" />
          <Skeleton className="h-20 w-full" />
          <Skeleton className="h-20 w-full" />
        </div>
      ) : list.isError && list.items.length === 0 ? (
        <ErrorState error={list.error} onRetry={() => void list.refetch()} title="Could not load your notifications" />
      ) : (
        <>
          <AnimatePresence mode="wait">
            {items.length === 0 ? (
              <motion.div key={`empty-${filter}`} initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
                {filter === 'unread' ? (
                  <EmptyState icon={<Inbox className="size-6" />} title="Inbox zero" body="No unread notifications. Nice work." action={<Button variant="outline" onClick={() => setFilter('all')}>Show all</Button>} />
                ) : (
                  <EmptyState
                    icon={<BellOff className="size-6" />}
                    title="Nothing here yet"
                    body="When someone comments on your product, a review finishes, or your launch goes live, you’ll see it here."
                    action={<Link to="/"><Button>Explore today’s launches</Button></Link>}
                  />
                )}
              </motion.div>
            ) : (
              <motion.div key={`list-${filter}`} initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="space-y-4">
                <Group title="Today" items={today} offset={0} onOpen={open} />
                <Group title="Earlier" items={earlier} offset={today.length} onOpen={open} />
              </motion.div>
            )}
          </AnimatePresence>
          <LoadMore hasNextPage={!!list.hasNextPage && (list.data?.pages.length ?? 0) < MAX_NOTIFICATION_PAGES} loading={list.isFetchingNextPage} failed={list.isFetchNextPageError} onClick={() => void list.fetchNextPage()} />
        </>
      )}

      <p className="mt-6 text-center text-sm text-ink-500">
        Choose which notifications also reach your inbox in{' '}
        <Link to="/settings?tab=email" className="font-semibold text-brand-600 hover:underline dark:text-brand-400">
          email settings
        </Link>
        .
      </p>
    </div>
  )
}
