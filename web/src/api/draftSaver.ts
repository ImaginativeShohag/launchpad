import type { QueryClient } from '@tanstack/react-query'
import type { FounderProduct } from '@/data/types'
import { DESC_MAX, formFromProduct, isHttpsUrl, isValidName, isValidSlug, linkRowValid, TAGLINE_MAX, type DraftForm } from '@/lib/productForm'
import { isApiError, type ApiError, type FieldErrors } from './errors'
import { createProduct, updateProduct, type ProductPatch } from './endpoints/founder'
import { patchMyProduct, writeFounderProduct } from './founderCache'
import { keys } from './keys'
import { toFounderProduct } from './mappers'
import type { FounderProductDto } from './types'

export const AUTOSAVE_MS = 1200
/** Transient failures (offline, 5xx, 429) are retried this many times, 2 s, 4 s, 8 s apart, before the member is told. */
export const AUTO_RETRIES = 3
const BACKOFF_MS = 2000

export interface SaveResult {
  ok: boolean
  /** The API's 422 fields of this save (empty when it succeeded or failed another way). */
  fields: FieldErrors
}

const transient = (e: unknown): e is ApiError => isApiError(e) && (e.status === 0 || e.status === 429 || e.status >= 500)

export type SaveState = 'idle' | 'saving' | 'saved' | 'error'

export interface SaverHooks {
  state(s: SaveState): void
  saved(at: string): void
  errors(fields: FieldErrors): void
  failure(e: unknown): void
  created(p: FounderProduct): void
}

const sameList = (a: string[], b: string[]) => a.length === b.length && a.every((x, i) => x === b[i])

/**
 * Saves the wizard's text fields: a draft is created (name and slug) on the first save, then only what changed is PATCHed, and
 * only fields the status allows. One request at a time: a save asked for while another is running waits and becomes a single
 * follow-up that reads the newest form. A field the browser already knows is invalid is not sent (the form shows why).
 */
export class DraftSaver {
  id: string | undefined
  private hooks: SaverHooks
  private qc: QueryClient
  private server: DraftForm
  private editable: string[]
  private latest: DraftForm | null = null
  private timer: ReturnType<typeof setTimeout> | undefined
  private inflight: Promise<SaveResult> | null = null
  private followUp: Promise<SaveResult> | null = null
  private failed = false
  private toasted = false
  private attempts = 0
  private disposed = false

  constructor(qc: QueryClient, product: FounderProduct | undefined, hooks: SaverHooks) {
    this.qc = qc
    this.hooks = hooks
    this.id = product?.id
    this.server = formFromProduct(product)
    this.editable = product?.editableFields ?? []
  }

  /** Where state changes go; the hook rebinds it on every mount. */
  bind(hooks: SaverHooks) {
    this.hooks = hooks
    this.disposed = false
  }

  /** True when there is unsaved work: an edit waiting for the debounce, a retry due, or a change the server does not have. */
  get pending() {
    return this.timer !== undefined || this.failed || (this.id !== undefined && this.latest !== null && !!this.buildPatch(this.latest))
  }

  /**
   * Remember the newest form and (re)start the debounce. A draft that does not exist yet is not created by typing: it is created
   * by `flush()` (the name field losing focus, Next, an upload, Save & finish later), so a half-typed name never becomes a draft.
   */
  schedule(form: DraftForm) {
    this.latest = form
    this.attempts = 0
    if (!this.id || !this.buildPatch(form)) {
      const wasWaiting = this.timer !== undefined
      if (wasWaiting) clearTimeout(this.timer)
      this.timer = undefined
      // An edit undone before the debounce fired: the pill must not stay on "Saving…".
      if (wasWaiting && !this.inflight) this.hooks.state(this.failed ? 'error' : this.id ? 'saved' : 'idle')
      return
    }
    this.hooks.state('saving')
    if (this.timer !== undefined) clearTimeout(this.timer)
    this.timer = setTimeout(() => {
      this.timer = undefined
      void this.flush()
    }, AUTOSAVE_MS)
  }

  /** Save now. `ok` is true when nothing is left unsaved; on a 422 `fields` holds the API's messages. */
  flush(): Promise<SaveResult> {
    if (this.timer !== undefined) {
      clearTimeout(this.timer)
      this.timer = undefined
    }
    if (this.inflight) {
      this.followUp ??= this.inflight.then(() => {
        this.followUp = null
        return this.flush()
      })
      return this.followUp
    }
    const run = this.save().finally(() => {
      if (this.inflight === run) this.inflight = null
    })
    this.inflight = run
    return run
  }

  dispose() {
    this.disposed = true
    if (this.timer !== undefined) clearTimeout(this.timer)
    this.timer = undefined
  }


  private createInput(form: DraftForm) {
    const name = form.name.trim()
    return isValidName(name) && isValidSlug(form.slug) ? { name, slug: form.slug } : null
  }

  private cachedAlts(): Record<string, string> {
    const dto = this.id ? this.qc.getQueryData<FounderProductDto>(keys.myProduct(this.id)) : undefined
    return Object.fromEntries((dto?.screenshots ?? []).map((s) => [s.id, s.alt_text ?? '']))
  }

  /** What differs from the last saved state, limited to valid values of fields the status allows. null = nothing to send. */
  private buildPatch(form: DraftForm): ProductPatch | null {
    const ok = (k: string) => this.editable.includes(k)
    const s = this.server
    const patch: ProductPatch = {}
    const name = form.name.trim()
    if (ok('name') && name !== s.name && isValidName(name)) patch.name = name
    if (ok('slug') && form.slug !== s.slug && isValidSlug(form.slug)) patch.slug = form.slug
    const tagline = form.tagline.trim()
    if (ok('tagline') && tagline !== s.tagline && tagline.length <= TAGLINE_MAX) patch.tagline = tagline || null
    const description = form.description.trim()
    if (ok('description') && description !== s.description && description.length <= DESC_MAX) patch.description = description || null
    const website = form.website.trim()
    if (ok('website_url') && website !== s.website && (website === '' || isHttpsUrl(website))) patch.website_url = website || null
    if (ok('topic_ids') && !sameList(form.topicIds, s.topicIds)) patch.topic_ids = form.topicIds
    const links = form.links.filter((l) => l.url.trim() !== '' && l.url.trim() !== 'https://')
    const sent = (ls: { kind: string; url: string }[]) => JSON.stringify(ls.map((l) => [l.kind, l.url.trim()]))
    if (ok('links') && sent(links) !== sent(s.links) && links.every(linkRowValid)) patch.links = links.map((l) => ({ kind: l.kind, url: l.url.trim() }))
    if (ok('screenshots')) {
      const known = this.cachedAlts()
      const changed = Object.entries(form.alts).filter(([id, alt]) => id in known && alt.trim() !== known[id])
      if (changed.length) patch.screenshots = changed.map(([id, alt]) => ({ id, alt_text: alt.trim() || null }))
    }
    return Object.keys(patch).length ? patch : null
  }

  private async save(): Promise<SaveResult> {
    const form = this.latest
    if (!form) return { ok: true, fields: {} }
    this.hooks.state('saving')
    try {
      if (!this.id) {
        const input = this.createInput(form)
        if (!input) {
          this.hooks.state('idle')
          return { ok: true, fields: {} }
        }
        const dto = await createProduct(input)
        this.id = dto.id
        this.editable = dto.editable_fields
        this.server = { ...formFromProduct(toFounderProduct(dto)), name: input.name, slug: input.slug }
        writeFounderProduct(this.qc, dto, { refetchLists: false })
        // The new draft is not in any cached list yet: re-read them once.
        void this.qc.invalidateQueries({ queryKey: ['my-products'] })
        this.hooks.created(toFounderProduct(dto))
      }
      const patch = this.buildPatch(form)
      if (patch && this.id) {
        const dto = await updateProduct(this.id, patch)
        writeFounderProduct(this.qc, dto, { keepMedia: true, refetchLists: false })
        if (patch.screenshots) {
          const sent = new Map(patch.screenshots.map((x) => [x.id, x.alt_text]))
          patchMyProduct(this.qc, this.id, (p) => ({ ...p, screenshots: p.screenshots?.map((x) => (sent.has(x.id) ? { ...x, alt_text: sent.get(x.id) ?? null } : x)) }))
        }
        this.server = this.afterPatch(patch, form)
      }
      this.failed = false
      this.toasted = false
      this.attempts = 0
      this.hooks.errors({})
      this.hooks.saved(new Date().toISOString())
      // Only claim "Saved" when nothing newer is waiting.
      if (this.timer === undefined && !this.followUp) this.hooks.state('saved')
      return { ok: true, fields: {} }
    } catch (e) {
      if (!this.disposed && transient(e) && this.attempts < AUTO_RETRIES) {
        const delay = e.status === 429 && e.retryAfter ? e.retryAfter * 1000 : BACKOFF_MS * 2 ** this.attempts
        this.attempts += 1
        this.failed = true
        if (this.timer !== undefined) clearTimeout(this.timer)
        this.timer = setTimeout(() => {
          this.timer = undefined
          void this.flush()
        }, delay)
        this.hooks.state('saving')
        return { ok: false, fields: {} }
      }
      this.failed = true
      this.hooks.state('error')
      if (isApiError(e) && e.status === 422) {
        this.hooks.errors(e.fields)
        return { ok: false, fields: e.fields }
      }
      // One notice per failure episode, not one per attempt.
      if (!this.toasted) {
        this.toasted = true
        this.hooks.failure(e)
      }
      return { ok: false, fields: {} }
    }
  }

  private afterPatch(patch: ProductPatch, form: DraftForm): DraftForm {
    const s = { ...this.server }
    if (patch.name !== undefined) s.name = patch.name
    if (patch.slug !== undefined) s.slug = patch.slug
    if (patch.tagline !== undefined) s.tagline = patch.tagline ?? ''
    if (patch.description !== undefined) s.description = patch.description ?? ''
    if (patch.website_url !== undefined) s.website = patch.website_url ?? ''
    if (patch.topic_ids !== undefined) s.topicIds = patch.topic_ids
    if (patch.links !== undefined) s.links = form.links.filter((l) => l.url.trim() !== '' && l.url.trim() !== 'https://')
    return s
  }
}
