import { motion } from 'motion/react'
import { Fingerprint, Flag, Inbox, Package, ScrollText, ShieldCheck, Users } from 'lucide-react'
import { useEffect, useRef } from 'react'
import { Link, Outlet, useLocation } from 'react-router'
import { useReportQueue } from '@/api/hooks/useStaffReports'
import { useStaffQueue } from '@/api/hooks/useStaffReview'
import { useSession } from '@/session/SessionProvider'
import { Avatar, cn } from '@/components/ui'

interface StaffTab {
  to: string
  label: string
  icon: typeof Inbox
  count?: number
  /** The list has more than the count shows. */
  more?: boolean
  match: (path: string) => boolean
}

export default function StaffLayout() {
  const { user, isAdmin } = useSession()
  const { pathname } = useLocation()
  // The same queries the pages use, so the badges and the lists share one cache entry.
  const queue = useStaffQueue()
  const reports = useReportQueue()
  const pending = queue.isSuccess ? queue.items.length : undefined
  const openReports = reports.data?.meta.total_targets

  const tabs: StaffTab[] = [
    { to: '/staff', label: 'Review queue', icon: Inbox, count: pending, more: queue.hasNextPage, match: (p) => p === '/staff' || p === '/staff/' || p.startsWith('/staff/review') },
    { to: '/staff/reports', label: 'Reports', icon: Flag, count: openReports, match: (p) => p.startsWith('/staff/reports') },
    { to: '/staff/products', label: 'Products', icon: Package, match: (p) => p.startsWith('/staff/products') },
    { to: '/staff/risk', label: 'Risk', icon: Fingerprint, match: (p) => p.startsWith('/staff/risk') },
    ...(isAdmin ? [{ to: '/staff/members', label: 'Members', icon: Users, match: (p: string) => p.startsWith('/staff/members') }] : []),
    ...(isAdmin ? [{ to: '/staff/audit', label: 'Audit log', icon: ScrollText, match: (p: string) => p.startsWith('/staff/audit') }] : []),
  ]

  const navRef = useRef<HTMLDivElement>(null)
  useEffect(() => {
    navRef.current?.querySelector<HTMLElement>('[aria-current="page"]')?.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' })
  }, [pathname])

  return (
    <div className="pb-10">
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="flex size-11 items-center justify-center rounded-2xl bg-gradient-to-br from-violet-500 to-indigo-600 text-white shadow-[0_8px_24px_-8px_rgb(124_58_237/0.7)]">
            <ShieldCheck className="size-5.5" />
          </span>
          <div>
            <div className="flex items-center gap-2">
              <h1 className="text-xl font-bold sm:text-2xl">Staff tools</h1>
              <span
                className={cn(
                  'rounded-full px-2 py-0.5 text-[11px] font-bold tracking-wide uppercase',
                  isAdmin ? 'bg-violet-600 text-white' : 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
                )}
              >
                {isAdmin ? 'Admin' : 'Reviewer'}
              </span>
            </div>
            <p className="text-xs text-ink-500 sm:text-sm">Every action you take here is recorded in the audit log.</p>
          </div>
        </div>
        {user && (
          <div className="hidden items-center gap-2 rounded-full border border-ink-200/70 bg-white py-1 pr-3 pl-1 text-sm sm:flex dark:border-white/10 dark:bg-ink-900">
            <Avatar name={user.name} hue={user.hue} size="xs" />
            <span className="font-medium">{user.name}</span>
          </div>
        )}
      </div>

      <div className="sticky top-16 z-20 -mx-4 mb-6 border-b border-ink-200/60 bg-ink-50/85 px-4 py-2 backdrop-blur-xl dark:border-white/5 dark:bg-ink-950/85">
        <nav ref={navRef} aria-label="Staff sections" className="no-scrollbar flex gap-1 overflow-x-auto">
          {tabs.map((t) => {
            const active = t.match(pathname)
            const Icon = t.icon
            return (
              <Link
                key={t.to}
                to={t.to}
                aria-current={active ? 'page' : undefined}
                className={cn(
                  'relative flex shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-semibold whitespace-nowrap transition-colors',
                  active ? 'text-violet-700 dark:text-violet-200' : 'text-ink-500 hover:text-ink-900 dark:text-ink-400 dark:hover:text-white',
                )}
              >
                {active && (
                  <motion.span
                    layoutId="staff-tab-pill"
                    transition={{ type: 'spring', stiffness: 480, damping: 36 }}
                    className="absolute inset-0 rounded-xl bg-white shadow-soft ring-1 ring-violet-200/80 dark:bg-violet-500/15 dark:ring-violet-500/30"
                  />
                )}
                <Icon className="relative size-4" />
                <span className="relative">{t.label}</span>
                {t.count !== undefined && t.count > 0 && (
                  <motion.span
                    key={t.count}
                    initial={{ scale: 0.6 }}
                    animate={{ scale: 1 }}
                    transition={{ type: 'spring', stiffness: 600, damping: 18 }}
                    className={cn(
                      'relative rounded-full px-1.5 text-[11px] font-bold tabular-nums',
                      active ? 'bg-violet-600 text-white' : 'bg-ink-200 text-ink-700 dark:bg-white/10 dark:text-ink-200',
                    )}
                  >
                    {t.count}
                    {t.more ? '+' : ''}
                  </motion.span>
                )}
              </Link>
            )
          })}
        </nav>
      </div>

      <Outlet />
    </div>
  )
}
