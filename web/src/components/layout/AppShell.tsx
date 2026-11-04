import { AnimatePresence, motion } from 'motion/react'
import { Compass, Flame, Home, LayoutDashboard, LogOut, Moon, Plus, Rss, Search, Settings, Shield, Sun, Trophy, User as UserIcon } from 'lucide-react'
import { Suspense, useEffect, type ReactNode } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router'
import { useSession } from '@/session/SessionProvider'
import { useTheme } from '@/theme/ThemeProvider'
import { useCurrentUser, useIsStaff } from '@/session/SessionProvider'
import { Avatar, Button, MenuItem, Popover, Skeleton, cn } from '@/components/ui'
import { AuthGateProvider } from './AuthGate'
import { Logo } from './Logo'
import { NotificationBell } from './NotificationBell'

export const nav = [
  { to: '/', label: 'Today', icon: Home, end: true },
  { to: '/leaderboard', label: 'Leaderboards', icon: Trophy },
  { to: '/topics', label: 'Topics', icon: Compass },
  { to: '/feed', label: 'Following', icon: Rss, member: true },
]

export function UserMenu() {
  const user = useCurrentUser()
  const isStaff = useIsStaff()
  const { signOut } = useSession()
  const navigate = useNavigate()
  if (!user) return null
  const go = (to: string, close: () => void) => {
    close()
    navigate(to)
  }
  return (
    <Popover
      className="w-60"
      trigger={() => (
        <button aria-label="Account menu" className="cursor-pointer rounded-full transition hover:scale-105">
          <Avatar name={user.name} hue={user.hue} src={user.avatarUrl} size="sm" />
        </button>
      )}
    >
      {(close) => (
        <div>
          <div className="px-3 py-2">
            <p className="text-sm font-bold">{user.name}</p>
            <p className="text-xs text-ink-500">@{user.handle}</p>
          </div>
          <div className="my-1 h-px bg-ink-100 dark:bg-white/5" />
          <MenuItem icon={<UserIcon />} onClick={() => go(`/u/${user.handle}`, close)}>Profile</MenuItem>
          <MenuItem icon={<LayoutDashboard />} onClick={() => go('/my/products', close)}>My products</MenuItem>
          {isStaff && <MenuItem icon={<Shield />} onClick={() => go('/staff', close)}>Staff tools</MenuItem>}
          <MenuItem icon={<Settings />} onClick={() => go('/settings', close)}>Settings</MenuItem>
          <div className="my-1 h-px bg-ink-100 dark:bg-white/5" />
          <MenuItem icon={<LogOut />} danger onClick={() => { close(); void signOut().then(() => navigate('/')) }}>Sign out</MenuItem>
        </div>
      )}
    </Popover>
  )
}

export function ThemeToggle() {
  const { theme, toggleTheme } = useTheme()
  const dark = theme === 'dark'
  return (
    <button onClick={toggleTheme} aria-label="Toggle theme" className="flex size-10 cursor-pointer items-center justify-center rounded-xl transition hover:bg-ink-100 dark:hover:bg-white/5">
      <AnimatePresence mode="wait" initial={false}>
        <motion.span key={dark ? 'm' : 's'} initial={{ rotate: -90, opacity: 0 }} animate={{ rotate: 0, opacity: 1 }} exit={{ rotate: 90, opacity: 0 }} transition={{ duration: 0.2 }}>
          {dark ? <Moon className="size-5" /> : <Sun className="size-5" />}
        </motion.span>
      </AnimatePresence>
    </button>
  )
}

export function Header() {
  const user = useCurrentUser()
  const isStaff = useIsStaff()
  const navigate = useNavigate()
  return (
    <header className="sticky top-0 z-30 border-b border-ink-200/60 bg-white/75 backdrop-blur-xl dark:border-white/5 dark:bg-ink-950/75">
      <div className="mx-auto flex h-16 max-w-6xl items-center gap-3 px-4">
        <Logo />
        <nav className="ml-4 hidden items-center gap-1 md:flex">
          {nav
            .filter((n) => !n.member || user)
            .map((n) => (
              <NavLink key={n.to} to={n.to} end={n.end} className={({ isActive }) => cn('relative rounded-lg px-3 py-2 text-sm font-semibold transition-colors', isActive ? 'text-ink-900 dark:text-white' : 'text-ink-500 hover:text-ink-900 dark:hover:text-white')}>
                {({ isActive }) => (
                  <>
                    {n.label}
                    {isActive && <motion.span layoutId="nav-underline" className="absolute inset-x-3 -bottom-[13px] h-0.5 rounded-full bg-brand-500" />}
                  </>
                )}
              </NavLink>
            ))}
          {isStaff && (
            <NavLink to="/staff" className={({ isActive }) => cn('flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold', isActive ? 'text-violet-600' : 'text-ink-500 hover:text-violet-600')}>
              <Shield className="size-4" /> Staff
            </NavLink>
          )}
        </nav>
        <div className="ml-auto flex items-center gap-1">
          <button onClick={() => navigate('/search')} className="hidden h-10 w-56 cursor-pointer items-center gap-2 rounded-xl border border-ink-200 bg-ink-50 px-3 text-sm text-ink-400 transition hover:border-ink-300 lg:flex dark:border-white/10 dark:bg-white/5">
            <Search className="size-4" /> Search products…
            <kbd className="ml-auto rounded bg-white px-1.5 text-[11px] font-semibold text-ink-400 shadow-sm dark:bg-ink-800">/</kbd>
          </button>
          <button onClick={() => navigate('/search')} aria-label="Search" className="flex size-10 cursor-pointer items-center justify-center rounded-xl hover:bg-ink-100 lg:hidden dark:hover:bg-white/5">
            <Search className="size-5" />
          </button>
          <ThemeToggle />
          {user ? (
            <>
              <NotificationBell />
              <Button size="sm" className="mx-1 hidden sm:inline-flex" icon={<Plus className="size-4" />} onClick={() => navigate('/submit')}>
                Submit
              </Button>
              <UserMenu />
            </>
          ) : (
            <>
              <Link to="/signin" className="hidden px-3 text-sm font-semibold text-ink-600 hover:text-ink-900 sm:block dark:text-ink-300 dark:hover:text-white">Sign in</Link>
              <Button size="sm" onClick={() => navigate('/signup')}>Join free</Button>
            </>
          )}
        </div>
      </div>
    </header>
  )
}

export function MobileNav() {
  const user = useCurrentUser()
  const items = [
    { to: '/', label: 'Today', icon: Flame, end: true },
    { to: '/leaderboard', label: 'Top', icon: Trophy },
    ...(user ? [{ to: '/submit', label: 'Submit', icon: Plus, accent: true }] : []),
    { to: '/search', label: 'Search', icon: Search },
    user ? { to: '/feed', label: 'Following', icon: Rss } : { to: '/topics', label: 'Topics', icon: Compass },
  ]
  return (
    <nav className="fixed inset-x-0 bottom-0 z-30 border-t border-ink-200/70 bg-white/85 pb-[env(safe-area-inset-bottom)] backdrop-blur-xl md:hidden dark:border-white/5 dark:bg-ink-950/85">
      <div className="flex h-16 items-center justify-around">
        {items.map((it) => (
          <NavLink key={it.to} to={it.to} end={'end' in it ? it.end : false} className="flex flex-1 flex-col items-center gap-0.5">
            {({ isActive }) =>
              'accent' in it ? (
                <motion.span whileTap={{ scale: 0.9 }} className="-mt-6 flex size-12 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-400 to-pink-500 text-white shadow-glow">
                  <it.icon className="size-6" />
                </motion.span>
              ) : (
                <>
                  <motion.span animate={{ scale: isActive ? 1.1 : 1, y: isActive ? -1 : 0 }} className={isActive ? 'text-brand-500' : 'text-ink-400'}>
                    <it.icon className="size-5.5" />
                  </motion.span>
                  <span className={cn('text-[10px] font-semibold', isActive ? 'text-ink-900 dark:text-white' : 'text-ink-400')}>{it.label}</span>
                </>
              )
            }
          </NavLink>
        ))}
      </div>
    </nav>
  )
}

export function PageFallback() {
  return (
    <div className="space-y-4">
      <Skeleton className="h-10 w-64" />
      {[0, 1, 2, 3].map((i) => (
        <Skeleton key={i} className="h-24 w-full" />
      ))}
    </div>
  )
}

/** App layout: sticky top header + mobile bottom nav. */
function AppLayout({ children }: { children: ReactNode }) {
  return (
    <div className="min-h-dvh pb-24 md:pb-10">
      <Header />
      <main className="mx-auto max-w-6xl px-4 pt-6 sm:pt-8">{children}</main>
      <MobileNav />
    </div>
  )
}

/** Animated route outlet. */
// The submit wizard moves from /submit to /submit/:id on its first save and must keep what is on screen.
const pageKey = (path: string) => (path === '/submit' || path.startsWith('/submit/') ? '/submit' : path)

function PageOutlet() {
  const location = useLocation()
  return (
    <motion.div key={pageKey(location.pathname)} initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25, ease: 'easeOut' }}>
      <Suspense fallback={<PageFallback />}>
        <Outlet />
      </Suspense>
    </motion.div>
  )
}

export function AppShell() {
  const location = useLocation()
  const navigate = useNavigate()
  useEffect(() => {
    window.scrollTo({ top: 0 })
  }, [location.pathname])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const el = e.target as HTMLElement
      if (e.key === '/' && !['INPUT', 'TEXTAREA'].includes(el.tagName)) {
        e.preventDefault()
        navigate('/search')
      }
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [navigate])

  return (
    <AuthGateProvider>
      <AppLayout>
        <PageOutlet />
      </AppLayout>
    </AuthGateProvider>
  )
}

/** Minimal chrome for sign-in / sign-up screens. */
export function AuthShell() {
  return (
    <AuthGateProvider>
      <div className="relative min-h-dvh overflow-hidden">
        <div className="pointer-events-none absolute -top-40 left-1/2 h-[480px] w-[900px] -translate-x-1/2 rounded-full bg-gradient-to-r from-brand-300/40 via-pink-300/40 to-violet-300/40 blur-3xl dark:from-brand-500/15 dark:via-pink-500/15 dark:to-violet-500/15" />
        <div className="relative mx-auto flex min-h-dvh max-w-md flex-col px-5 py-8">
          <Logo />
          <div className="flex flex-1 flex-col justify-center py-10">
            <Suspense fallback={<Skeleton className="h-96 w-full" />}>
              <Outlet />
            </Suspense>
          </div>
        </div>
        </div>
    </AuthGateProvider>
  )
}
