import { AnimatePresence, motion } from 'motion/react'
import { AtSign, Camera, RotateCcw, Trash2 } from 'lucide-react'
import { useMemo, useRef, useState, type FormEvent } from 'react'
import { inferLinkKind } from '@/api/mappers'
import { useRemoveAvatar, useUpdateProfile, useUploadAvatar } from '@/api/hooks/useAccount'
import { errorMessage, isApiError } from '@/api/errors'
import type { ProfilePatch } from '@/api/endpoints/me'
import type { User } from '@/data/types'
import { useServerErrors } from '@/lib/useServerErrors'
import { useVerifyPrompt } from '@/components/layout/AuthGate'
import { Avatar, Button, Input, useToast } from '@/components/ui'
import { HANDLE_RE } from '@/pages/auth/components/authUtils'
import { LinksEditor, linkError, toEditable, type EditableLink } from './LinksEditor'
import { ProfilePreview } from './ProfilePreview'
import { SettingsCard } from './SettingsCard'
import { UnverifiedBanner } from './UnverifiedBanner'

const HEADLINE_MAX = 120
const AVATAR_TYPES = ['image/jpeg', 'image/png', 'image/webp']
const AVATAR_MAX = 2 * 1024 * 1024

export function ProfileTab({ user, onSaved }: { user: User; onSaved: () => void }) {
  const toast = useToast()
  const update = useUpdateProfile()
  const upload = useUploadAvatar()
  const remove = useRemoveAvatar()
  const server = useServerErrors()
  const fileInput = useRef<HTMLInputElement>(null)
  const promptVerify = useVerifyPrompt()
  const verified = !!user.emailVerified
  const [progress, setProgress] = useState<number>()
  const unverified = (err: unknown) => isApiError(err) && err.code === 'email_unverified' && (promptVerify(), true)
  const [avatarError, setAvatarError] = useState<string>()

  const initial = useMemo(() => ({ name: user.name, handle: user.handle, headline: user.headline, links: user.links.map(toEditable) }), [user])
  const [draft, setDraft] = useState(initial)
  const [submitted, setSubmitted] = useState(false)

  const clean = (links: EditableLink[]) => links.filter((l) => l.label.trim() || l.url.trim()).map(({ label, url }) => ({ label: label.trim(), url: url.trim() }))
  const linksChanged = JSON.stringify(clean(draft.links)) !== JSON.stringify(clean(initial.links))
  const dirty = draft.name !== initial.name || draft.handle !== initial.handle || draft.headline !== initial.headline || linksChanged

  const errors = {
    name: draft.name.trim().length < 2 ? 'Name must be at least 2 characters' : undefined,
    handle: !HANDLE_RE.test(draft.handle) ? '3–30 lowercase letters, numbers or _' : undefined,
    headline: draft.headline.length > HEADLINE_MAX ? `Keep it under ${HEADLINE_MAX} characters` : undefined,
    links: draft.links.some((l) => linkError(l)),
  }
  const invalid = !!(errors.name || errors.handle || errors.headline || errors.links)

  // The API numbers links by position in what was sent (blank rows are not sent), so map its row back to the editor row.
  const sentRows = draft.links.map((l, i) => (l.label.trim() || l.url.trim() ? i : -1)).filter((i) => i >= 0)
  const linkErrors: Record<number, string> = {}
  for (const [key, messages] of Object.entries(server.fields)) {
    const m = /^links\.(\d+)\./.exec(key)
    if (m && messages[0]) linkErrors[sentRows[Number(m[1])] ?? Number(m[1])] = messages[0]
  }

  const onSubmit = (e: FormEvent) => {
    e.preventDefault()
    setSubmitted(true)
    if (invalid) return
    // Only what changed goes over the wire.
    const patch: ProfilePatch = {}
    if (draft.name !== initial.name) patch.name = draft.name.trim()
    if (draft.handle !== initial.handle) patch.handle = draft.handle
    if (draft.headline !== initial.headline) patch.headline = draft.headline.trim() || null
    if (linksChanged) patch.links = clean(draft.links).map((l) => ({ kind: inferLinkKind(l.url), label: l.label || null, url: l.url }))
    server.reset()
    update.mutate(patch, {
      onSuccess: () => {
        setSubmitted(false)
        onSaved()
      },
      onError: (err) => {
        server.set(err)
        if (unverified(err)) return
        if (!isApiError(err) || Object.keys(err.fields).length === 0) toast({ tone: 'error', title: 'Could not save your profile', body: errorMessage(err) })
      },
    })
  }

  const onFile = (file: File | undefined) => {
    setAvatarError(undefined)
    if (!file) return
    if (!AVATAR_TYPES.includes(file.type)) return setAvatarError('Use a JPEG, PNG or WebP image.')
    if (file.size > AVATAR_MAX) return setAvatarError('The photo must be 2 MB or smaller.')
    setProgress(0)
    upload.mutate({ file, onProgress: setProgress }, {
      onSuccess: onSaved,
      onSettled: () => setProgress(undefined),
      onError: (err) => unverified(err) || setAvatarError(isApiError(err) ? (err.fields.avatar?.[0] ?? errorMessage(err)) : errorMessage(err)),
    })
  }

  const onRemove = () => {
    setAvatarError(undefined)
    remove.mutate(undefined, { onSuccess: onSaved, onError: (err) => unverified(err) || setAvatarError(errorMessage(err)) })
  }

  return (
    <form onSubmit={onSubmit} noValidate className="grid gap-5 lg:grid-cols-[1fr_20rem]">
      {!verified && (
        <div className="lg:col-span-2">
          <UnverifiedBanner email={user.email} />
        </div>
      )}
      <div className="space-y-5">
        <SettingsCard title="Photo" description="A square JPEG, PNG or WebP up to 2 MB.">
          <div className="flex flex-wrap items-center gap-4">
            <Avatar name={user.name} hue={user.hue} src={user.avatarUrl} size="xl" />
            <div className="space-y-2">
              <div className="flex flex-wrap gap-2">
                <input ref={fileInput} type="file" accept={AVATAR_TYPES.join(',')} aria-label="Upload photo" className="hidden" onChange={(e) => { onFile(e.target.files?.[0]); e.target.value = '' }} />
                <Button type="button" variant="outline" icon={<Camera className="size-4" />} loading={upload.isPending} disabled={!verified} onClick={() => fileInput.current?.click()}>
                  {progress !== undefined ? `Uploading ${progress}%` : user.avatarUrl ? 'Change photo' : 'Upload photo'}
                </Button>
                {user.avatarUrl && (
                  <Button type="button" variant="ghost" icon={<Trash2 className="size-4" />} loading={remove.isPending} disabled={!verified} onClick={onRemove}>
                    Remove
                  </Button>
                )}
              </div>
              {avatarError && <p role="alert" className="text-xs font-medium text-red-500">{avatarError}</p>}
            </div>
          </div>
        </SettingsCard>

        <SettingsCard title="About you" description="Shown on your public profile.">
          <div className="space-y-4">
            <Input label="Name" autoComplete="name" value={draft.name} onChange={(e) => { setDraft((d) => ({ ...d, name: e.target.value })); server.clear('name') }} error={server.get('name') ?? errors.name} />
            <Input
              label="Handle"
              leading={<AtSign className="size-4" />}
              value={draft.handle}
              onChange={(e) => { setDraft((d) => ({ ...d, handle: e.target.value.toLowerCase().replace(/\s/g, '') })); server.clear('handle') }}
              error={server.get('handle') ?? errors.handle}
              hint={`launchpad.app/u/${draft.handle || 'handle'}`}
            />
            <Input
              label="Headline"
              placeholder="Indie maker shipping tiny tools"
              value={draft.headline}
              onChange={(e) => { setDraft((d) => ({ ...d, headline: e.target.value })); server.clear('headline') }}
              counter={{ value: draft.headline.length, max: HEADLINE_MAX }}
              error={server.get('headline') ?? errors.headline}
              hint="One line about you."
            />
          </div>
        </SettingsCard>

        <SettingsCard title="Links" description="Your website, socials, or portfolio (https only).">
          <LinksEditor links={draft.links} onChange={(links) => { setDraft((d) => ({ ...d, links })); server.reset() }} showErrors={submitted} serverErrors={linkErrors} />
          {server.get('links') && <p className="mt-2 text-xs font-medium text-red-500">{server.get('links')}</p>}
        </SettingsCard>
      </div>

      <div className="lg:sticky lg:top-24 lg:self-start">
        <p className="mb-2 text-xs font-semibold tracking-wider text-ink-400 uppercase">Live preview</p>
        <ProfilePreview name={draft.name} handle={draft.handle} headline={draft.headline} hue={user.hue} avatarUrl={user.avatarUrl} links={draft.links} />
        <AnimatePresence>
          {dirty && (
            <motion.div
              initial={{ opacity: 0, y: 12 }}
              animate={{ opacity: 1, y: 0 }}
              exit={{ opacity: 0, y: 12 }}
              transition={{ type: 'spring', stiffness: 420, damping: 32 }}
              className="sticky bottom-20 z-10 mt-4 flex gap-2 rounded-2xl bg-white/90 p-2 shadow-lift ring-1 ring-ink-200/70 backdrop-blur md:bottom-4 lg:static lg:bg-transparent lg:p-0 lg:shadow-none lg:ring-0 dark:bg-ink-900/90 dark:ring-white/10 lg:dark:bg-transparent"
            >
              <Button type="button" variant="ghost" icon={<RotateCcw className="size-4" />} onClick={() => { setDraft(initial); setSubmitted(false); server.reset() }}>
                Discard
              </Button>
              <Button type="submit" className="flex-1" loading={update.isPending} disabled={!verified}>
                Save changes
              </Button>
            </motion.div>
          )}
        </AnimatePresence>
      </div>
    </form>
  )
}
