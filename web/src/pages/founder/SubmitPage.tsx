import { AnimatePresence, motion } from 'motion/react'
import { ArrowLeft, ArrowRight, CalendarDays, Check, CheckCircle2, Circle, CloudCheck, CloudOff, FileEdit, Globe, Loader2, MessageSquareWarning, Rocket, Search, Send, ShieldCheck } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router'
import { useProductDraft } from '@/api/hooks/useProductDraft'
import { useDeleteScreenshot, useRemoveLogo, useReorderScreenshots, useSubmitProduct, useUploadLogo, useUploadScreenshot } from '@/api/hooks/useFounderMutations'
import { useMyProduct } from '@/api/hooks/useMyProducts'
import { useTopics } from '@/api/hooks/useTopics'
import { firstFieldError, isApiError, type FieldErrors } from '@/api/errors'
import { founderMessage } from '@/api/founderErrors'
import type { FounderProduct, FounderScreenshot } from '@/data/types'
import { timeAgo } from '@/lib/date'
import { DESC_MAX, formFromProduct, looksLikeUlid, imageProblem, isHttpsUrl, isValidName, isValidSlug, LOGO_MAX_BYTES, MAX_SHOTS, MAX_TOPICS, NAME_MAX, slugify, SHOT_MAX_BYTES, TAGLINE_MAX, type DraftForm } from '@/lib/productForm'
import { useVerifyPrompt } from '@/components/layout/AuthGate'
import { Button, EmptyState, ErrorState, Input, Skeleton, Textarea, cn, useToast } from '@/components/ui'
import { Stepper, type StepDef } from './components/Stepper'
import { Confetti } from './components/Confetti'
import { LogoPicker, PreviewCard, ProductLinksEditor, ScreenshotsField, TopicPicker, type PreviewData, type UploadRow } from './components/SubmitFields'

const STEPS: StepDef[] = [
  { label: 'Basics', hint: 'Name, pitch and website' },
  { label: 'Media', hint: 'Logo and screenshots' },
  { label: 'Details', hint: 'Description and topics' },
  { label: 'Review', hint: 'Check and submit' },
]

type Key = 'name' | 'slug' | 'tagline' | 'website' | 'logo' | 'shots' | 'description' | 'topics' | 'links'

/** The wizard's field names against the API's (422 `errors` keys). */
const API_FIELD: Record<Key, string> = { name: 'name', slug: 'slug', tagline: 'tagline', website: 'website_url', logo: 'logo', shots: 'screenshots', description: 'description', topics: 'topic_ids', links: 'links' }
const STEP_FIELDS: Key[][] = [['name', 'slug', 'tagline', 'website'], ['logo', 'shots'], ['description', 'topics', 'links'], []]

function validate(f: DraftForm, p: FounderProduct | undefined) {
  const e: Partial<Record<Key, string>> = {}
  if (!f.name.trim()) e.name = 'Give your product a name.'
  else if (!isValidName(f.name)) e.name = f.name.trim().length > NAME_MAX ? `Keep it under ${NAME_MAX} characters.` : 'Use at least one letter or number.'
  if (!isValidSlug(f.slug)) e.slug = 'Use 3 to 80 lowercase letters, numbers and single hyphens.'
  if (!f.tagline.trim()) e.tagline = 'Add a short pitch so people know what it does.'
  else if (f.tagline.trim().length > TAGLINE_MAX) e.tagline = `Keep the pitch to ${TAGLINE_MAX} characters.`
  if (!f.website.trim()) e.website = 'Where can people try it?'
  else if (!isHttpsUrl(f.website.trim())) e.website = 'Enter a full https link, like https://yourproduct.com'
  if (!p?.logoUrl) e.logo = 'Upload a logo so reviewers and visitors can recognise the product.'
  if (!p?.screenshots?.length) e.shots = 'Add at least one screenshot so reviewers can see the product.'
  if (!f.description.trim()) e.description = 'Tell people what it does and who it is for.'
  else if (f.description.trim().length > DESC_MAX) e.description = `Keep it under ${DESC_MAX} characters.`
  if (f.topicIds.length === 0) e.topics = 'Pick at least one topic.'
  if (f.links.some((l) => l.url.trim() && l.url.trim() !== 'https://' && !isHttpsUrl(l.url.trim()))) e.links = 'Every link needs a full https URL.'
  return e
}

const stepOfApiField = (name: string): number => {
  const base = name.split('.')[0]
  const key = (Object.keys(API_FIELD) as Key[]).find((k) => API_FIELD[k] === base)
  const i = STEP_FIELDS.findIndex((fields) => key && fields.includes(key))
  return i < 0 ? 0 : i
}

export default function SubmitPage() {
  const params = useParams()
  const navigate = useNavigate()
  const [round, setRound] = useState(0)
  // A malformed id is "not found" without a request.
  const badId = !!params.id && !looksLikeUlid(params.id)
  const id = badId ? undefined : params.id
  const query = useMyProduct(id)
  const existing = query.data
  // The wizard keeps its state when its own first save moves the address from /submit to /submit/:id, and starts over when the
  // address changes to another product (or back to /submit).
  const [createdId, setCreatedId] = useState<string | null>(null)
  const [keyState, setKeyState] = useState({ n: 0, id })
  if (keyState.id !== id) {
    const fromOwnCreate = keyState.id === undefined && id !== undefined && id === createdId
    setKeyState({ n: fromOwnCreate ? keyState.n : keyState.n + 1, id })
  }
  // Gate on the status seen when this draft was first opened, so submitting doesn't swap the success screen out.
  const [firstSeen, setFirstSeen] = useState<{ id: string; status: string } | null>(null)
  if (existing && firstSeen?.id !== existing.id) setFirstSeen({ id: existing.id, status: existing.status })

  if (badId) {
    return <EmptyState icon={<Search className="size-6" />} title="We couldn’t find that draft" body="It may have been deleted, or it belongs to someone else." action={<Link to="/my/products"><Button>Go to my products</Button></Link>} />
  }
  if (id && query.isPending) {
    return (
      <div className="mx-auto max-w-2xl space-y-4">
        <Skeleton className="h-10 w-64" />
        <Skeleton className="h-48 w-full" />
      </div>
    )
  }
  if (id && query.isError) {
    if (isApiError(query.error) && query.error.status === 404) {
      return <EmptyState icon={<Search className="size-6" />} title="We couldn’t find that draft" body="It may have been deleted, or it belongs to someone else." action={<Link to="/my/products"><Button>Go to my products</Button></Link>} />
    }
    return <ErrorState error={query.error} onRetry={() => void query.refetch()} title="Could not load this draft" />
  }
  const gateStatus = firstSeen && firstSeen.id === id ? firstSeen.status : existing?.status
  if (existing && gateStatus !== 'draft' && gateStatus !== 'changes_requested') {
    return (
      <EmptyState
        icon={<ShieldCheck className="size-6" />}
        title="This product can’t be edited here"
        body={existing.status === 'pending' ? 'It is with our reviewers right now. You’ll hear back soon.' : 'Only drafts and products sent back for changes can be edited in the submit form.'}
        action={<Link to="/my/products"><Button>Go to my products</Button></Link>}
      />
    )
  }
  return <Wizard key={`${round}-${keyState.n}`} initial={existing} onCreated={setCreatedId} onAnother={() => { setRound((r) => r + 1); navigate('/submit') }} />
}

function Wizard({ initial, onCreated, onAnother }: { initial?: FounderProduct; onCreated: (id: string) => void; onAnother: () => void }) {
  const toast = useToast()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const promptVerify = useVerifyPrompt()
  const topicsQuery = useTopics()
  const [draftId, setDraftId] = useState(initial?.id)
  const product = useMyProduct(draftId).data ?? initial
  const [form, setForm] = useState<DraftForm>(() => formFromProduct(initial))
  const [step, setStep] = useState(0)
  const [maxReached, setMaxReached] = useState(initial ? 3 : 0)
  const [dir, setDir] = useState(1)
  const [touched, setTouched] = useState<Set<string>>(new Set())
  const [submitted, setSubmitted] = useState(false)
  const [, tick] = useState(0)
  const dirty = useRef(false)
  const slugTouched = useRef(!!initial)

  const draft = useProductDraft(initial, (p) => {
    setDraftId(p.id)
    onCreated(p.id)
    navigate(`/submit/${p.id}`, { replace: true })
  })
  const { schedule } = draft
  const submitMutation = useSubmitProduct()
  const uploadLogo = useUploadLogo()
  const removeLogo = useRemoveLogo()
  const uploadShot = useUploadScreenshot()
  const reorder = useReorderScreenshots()
  const deleteShot = useDeleteScreenshot()

  const errors = useMemo(() => validate(form, product), [form, product])
  // Link rows show their own server message (below); everything else is the API's message for that field.
  const serverError = (k: Key) => (k === 'links' ? undefined : draft.errors[API_FIELD[k]]?.[0])
  const show = (k: Key) => serverError(k) ?? (touched.has(k) ? errors[k] : undefined)
  const touch = (k: Key) => setTouched((t) => new Set(t).add(k))

  const { clearError } = draft
  const patch = useCallback(
    (p: Partial<DraftForm>, clears: string[] = []) => {
      dirty.current = true
      clears.forEach(clearError)
      setForm((f) => ({ ...f, ...p }))
    },
    [clearError],
  )

  // Debounced autosave: only once the member changed something.
  useEffect(() => {
    if (dirty.current && !submitted) schedule(form)
  }, [form, schedule, submitted])

  // Autosave failures that are not field errors (a lock, a rate limit, no network).
  useEffect(() => {
    const e = draft.failure
    if (!e) return
    if (isApiError(e) && e.code === 'email_unverified') promptVerify()
    else toast({ tone: 'error', title: 'Could not save your changes', body: founderMessage(e) })
  }, [draft.failure, promptVerify, toast])

  // Keep "Saved · 2m ago" fresh.
  useEffect(() => {
    const t = setInterval(() => tick((n) => n + 1), 20_000)
    return () => clearInterval(t)
  }, [])

  // Moving between steps moves keyboard focus to the new step's heading (not on first load).
  const stepHeading = useRef<HTMLHeadingElement>(null)
  const firstStep = useRef(true)
  useEffect(() => {
    if (firstStep.current) firstStep.current = false
    else stepHeading.current?.focus()
  }, [step])

  const goTo = (i: number) => {
    setDir(i > step ? 1 : -1)
    setStep(i)
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  const next = () => {
    const fields = STEP_FIELDS[step]
    const bad = fields.filter((k) => errors[k])
    if (bad.length) {
      setTouched((t) => new Set([...t, ...fields]))
      toast({ tone: 'error', title: 'A few things need attention', body: bad.map((k) => errors[k]).join(' ') })
      return
    }
    // Leaving the first step is the other moment the draft is created (the name field's blur is the first).
    if (step === 0 && !draft.getId()) void draft.flush()
    const n = step + 1
    setMaxReached((m) => Math.max(m, n))
    goTo(n)
  }

  /* ---- media ---- */

  const ensureDraft = async (): Promise<string | undefined> => {
    if (!draft.getId()) {
      dirty.current = true
      schedule(form)
    }
    await draft.flush()
    return draft.getId()
  }

  const handleMediaError = (e: unknown) => isApiError(e) && e.code === 'email_unverified' && (promptVerify(), true)

  const [logoPct, setLogoPct] = useState<number>()
  const [logoError, setLogoError] = useState<string>()
  const onLogo = async (file: File) => {
    const problem = imageProblem(file, LOGO_MAX_BYTES)
    if (problem) return setLogoError(problem)
    setLogoError(undefined)
    const id = await ensureDraft()
    if (!id) return setLogoError('Give your product a name and a web address first, then upload the logo.')
    setLogoPct(0)
    try {
      await uploadLogo.mutateAsync({ id, file, onProgress: setLogoPct })
      clearError('logo')
    } catch (e) {
      if (!handleMediaError(e)) setLogoError(firstFieldError(e, 'logo') ?? founderMessage(e))
    } finally {
      setLogoPct(undefined)
    }
  }
  const onRemoveLogo = async () => {
    const id = draft.getId()
    if (!id) return
    try {
      await removeLogo.mutateAsync(id)
    } catch (e) {
      if (!handleMediaError(e)) toast({ tone: 'error', title: 'Could not remove the logo', body: founderMessage(e) })
    }
  }

  const [uploads, setUploads] = useState<UploadRow[]>([])
  const files = useRef(new Map<string, File>())
  const queue = useRef<Promise<void>>(Promise.resolve())
  const seq = useRef(0)
  const setRow = (key: string, p: Partial<UploadRow>) => setUploads((rows) => rows.map((r) => (r.key === key ? { ...r, ...p } : r)))
  const dropRow = (key: string) => {
    files.current.delete(key)
    setUploads((rows) => rows.filter((r) => r.key !== key))
  }

  const runUpload = async (key: string) => {
    const file = files.current.get(key)
    if (!file) return
    setRow(key, { pct: 0, error: undefined })
    const id = await ensureDraft()
    if (!id) return setRow(key, { error: 'Give your product a name and a web address first.', retryable: true })
    try {
      await uploadShot.mutateAsync({ id, file, onProgress: (pct) => setRow(key, { pct }) })
      clearError('screenshots')
      dropRow(key)
    } catch (e) {
      if (!handleMediaError(e)) setRow(key, { error: firstFieldError(e, 'screenshot') ?? founderMessage(e), retryable: true })
      else dropRow(key)
    }
  }
  const enqueue = (key: string) => {
    queue.current = queue.current.then(() => runUpload(key))
  }

  const shotCount = product?.screenshots?.length ?? 0
  const onFiles = (list: File[]) => {
    const room = MAX_SHOTS - shotCount - uploads.filter((u) => !u.error).length
    const accepted = list.slice(0, Math.max(0, room))
    if (list.length > accepted.length) toast({ tone: 'info', title: `Only ${Math.max(0, room)} more fit`, body: `You can add up to ${MAX_SHOTS} screenshots.` })
    for (const file of accepted) {
      const key = `u${++seq.current}`
      const problem = imageProblem(file, SHOT_MAX_BYTES)
      setUploads((rows) => [...rows, { key, name: file.name, pct: 0, error: problem }])
      if (problem) continue
      files.current.set(key, file)
      enqueue(key)
    }
  }

  const [localOrder, setLocalOrder] = useState<string[] | null>(null)
  const shots: FounderScreenshot[] = useMemo(() => {
    const all = product?.screenshots ?? []
    if (!localOrder) return all
    const byId = new Map(all.map((s) => [s.id, s]))
    const ordered = localOrder.flatMap((id) => byId.get(id) ?? [])
    return [...ordered, ...all.filter((s) => !localOrder.includes(s.id))]
  }, [product?.screenshots, localOrder])

  // Reorders go one at a time; the local order is dropped only when none is left in flight.
  const reorderChain = useRef<Promise<void>>(Promise.resolve())
  const reordering = useRef(0)
  const commitOrder = (ids: string[]) => {
    const id = draft.getId()
    if (!id) return
    if (reordering.current === 0 && ids.join() === (product?.screenshots ?? []).map((x) => x.id).join()) return setLocalOrder(null)
    reordering.current += 1
    reorderChain.current = reorderChain.current.then(async () => {
      try {
        await reorder.mutateAsync({ id, ids })
      } catch (e) {
        if (!handleMediaError(e)) toast({ tone: 'error', title: 'Could not save the new order', body: founderMessage(e) })
      } finally {
        reordering.current -= 1
        if (reordering.current === 0) setLocalOrder(null)
      }
    })
  }
  const removeShot = async (shotId: string) => {
    const id = draft.getId()
    if (!id) return
    try {
      await deleteShot.mutateAsync({ id, shotId })
    } catch (e) {
      if (!handleMediaError(e)) toast({ tone: 'error', title: 'Could not remove the screenshot', body: founderMessage(e) })
    }
  }

  /* ---- leaving and submitting ---- */

  const jumpToServerErrors = (fields: FieldErrors) => {
    const steps = Object.keys(fields).map(stepOfApiField)
    if (steps.length) goTo(Math.min(...steps))
  }

  const saveAndLeave = async () => {
    const { ok } = await draft.flush()
    if (!ok) {
      toast({ tone: 'error', title: 'Could not save your draft', body: 'Fix what is highlighted, or try again in a moment.' })
      return
    }
    if (draft.getId()) toast({ tone: 'success', title: 'Draft saved', body: 'Pick up where you left off from My products.' })
    // My products must show the draft at once, not a list read before it existed.
    void qc.invalidateQueries({ queryKey: ['my-products'] })
    navigate('/my/products')
  }

  const allErrors = Object.values(errors).filter(Boolean)
  const uploading = logoPct !== undefined || uploads.some((u) => !u.error)
  const submit = async () => {
    if (allErrors.length) {
      setTouched(new Set(Object.keys(errors)))
      toast({ tone: 'error', title: 'Almost there', body: 'Finish the checklist before submitting.' })
      const first = (Object.keys(errors) as Key[]).map((k) => STEP_FIELDS.findIndex((f) => f.includes(k))).filter((i) => i >= 0)
      if (first.length) goTo(Math.min(...first))
      return
    }
    if (uploading) {
      toast({ tone: 'info', title: 'Uploads still running', body: 'Wait for them to finish, then submit.' })
      return
    }
    const saved = await draft.flush()
    if (!saved.ok) {
      toast({ tone: 'error', title: 'Could not save your changes', body: 'Fix what is highlighted, then submit.' })
      jumpToServerErrors(saved.fields)
      return
    }
    const id = draft.getId()
    if (!id) return
    try {
      await submitMutation.mutateAsync(id)
      setSubmitted(true)
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } catch (e) {
      if (isApiError(e) && e.code === 'email_unverified') return promptVerify()
      if (isApiError(e) && e.status === 422) {
        // The API lists every missing field: show each under its input and open the first step that has one.
        draft.setServerErrors(e.fields)
        setTouched(new Set(Object.keys(errors)))
        jumpToServerErrors(e.fields)
        toast({ tone: 'error', title: 'Almost there', body: 'The review needs a few more things. They are marked in the form.' })
        return
      }
      toast({ tone: 'error', title: 'Could not submit', body: founderMessage(e) })
    }
  }

  const topicObjects = useMemo(() => (topicsQuery.data ?? []).filter((t) => form.topicIds.includes(t.id)), [topicsQuery.data, form.topicIds])
  const preview: PreviewData = {
    name: form.name,
    tagline: form.tagline,
    description: form.description,
    website: form.website,
    hue: product?.hue ?? 20,
    logoUrl: product?.logoUrl,
    shots: shots.map((s, i) => ({ id: s.id, url: s.url, label: (form.alts[s.id] ?? s.altText) || `Screenshot ${i + 1}` })),
    topics: topicObjects,
  }

  if (submitted) return <SubmittedScreen name={form.name} onAnother={onAnother} />

  const checklist = [
    { label: 'Name and one-line pitch', ok: !errors.name && !errors.slug && !errors.tagline, step: 0 },
    { label: 'Working website link', ok: !errors.website, step: 0 },
    { label: 'Logo', ok: !errors.logo, step: 1 },
    { label: `At least one screenshot (${shots.length} added)`, ok: !errors.shots, step: 1 },
    { label: 'Description', ok: !errors.description, step: 2 },
    { label: `Topics (${form.topicIds.length}/${MAX_TOPICS})`, ok: !errors.topics, step: 2 },
  ]
  const doneCount = checklist.filter((c) => c.ok).length
  const linkErrors: Record<number, string> = {}
  for (const [name, msgs] of Object.entries(draft.errors)) {
    const m = /^links\.(\d+)\./.exec(name)
    if (m && msgs[0]) linkErrors[Number(m[1])] = msgs[0]
  }

  return (
    <div className="mx-auto max-w-2xl">
      {/* Header */}
      <div className="mb-5 flex items-start justify-between gap-3">
        <div>
          <Link to="/my/products" className="mb-1 inline-flex items-center gap-1 text-sm font-medium text-ink-500 hover:text-ink-800 dark:hover:text-ink-200">
            <ArrowLeft className="size-4" /> My products
          </Link>
          <h1 className="text-2xl font-bold sm:text-3xl">
            {initial?.status === 'changes_requested' ? 'Fix & resubmit' : initial ? 'Continue your draft' : (
              <>
                Submit your <span className="text-gradient">product</span>
              </>
            )}
          </h1>
          <p className="mt-0.5 text-sm text-ink-500">{STEPS[step].hint} · step {step + 1} of {STEPS.length}</p>
        </div>
        <SaveIndicator state={draft.state} savedAt={draft.savedAt} onRetry={() => void draft.flush()} />
      </div>

      {product?.status === 'changes_requested' && product.reviewNote && (
        <motion.div initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }} className="mb-5 flex gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/20 dark:bg-amber-500/10">
          <MessageSquareWarning className="mt-0.5 size-5 shrink-0 text-amber-600" />
          <div className="text-sm">
            <p className="font-semibold text-amber-900 dark:text-amber-200">A reviewer asked for changes</p>
            <p className="mt-1 text-amber-800 dark:text-amber-300">“{product.reviewNote}”</p>
            <p className="mt-2 text-xs text-amber-700/80 dark:text-amber-400/80">Make the changes below, then submit again from the Review step.</p>
          </div>
        </motion.div>
      )}

      <h2 ref={stepHeading} tabIndex={-1} className="sr-only">
        Step {step + 1} of {STEPS.length}: {STEPS[step].label}
      </h2>
      <div className="surface mb-5 px-4 pt-5 pb-4 sm:px-6">
        <Stepper steps={STEPS} current={step} maxReached={maxReached} onStep={goTo} />
      </div>

      {/* Step body */}
      <div className="relative">
        <AnimatePresence mode="wait" custom={dir} initial={false}>
          <motion.div
            key={step}
            custom={dir}
            initial={{ opacity: 0, x: dir * 40 }}
            animate={{ opacity: 1, x: 0 }}
            exit={{ opacity: 0, x: dir * -40 }}
            transition={{ type: 'spring', stiffness: 380, damping: 34 }}
          >
            {step === 0 && (
              <section className="surface space-y-5 p-5 sm:p-6">
                <Input
                  label="Product name"
                  placeholder="e.g. Palette Pilot"
                  value={form.name}
                  maxLength={NAME_MAX + 10}
                  counter={{ value: form.name.length, max: NAME_MAX }}
                  error={show('name')}
                  onBlur={() => {
                    touch('name')
                    // The draft is created when the name is done, not while it is typed.
                    if (!draft.getId() && isValidName(form.name) && isValidSlug(form.slug)) void draft.flush()
                  }}
                  onChange={(e) => {
                    const name = e.target.value
                    // The web address follows the name until the member edits the address field itself (also after the draft exists).
                    patch(slugTouched.current ? { name } : { name, slug: slugify(name) }, ['name', 'slug'])
                  }}
                  autoFocus={!initial}
                />
                <Input
                  label="Web address"
                  placeholder="palette-pilot"
                  leading={<span className="text-xs font-semibold">/p/</span>}
                  value={form.slug}
                  error={show('slug')}
                  hint="Where your product page lives. Lowercase letters, numbers and hyphens."
                  onBlur={() => touch('slug')}
                  onChange={(e) => {
                    slugTouched.current = true
                    patch({ slug: e.target.value.toLowerCase() }, ['slug'])
                  }}
                />
                <Input
                  label="One-line pitch"
                  placeholder="What does it do, in one breath?"
                  value={form.tagline}
                  counter={{ value: form.tagline.length, max: TAGLINE_MAX }}
                  error={show('tagline')}
                  hint="Shown under your name in every list. Be specific, skip the buzzwords."
                  onBlur={() => touch('tagline')}
                  onChange={(e) => patch({ tagline: e.target.value }, ['tagline'])}
                />
                <Input
                  label="Website"
                  type="url"
                  inputMode="url"
                  placeholder="https://yourproduct.com"
                  leading={<Globe className="size-4" />}
                  trailing={
                    form.website && !errors.website ? (
                      <motion.span initial={{ scale: 0 }} animate={{ scale: 1 }} className="mr-1 flex size-6 items-center justify-center rounded-full bg-emerald-500 text-white">
                        <Check className="size-3.5" strokeWidth={3} />
                      </motion.span>
                    ) : undefined
                  }
                  value={form.website}
                  error={show('website')}
                  hint="Where people can try or buy it. https only."
                  onBlur={() => {
                    const w = form.website.trim()
                    if (w && !/^[a-z][a-z0-9+.-]*:/i.test(w)) patch({ website: `https://${w}` })
                    touch('website')
                  }}
                  onChange={(e) => patch({ website: e.target.value }, ['website_url'])}
                />
              </section>
            )}

            {step === 1 && (
              <div className="space-y-5">
                <section className="surface p-5 sm:p-6">
                  <h2 className="mb-1 text-lg font-bold">Logo</h2>
                  <p className="mb-4 text-sm text-ink-500">Upload your logo. It is saved as soon as it finishes uploading.</p>
                  <LogoPicker
                    name={form.name}
                    hue={product?.hue ?? 20}
                    logoUrl={product?.logoUrl}
                    progress={logoPct}
                    error={logoError ?? show('logo')}
                    onUpload={(f) => void onLogo(f)}
                    onRemove={() => void onRemoveLogo()}
                  />
                </section>
                <section className="surface p-5 sm:p-6">
                  <h2 className="mb-1 text-lg font-bold">Screenshots</h2>
                  <p className="mb-4 text-sm text-ink-500">Show the real product. Reviewers send back submissions with mockups only.</p>
                  <ScreenshotsField
                    shots={shots}
                    captions={form.alts}
                    uploads={uploads}
                    error={show('shots')}
                    onFiles={onFiles}
                    onOrder={(s) => setLocalOrder(s.map((x) => x.id))}
                    onCommitOrder={commitOrder}
                    onRemove={(id) => void removeShot(id)}
                    onCaption={(id, caption) => patch({ alts: { ...form.alts, [id]: caption } })}
                    onRetryUpload={(key) => enqueue(key)}
                    onDismissUpload={dropRow}
                  />
                </section>
              </div>
            )}

            {step === 2 && (
              <section className="surface space-y-6 p-5 sm:p-6">
                <Textarea
                  label="Description"
                  rows={7}
                  placeholder={'What problem does it solve? Who is it for? What makes it different?\n\nTip: a short story about why you built it goes a long way.'}
                  value={form.description}
                  counter={{ value: form.description.length, max: DESC_MAX }}
                  error={show('description')}
                  hint="You can still edit this after launch."
                  onBlur={() => touch('description')}
                  onChange={(e) => patch({ description: e.target.value }, ['description'])}
                />
                <TopicPicker value={form.topicIds} error={show('topics')} onChange={(topicIds) => patch({ topicIds }, ['topic_ids'])} />
                <div>
                  <p className="mb-2 text-sm font-semibold">More links <span className="font-normal text-ink-400">(optional)</span></p>
                  <ProductLinksEditor links={form.links} onChange={(links) => patch({ links }, ['links'])} serverErrors={linkErrors} />
                  {show('links') && <p className="mt-1 text-xs font-medium text-red-500">{show('links')}</p>}
                </div>
              </section>
            )}

            {step === 3 && (
              <div className="space-y-5">
                <PreviewCard d={preview} />
                <section className="surface p-5 sm:p-6">
                  <div className="mb-3 flex items-center justify-between">
                    <h2 className="text-lg font-bold">Checklist</h2>
                    <span className="text-sm font-semibold text-ink-500 tabular-nums">{doneCount}/{checklist.length}</span>
                  </div>
                  <div className="mb-4 h-1.5 overflow-hidden rounded-full bg-ink-100 dark:bg-white/8">
                    <motion.div className="h-full rounded-full bg-emerald-500" initial={{ width: 0 }} animate={{ width: `${(doneCount / checklist.length) * 100}%` }} transition={{ type: 'spring', stiffness: 120, damping: 20 }} />
                  </div>
                  <ul className="space-y-1">
                    {checklist.map((c, i) => (
                      <motion.li key={c.label} initial={{ opacity: 0, x: -8 }} animate={{ opacity: 1, x: 0 }} transition={{ delay: i * 0.05 }}>
                        <button type="button" onClick={() => !c.ok && goTo(c.step)} className={cn('flex w-full items-center gap-2.5 rounded-xl px-2 py-2 text-left text-sm', !c.ok && 'cursor-pointer hover:bg-ink-50 dark:hover:bg-white/5')}>
                          {c.ok ? <CheckCircle2 className="size-5 shrink-0 text-emerald-500" /> : <Circle className="size-5 shrink-0 text-ink-300" />}
                          <span className={cn('flex-1', c.ok ? 'text-ink-700 dark:text-ink-200' : 'text-ink-500')}>{c.label}</span>
                          {!c.ok && <span className="text-xs font-semibold text-brand-600">Fix</span>}
                        </button>
                      </motion.li>
                    ))}
                  </ul>
                  <div className="mt-4 flex gap-3 rounded-2xl bg-ink-50 p-4 text-sm text-ink-600 dark:bg-white/5 dark:text-ink-300">
                    <ShieldCheck className="mt-0.5 size-5 shrink-0 text-brand-500" />
                    <p>Our reviewers check every submission before it appears anywhere. You'll be told the outcome by email and in-app.</p>
                  </div>
                </section>
              </div>
            )}
          </motion.div>
        </AnimatePresence>
      </div>

      {/* Action bar */}
      <div className="sticky bottom-[76px] z-20 mt-5 md:bottom-4">
        <div className="flex items-center gap-2 rounded-2xl border border-ink-200/70 bg-white/85 p-2 shadow-lift backdrop-blur-md dark:border-white/10 dark:bg-ink-900/85">
          {step > 0 ? (
            <Button variant="ghost" onClick={() => goTo(step - 1)} icon={<ArrowLeft className="size-4" />} aria-label="Back">
              <span className="hidden sm:inline">Back</span>
            </Button>
          ) : (
            <span className="w-1" />
          )}
          <Button variant="outline" onClick={() => void saveAndLeave()} className="flex-1 sm:flex-none">
            <span className="sm:hidden">Save for later</span>
            <span className="hidden sm:inline">Save &amp; finish later</span>
          </Button>
          <div className="hidden flex-1 sm:block" />
          {step < 3 ? (
            <Button onClick={next} className="flex-1 sm:flex-none">
              Next <ArrowRight className="size-4" />
            </Button>
          ) : (
            <Button onClick={() => void submit()} loading={submitMutation.isPending} icon={<Send className="size-4" />} className="flex-1 sm:flex-none">
              Submit for review
            </Button>
          )}
        </div>
      </div>
    </div>
  )
}

function SaveIndicator({ state, savedAt, onRetry }: { state: 'idle' | 'saving' | 'saved' | 'error'; savedAt: string | null; onRetry: () => void }) {
  if (state === 'idle') return <span className="mt-6 hidden shrink-0 items-center gap-1.5 text-xs text-ink-400 sm:inline-flex"><FileEdit className="size-3.5" />Autosaves as you type</span>
  return (
    <AnimatePresence mode="wait">
      <motion.span
        key={state}
        role="status"
        initial={{ opacity: 0, y: -4 }}
        animate={{ opacity: 1, y: 0 }}
        exit={{ opacity: 0, y: 4 }}
        className={cn(
          'mt-6 inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium',
          state === 'saving' ? 'bg-ink-100 text-ink-500 dark:bg-white/8' : state === 'error' ? 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
        )}
      >
        {state === 'saving' ? <Loader2 className="size-3.5 animate-spin" /> : state === 'error' ? <CloudOff className="size-3.5" /> : <CloudCheck className="size-3.5" />}
        {state === 'saving' ? 'Saving…' : state === 'error' ? 'Not saved' : `Saved · ${savedAt ? timeAgo(savedAt) : 'just now'}`}
        {state === 'error' && (
          <button type="button" onClick={onRetry} className="ml-1 cursor-pointer font-semibold underline">
            Retry
          </button>
        )}
      </motion.span>
    </AnimatePresence>
  )
}

function SubmittedScreen({ name, onAnother }: { name: string; onAnother: () => void }) {
  const steps = [
    { icon: ShieldCheck, title: 'Review', body: 'A reviewer checks your submission, usually within 24 hours. We’ll email you and notify you here.', active: true },
    { icon: CalendarDays, title: 'Pick your launch day', body: 'Once approved, choose any upcoming day from My products. You can change it until launch.' },
    { icon: Rocket, title: 'Goes live automatically', body: 'At midnight UTC on your day it appears on the front page. No button to press.' },
  ]
  return (
    <div className="relative mx-auto max-w-lg overflow-hidden pt-4 text-center">
      <Confetti />
      <motion.div
        initial={{ scale: 0, rotate: -30 }}
        animate={{ scale: 1, rotate: 0 }}
        transition={{ type: 'spring', stiffness: 260, damping: 14, delay: 0.1 }}
        className="mx-auto flex size-20 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-pink-500 text-white shadow-glow"
      >
        <Check className="size-10" strokeWidth={3} />
      </motion.div>
      <motion.h1 initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.3 }} className="mt-6 text-3xl font-bold">
        {name || 'Your product'} is <span className="text-gradient">in review</span>
      </motion.h1>
      <motion.p initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ delay: 0.4 }} className="mt-2 text-ink-500">
        Nobody else can see it yet. Here’s what happens next.
      </motion.p>

      <ol className="surface mt-8 p-5 text-left sm:p-6">
        {steps.map((s, i) => (
          <motion.li key={s.title} initial={{ opacity: 0, x: -12 }} animate={{ opacity: 1, x: 0 }} transition={{ delay: 0.55 + i * 0.15 }} className="relative flex gap-4 pb-6 last:pb-0">
            {i < steps.length - 1 && (
              <motion.span className="absolute top-10 left-5 w-0.5 origin-top bg-ink-100 dark:bg-white/8" style={{ bottom: 0 }} initial={{ scaleY: 0 }} animate={{ scaleY: 1 }} transition={{ delay: 0.7 + i * 0.15 }} />
            )}
            <span className={cn('relative flex size-10 shrink-0 items-center justify-center rounded-full', s.active ? 'bg-brand-500 text-white shadow-glow' : 'bg-ink-100 text-ink-500 dark:bg-white/8')}>
              {s.active && <motion.span className="absolute inset-0 rounded-full bg-brand-500" animate={{ scale: [1, 1.6], opacity: [0.5, 0] }} transition={{ repeat: Infinity, duration: 1.8 }} />}
              <s.icon className="relative size-5" />
            </span>
            <div className="pt-1">
              <p className="font-semibold">
                {s.title} {s.active && <span className="ml-1 rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-bold text-brand-600 dark:bg-brand-500/10">Now</span>}
              </p>
              <p className="mt-0.5 text-sm text-ink-500">{s.body}</p>
            </div>
          </motion.li>
        ))}
      </ol>

      <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ delay: 1.1 }} className="mt-6 flex flex-col gap-2 sm:flex-row sm:justify-center">
        <Link to="/my/products">
          <Button className="w-full">Go to my products</Button>
        </Link>
        <Button variant="outline" onClick={onAnother}>Submit another</Button>
      </motion.div>
    </div>
  )
}
