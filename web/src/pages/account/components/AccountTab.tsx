import { AnimatePresence, motion } from 'motion/react'
import { BadgeCheck, LogOut, Mail, Trash2, TriangleAlert } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router'
import { useChangePassword, useDeleteAccount, useRequestEmailChange } from '@/api/hooks/useAccount'
import { errorMessage, isApiError } from '@/api/errors'
import type { User } from '@/data/types'
import { useCooldown } from '@/lib/useCooldown'
import { useServerErrors } from '@/lib/useServerErrors'
import { dropSession, useSession } from '@/session/SessionProvider'
import { Badge, Button, Input, Modal, useToast } from '@/components/ui'
import { PasswordInput } from '@/pages/auth/components/PasswordInput'
import { PasswordStrength, isPasswordAcceptable } from '@/pages/auth/components/PasswordStrength'
import { PASSWORD_HELP, validateEmail } from '@/pages/auth/components/authUtils'
import { useVerifyPrompt } from '@/components/layout/AuthGate'
import { SettingsCard } from './SettingsCard'
import { UnverifiedBanner } from './UnverifiedBanner'

function GoogleNote() {
  return (
    <div className="flex items-start gap-3 rounded-xl bg-sky-50 p-4 dark:bg-sky-500/10">
      <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-white font-display text-base font-extrabold text-sky-600 shadow-soft dark:bg-ink-900">G</span>
      <div>
        <p className="text-sm font-semibold">You sign in with Google</p>
        <p className="mt-0.5 text-sm text-ink-600 dark:text-ink-300">
          There’s no Launchpad password on your account. Manage your password and two-step verification in your Google account, or{' '}
          <Link to="/forgot-password" className="font-semibold text-brand-600 hover:underline dark:text-brand-400">set a Launchpad password</Link>.
        </p>
      </div>
    </div>
  )
}

function ChangePasswordForm() {
  const toast = useToast()
  const change = useChangePassword()
  const server = useServerErrors()
  const cooldown = useCooldown()
  const [form, setForm] = useState({ current: '', next: '', confirm: '' })
  const [submitted, setSubmitted] = useState(false)

  const errors = {
    current: !form.current ? 'Enter your current password' : undefined,
    next: !isPasswordAcceptable(form.next) ? PASSWORD_HELP : form.next === form.current ? 'Choose a different password' : undefined,
    confirm: form.confirm !== form.next ? 'Passwords don’t match' : undefined,
  }
  const show = (k: keyof typeof errors, field: string) => server.get(field) ?? (submitted ? errors[k] : undefined)
  const set = (k: keyof typeof form, field: string) => (e: React.ChangeEvent<HTMLInputElement>) => {
    server.clear(field)
    setForm((f) => ({ ...f, [k]: e.target.value }))
  }

  const onSubmit = (e: FormEvent) => {
    e.preventDefault()
    setSubmitted(true)
    if (errors.current || errors.next || errors.confirm) return
    server.reset()
    change.mutate(
      { current_password: form.current, password: form.next },
      {
        onSuccess: () => {
          setSubmitted(false)
          setForm({ current: '', next: '', confirm: '' })
          toast({ tone: 'success', title: 'Password changed', body: 'Other devices were signed out for your safety.' })
        },
        onError: (err) => {
          server.set(err)
          if (isApiError(err) && err.status === 429 && err.retryAfter) cooldown.start(err.retryAfter)
          if (!isApiError(err) || Object.keys(err.fields).length === 0) toast({ tone: 'error', title: 'Could not change your password', body: errorMessage(err) })
        },
      },
    )
  }

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-4">
      <PasswordInput label="Current password" autoComplete="current-password" value={form.current} onChange={set('current', 'current_password')} error={show('current', 'current_password')} />
      <div>
        <PasswordInput label="New password" autoComplete="new-password" value={form.next} onChange={set('next', 'password')} error={show('next', 'password')} />
        <PasswordStrength password={form.next} />
      </div>
      <PasswordInput label="Confirm new password" autoComplete="new-password" value={form.confirm} onChange={set('confirm', 'confirm')} error={show('confirm', 'confirm')} />
      <div className="flex justify-end">
        <Button type="submit" loading={change.isPending} disabled={(!form.current && !form.next) || cooldown.remaining > 0}>
          {cooldown.remaining > 0 ? `Try again in ${cooldown.remaining}s` : 'Update password'}
        </Button>
      </div>
    </form>
  )
}

/** Asks for a new address; the API mails a confirmation link to it and a cancel link to the old one. */
function ChangeEmailForm({ user, onDone }: { user: User; onDone: () => void }) {
  const toast = useToast()
  const request = useRequestEmailChange()
  const server = useServerErrors()
  const cooldown = useCooldown()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [submitted, setSubmitted] = useState(false)
  const [reauth, setReauth] = useState(false)
  const promptVerify = useVerifyPrompt()

  const errors = {
    email: validateEmail(email),
    password: user.hasPassword && !password ? 'Enter your password to confirm' : undefined,
  }

  const onSubmit = (e: FormEvent) => {
    e.preventDefault()
    setSubmitted(true)
    if (errors.email || errors.password) return
    server.reset()
    setReauth(false)
    request.mutate(user.hasPassword ? { email: email.trim(), current_password: password } : { email: email.trim() }, {
      onSuccess: () => {
        toast({ tone: 'success', title: 'Check your new inbox', body: 'We sent a confirmation link to the new address.' })
        onDone()
      },
      onError: (err) => {
        server.set(err)
        if (isApiError(err) && err.status === 429 && err.retryAfter) cooldown.start(err.retryAfter)
        if (isApiError(err) && err.code === 'email_unverified') promptVerify()
        else if (isApiError(err) && err.code === 'reauth_required') setReauth(true)
        else if (!isApiError(err) || Object.keys(err.fields).length === 0) toast({ tone: 'error', title: 'Could not change your email', body: errorMessage(err) })
      },
    })
  }

  return (
    <form onSubmit={onSubmit} noValidate className="mt-4 space-y-4">
      <Input label="New email address" type="email" autoComplete="email" inputMode="email" value={email} onChange={(e) => { setEmail(e.target.value); server.clear('email') }} error={server.get('email') ?? (submitted ? errors.email : undefined)} />
      {user.hasPassword && (
        <PasswordInput label="Current password" autoComplete="current-password" value={password} onChange={(e) => { setPassword(e.target.value); server.clear('current_password') }} error={server.get('current_password') ?? (submitted ? errors.password : undefined)} />
      )}
      {reauth && <p role="alert" className="text-sm font-medium text-red-500">For your security, sign out and sign in again, then retry within a few minutes.</p>}
      <div className="flex justify-end gap-2">
        <Button type="button" variant="ghost" onClick={onDone}>Cancel</Button>
        <Button type="submit" loading={request.isPending} disabled={cooldown.remaining > 0}>
          {cooldown.remaining > 0 ? `Try again in ${cooldown.remaining}s` : 'Send confirmation'}
        </Button>
      </div>
    </form>
  )
}

function DeleteAccountModal({ user, open, onClose }: { user: User; open: boolean; onClose: () => void }) {
  const navigate = useNavigate()
  const toast = useToast()
  const session = useSession()
  const del = useDeleteAccount()
  const qc = useQueryClient()
  const server = useServerErrors()
  const [password, setPassword] = useState('')
  const [problem, setProblem] = useState<{ code: string; message: string } | null>(null)

  const close = () => {
    setPassword('')
    setProblem(null)
    server.reset()
    onClose()
  }

  const onConfirm = (e: FormEvent) => {
    e.preventDefault()
    setProblem(null)
    server.reset()
    del.mutate(user.hasPassword ? { current_password: password } : {}, {
      onSuccess: () => {
        // Leave the members-only page first, then drop the session, so the route guard never redirects to sign-in on the way out.
        navigate('/', { replace: true })
        toast({ tone: 'info', title: 'Your account was deleted' })
        void dropSession(qc)
      },
      onError: (err) => {
        server.set(err)
        // reauth_required, has_active_product, last_admin: the API's own wording explains what to do.
        if (isApiError(err) && Object.keys(err.fields).length === 0) setProblem({ code: err.code, message: errorMessage(err) })
        else if (!isApiError(err)) setProblem({ code: 'unknown', message: errorMessage(err) })
      },
    })
  }

  return (
    <Modal
      open={open}
      onClose={close}
      size="sm"
      title="Delete your account?"
      description="This removes your profile, votes and comments’ author name, and signs you out everywhere. It can’t be undone."
      footer={
        <>
          <Button variant="ghost" onClick={close}>Keep my account</Button>
          <Button type="submit" form="delete-account-form" variant="danger" icon={<Trash2 className="size-4" />} loading={del.isPending} disabled={user.hasPassword && !password}>
            Delete account
          </Button>
        </>
      }
    >
      <form id="delete-account-form" onSubmit={onConfirm} noValidate className="space-y-3">
        {user.hasPassword && (
          <PasswordInput label="Confirm with your password" autoComplete="current-password" value={password} onChange={(e) => { setPassword(e.target.value); server.clear('current_password') }} error={server.get('current_password')} />
        )}
        {problem && (
          <div role="alert" className="rounded-xl bg-red-50 p-3 text-sm font-medium text-red-600 dark:bg-red-500/10 dark:text-red-400">
            {problem.message}
            {problem.code === 'reauth_required' && (
              <Button type="button" variant="outline" size="sm" className="mt-2" onClick={() => void session.signOut().then(() => navigate('/signin?next=%2Fsettings%3Ftab%3Daccount'))}>
                Sign in again
              </Button>
            )}
          </div>
        )}
      </form>
    </Modal>
  )
}

export function AccountTab({ user }: { user: User }) {
  const { signOut } = useSession()
  const navigate = useNavigate()
  const [confirmOut, setConfirmOut] = useState(false)
  const [changingEmail, setChangingEmail] = useState(false)
  const [deleting, setDeleting] = useState(false)

  return (
    <div className="mx-auto max-w-2xl space-y-5">
      {!user.emailVerified && <UnverifiedBanner email={user.email} />}
      <SettingsCard title="Email address" description="Where we send account and product emails.">
        <div className="flex flex-wrap items-center gap-3 rounded-xl bg-ink-50 p-3.5 dark:bg-white/5">
          <Mail className="size-5 text-ink-400" />
          <span className="min-w-0 flex-1 truncate text-sm font-semibold">{user.email}</span>
          {user.emailVerified ? (
            <Badge tone="green" icon={<BadgeCheck className="size-3.5" />}>Verified</Badge>
          ) : (
            <button onClick={() => navigate('/verify-email')} className="cursor-pointer">
              <Badge tone="amber" icon={<TriangleAlert className="size-3.5" />}>Verify now</Badge>
            </button>
          )}
        </div>
        {user.pendingEmail && (
          <p className="mt-3 flex items-start gap-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
            <TriangleAlert className="mt-0.5 size-4 shrink-0" />
            <span>
              Waiting for you to confirm <strong className="font-semibold">{user.pendingEmail}</strong>. Open the link we sent there; until then your address stays the same.
            </span>
          </p>
        )}
        {changingEmail ? (
          <ChangeEmailForm user={user} onDone={() => setChangingEmail(false)} />
        ) : (
          <div className="mt-4 flex justify-end">
            <Button variant="outline" disabled={!user.emailVerified} onClick={() => setChangingEmail(true)}>Change email</Button>
          </div>
        )}
      </SettingsCard>

      <SettingsCard title="Password" description={user.hasPassword ? 'Use a strong password you don’t use anywhere else.' : undefined}>
        {user.hasPassword ? <ChangePasswordForm /> : <GoogleNote />}
      </SettingsCard>

      <SettingsCard title="Danger zone" tone="danger" description="Sign out of Launchpad on this device, or delete your account.">
        <AnimatePresence mode="wait" initial={false}>
          {!confirmOut ? (
            <motion.div key="a" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="flex flex-wrap gap-2">
              <Button variant="outline" className="text-red-600 dark:text-red-400" icon={<LogOut className="size-4" />} onClick={() => setConfirmOut(true)}>
                Sign out
              </Button>
              <Button variant="outline" className="text-red-600 dark:text-red-400" icon={<Trash2 className="size-4" />} onClick={() => setDeleting(true)}>
                Delete account
              </Button>
            </motion.div>
          ) : (
            <motion.div key="b" initial={{ opacity: 0, x: 10 }} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0 }} className="flex flex-wrap items-center gap-2">
              <span className="mr-1 text-sm font-semibold">Sign out now?</span>
              <Button variant="danger" icon={<LogOut className="size-4" />} onClick={() => void signOut().then(() => navigate('/'))}>
                Yes, sign out
              </Button>
              <Button variant="ghost" onClick={() => setConfirmOut(false)}>Cancel</Button>
            </motion.div>
          )}
        </AnimatePresence>
      </SettingsCard>

      <DeleteAccountModal user={user} open={deleting} onClose={() => setDeleting(false)} />
    </div>
  )
}
