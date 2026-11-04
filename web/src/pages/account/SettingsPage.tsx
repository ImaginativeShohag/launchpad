import { AnimatePresence, motion } from 'motion/react'
import { Bell, KeyRound, UserRound } from 'lucide-react'
import { Link, useSearchParams } from 'react-router'
import { useCurrentUser } from '@/session/SessionProvider'
import { Avatar, Tabs, type TabItem } from '@/components/ui'
import { AccountTab } from './components/AccountTab'
import { EmailTab } from './components/EmailTab'
import { ProfileTab } from './components/ProfileTab'
import { SavedPill, useSavedFlash } from './components/SettingsCard'

type Tab = 'profile' | 'account' | 'email'

const tabs: TabItem<Tab>[] = [
  { value: 'profile', label: <span className="flex items-center gap-1.5"><UserRound className="size-4" /> Profile</span> },
  { value: 'account', label: <span className="flex items-center gap-1.5"><KeyRound className="size-4" /> Account</span> },
  { value: 'email', label: <span className="flex items-center gap-1.5"><Bell className="size-4" /> Email notifications</span> },
]

export default function SettingsPage() {
  const user = useCurrentUser()
  const [params, setParams] = useSearchParams()
  const { savedKey, flash } = useSavedFlash()
  const raw = params.get('tab')
  const tab: Tab = raw === 'account' || raw === 'email' ? raw : 'profile'

  if (!user) return null

  return (
    <div className="pb-6">
      <motion.header initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} className="mb-6 flex items-center gap-4">
        <Avatar name={user.name} hue={user.hue} src={user.avatarUrl} size="lg" className="hidden sm:inline-flex" />
        <div className="min-w-0">
          <h1 className="text-3xl font-extrabold">Settings</h1>
          <p className="text-sm text-ink-500">
            Manage your profile, sign-in, and emails ·{' '}
            <Link to={`/u/${user.handle}`} className="font-semibold text-brand-600 hover:underline dark:text-brand-400">
              View public profile
            </Link>
          </p>
        </div>
      </motion.header>

      <Tabs items={tabs} value={tab} onChange={(v) => setParams(v === 'profile' ? {} : { tab: v }, { replace: true })} className="mb-6" />

      <AnimatePresence mode="wait" initial={false}>
        <motion.div key={tab} initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -6 }} transition={{ duration: 0.2 }}>
          {tab === 'profile' && <ProfileTab user={user} onSaved={flash} />}
          {tab === 'account' && <AccountTab user={user} />}
          {tab === 'email' && <EmailTab onSaved={flash} />}
        </motion.div>
      </AnimatePresence>

      <SavedPill trigger={savedKey} />
    </div>
  )
}
