import { motion } from 'motion/react'
import { CalendarClock, CalendarDays, CheckCircle2, ClipboardCheck, Download, KeyRound, MailCheck, MessageCircle, RotateCw, Rocket, ShieldCheck, Trophy } from 'lucide-react'
import type { ReactNode } from 'react'
import type { EmailPref } from '@/data/types'
import { useEmailPrefs, useSetEmailPref } from '@/api/hooks/useEmailPrefs'
import { errorMessage } from '@/api/errors'
import { Button, Skeleton, useToast } from '@/components/ui'
import { PrefRow } from './PrefRow'
import { SettingsCard } from './SettingsCard'

interface PrefDef {
  key: EmailPref
  icon: ReactNode
  title: string
  description: string
}

const productPrefs: PrefDef[] = [
  { key: 'review_outcome', icon: <ClipboardCheck />, title: 'Review outcome', description: 'Approved, rejected, or needs changes.' },
  { key: 'launch_reminder', icon: <CalendarClock />, title: 'Launch reminder', description: 'The day before your product launches.' },
  { key: 'product_live', icon: <Rocket />, title: 'Your product is live', description: 'The moment it hits the front page.' },
  { key: 'new_comment', icon: <MessageCircle />, title: 'New comments', description: 'When someone comments on your product.' },
  { key: 'export_ready', icon: <Download />, title: 'Export ready', description: 'When a data export you asked for can be downloaded.' },
]

const digestPrefs: PrefDef[] = [
  { key: 'daily_top5', icon: <Trophy />, title: 'Daily top 5', description: 'Yesterday’s best launches, every morning.' },
  { key: 'weekly_best', icon: <CalendarDays />, title: 'Weekly best', description: 'The week’s top products, every Monday.' },
]

const groupMotion = (i: number) => ({ initial: { opacity: 0, y: 10 }, animate: { opacity: 1, y: 0 }, transition: { delay: i * 0.07, type: 'spring' as const, stiffness: 380, damping: 32 } })

export function EmailTab({ onSaved }: { onSaved: () => void }) {
  const prefs = useEmailPrefs()
  const setPref = useSetEmailPref()
  const toast = useToast()
  const toggle = (key: EmailPref, enabled: boolean) =>
    setPref.mutate(
      { type: key, enabled },
      {
        onSuccess: onSaved,
        onError: (e) => toast({ tone: 'error', title: 'Could not save that setting', body: errorMessage(e) }),
      },
    )
  // A type the API did not list reads as on, like a missing row on the server.
  const renderRows = (defs: PrefDef[]) => (
    <div className="divide-y divide-ink-100 dark:divide-white/5">
      {defs.map((d) => (
        <PrefRow key={d.key} icon={d.icon} title={d.title} description={d.description} checked={prefs.data?.[d.key] ?? true} onChange={(v) => toggle(d.key, v)} />
      ))}
    </div>
  )

  if (prefs.isPending) {
    return (
      <div className="mx-auto max-w-2xl space-y-5" aria-busy="true">
        <Skeleton className="h-16 w-full" />
        <Skeleton className="h-64 w-full" />
        <Skeleton className="h-32 w-full" />
      </div>
    )
  }
  if (prefs.isError) {
    return (
      <div className="mx-auto flex max-w-2xl flex-col items-center gap-3 rounded-2xl bg-red-50 p-8 text-center dark:bg-red-500/10" role="alert">
        <p className="text-sm font-semibold text-red-600 dark:text-red-400">{errorMessage(prefs.error, 'We couldn’t load your email settings.')}</p>
        <Button variant="outline" icon={<RotateCw className="size-4" />} onClick={() => void prefs.refetch()}>Try again</Button>
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-2xl space-y-5">
      <motion.div {...groupMotion(0)} className="flex items-start gap-3 rounded-2xl bg-emerald-50 p-4 dark:bg-emerald-500/10">
        <ShieldCheck className="mt-0.5 size-5 shrink-0 text-emerald-600" />
        <p className="text-sm text-ink-700 dark:text-ink-200">
          When you turn an email off, we don’t send it. Ever. Changes save instantly.
        </p>
      </motion.div>

      <motion.div {...groupMotion(1)}>
        <SettingsCard title="Account emails" description="Required to keep your account secure.">
          <div className="divide-y divide-ink-100 dark:divide-white/5">
            <PrefRow locked checked icon={<MailCheck />} title="Confirm your email" description="Sent once when you sign up, so only real people vote." />
            <PrefRow locked checked icon={<KeyRound />} title="Password reset" description="Only when you ask for a reset link." />
          </div>
        </SettingsCard>
      </motion.div>

      <motion.div {...groupMotion(2)}>
        <SettingsCard title="Your products" description="Updates about products you’ve submitted.">
          {renderRows(productPrefs)}
        </SettingsCard>
      </motion.div>

      <motion.div {...groupMotion(3)}>
        <SettingsCard title="Digests" description="The best of Launchpad, delivered.">
          {renderRows(digestPrefs)}
        </SettingsCard>
      </motion.div>

      <motion.p {...groupMotion(4)} className="flex items-center justify-center gap-1.5 text-xs text-ink-400">
        <CheckCircle2 className="size-3.5" /> Emails are queued and retried, so nothing goes missing if our provider has an outage.
      </motion.p>
    </div>
  )
}
