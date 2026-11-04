import { AnimatePresence, motion } from 'motion/react'
import { Bell, CheckCheck } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router'
import { useMarkRead } from '@/api/hooks/useMarkRead'
import { useNotifications } from '@/api/hooks/useNotifications'
import { unreadLabel, useUnreadCount } from '@/api/hooks/useUnreadCount'
import { timeAgo } from '@/lib/date'
import { notificationText } from '@/lib/notificationText'
import { useUserChannel } from '@/realtime/useLive'
import { useSession } from '@/session/SessionProvider'
import { Popover, Skeleton, cn } from '@/components/ui'
import { NotificationIcon } from './NotificationIcon'

const rowClass = 'flex gap-3 rounded-xl px-3 py-2.5 transition hover:bg-ink-50 dark:hover:bg-white/5'

function BellList({ close }: { close: () => void }) {
  const list = useNotifications({ perPage: 5 })
  const mark = useMarkRead()
  const open = (id: string, read: boolean) => {
    close()
    if (!read) mark.mutate({ ids: [id] })
  }

  if (list.isPending) {
    return (
      <div className="space-y-2 px-3 py-3" aria-busy="true">
        <Skeleton className="h-10 w-full" />
        <Skeleton className="h-10 w-full" />
        <Skeleton className="h-10 w-full" />
      </div>
    )
  }
  if (list.isError && list.items.length === 0) {
    return (
      <p className="px-3 py-8 text-center text-sm text-ink-500">
        Could not load notifications.{' '}
        <button onClick={() => void list.refetch()} className="cursor-pointer font-semibold text-brand-600 hover:underline">
          Try again
        </button>
      </p>
    )
  }
  if (list.items.length === 0) return <p className="px-3 py-10 text-center text-sm text-ink-500">You’re all caught up ✨</p>

  return (
    <>
      {list.items.map((n) => {
        const { title, body } = notificationText(n.messageKey, n.params)
        const inner = (
          <>
            <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-ink-100 dark:bg-white/5">
              <NotificationIcon type={n.type} />
            </span>
            <span className="min-w-0 flex-1">
              <span className="block text-sm font-semibold">{title}</span>
              {body && <span className="block truncate text-xs text-ink-500">{body}</span>}
              <span className="mt-0.5 block text-[11px] text-ink-400">{timeAgo(n.createdAt)}</span>
            </span>
            {!n.read && <span className="mt-2 size-2 shrink-0 rounded-full bg-brand-500" role="img" aria-label="Unread" />}
          </>
        )
        return n.href ? (
          <Link key={n.id} to={n.href} onClick={() => open(n.id, n.read)} className={rowClass}>
            {inner}
          </Link>
        ) : (
          <button key={n.id} onClick={() => open(n.id, n.read)} className={cn(rowClass, 'w-full cursor-pointer text-left')}>
            {inner}
          </button>
        )
      })}
    </>
  )
}

/** Bell with the unread count (focus and 2-minute re-reads, live frames when Reverb is on). Shakes when the number goes up. */
export function NotificationBell() {
  const { user, isVerified } = useSession()
  const verified = !!user && isVerified
  const count = useUnreadCount(true, verified)
  const mark = useMarkRead()
  // Only a verified, active member joins the private channel; sign-out unmounts the bell, which leaves it.
  useUserChannel(user && verified ? user.id : null)

  const unread = count.data?.unread_count ?? 0
  const label = unreadLabel(count.data)
  const prev = useRef<number | null>(null)
  const [shake, setShake] = useState(0)
  const [announcement, setAnnouncement] = useState('')

  useEffect(() => {
    if (!count.data) return
    if (prev.current !== null && unread !== prev.current) {
      // Said once per change, politely; the first load is not announced.
      setAnnouncement(unread === 0 ? 'No unread notifications' : `${label} unread ${unread === 1 ? 'notification' : 'notifications'}`)
      if (unread > prev.current) setShake((n) => n + 1)
    }
    prev.current = unread
  }, [count.data, unread, label])

  return (
    <>
      <span role="status" aria-live="polite" className="sr-only">
        {announcement}
      </span>
    <Popover
      label="Notifications"
      className="w-[min(92vw,380px)]"
      trigger={(open) => (
        <button aria-label={`Notifications, ${label} unread`} aria-haspopup="dialog" aria-expanded={open} className={cn('relative flex size-10 cursor-pointer items-center justify-center rounded-xl transition hover:bg-ink-100 dark:hover:bg-white/5', open && 'bg-ink-100 dark:bg-white/5')}>
          <motion.span key={shake} animate={shake ? { rotate: [0, -18, 14, -10, 6, 0] } : undefined} transition={{ duration: 0.6 }}>
            <Bell className="size-5" />
          </motion.span>
          <AnimatePresence>
            {unread > 0 && (
              <motion.span
                key={label}
                initial={{ scale: 0 }}
                animate={{ scale: 1 }}
                exit={{ scale: 0 }}
                transition={{ type: 'spring', stiffness: 600, damping: 18 }}
                aria-hidden="true"
                className="absolute top-1 right-1 flex h-4.5 min-w-4.5 items-center justify-center rounded-full bg-brand-500 px-1 text-[10px] font-bold text-white ring-2 ring-white dark:ring-ink-950"
              >
                {label}
              </motion.span>
            )}
          </AnimatePresence>
        </button>
      )}
    >
      {(close) => (
        <div>
          <div className="flex items-center justify-between px-3 pt-2 pb-1">
            <p className="font-display text-base font-bold">Notifications</p>
            {unread > 0 && (
              <button
                onClick={() => mark.mutate({ all: true })}
                className="flex cursor-pointer items-center gap-1 text-xs font-semibold text-brand-600 hover:underline"
              >
                <CheckCheck className="size-3.5" /> Mark all read
              </button>
            )}
          </div>
          <div className="max-h-96 overflow-y-auto">
            <BellList close={close} />
          </div>
          <Link to="/notifications" onClick={close} className="mt-1 block rounded-xl py-2 text-center text-sm font-semibold text-ink-600 hover:bg-ink-50 dark:text-ink-300 dark:hover:bg-white/5">
            See all
          </Link>
        </div>
      )}
    </Popover>
    </>
  )
}
