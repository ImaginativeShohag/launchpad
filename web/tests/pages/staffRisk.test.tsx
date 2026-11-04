import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { MAX_VOID_CALLS, voidAllVotes } from '@/api/hooks/useStaffRisk'
import type { VoidVotesDto } from '@/api/types'
import { apiError, json, type Handler } from '../helpers/mockApi'
import { failOnConsoleError } from '../helpers/consoleErrors'
import { renderApp } from '../helpers/renderApp'
import { layoutRoutes } from '../helpers/staffRoutes'
import { memberSummary, page, riskMember, riskSignal, signalDetail } from '../fixtures/staff'
import { apiUser } from '../fixtures/user'

failOnConsoleError()

const reviewer = () => apiUser({ role: 'reviewer', id: '01JREVIEWER000000000000001', handle: 'kenji' })
const admin = () => apiUser({ role: 'admin', id: '01JADMIN00000000000000001A', handle: 'sofia' })
const MEM = '01JBADMEM00000000000000001'
const SIG = '01JSIGNAL0000000000000001'

const base = (extra: Record<string, unknown> = {}) => ({
  ...layoutRoutes(),
  'GET /staff/risk-signals': json(page([riskSignal({ id: SIG })])),
  'GET /staff/risk-signals/:id': json({ data: signalDetail({ id: SIG }) }),
  'GET /staff/risk/members': json(page([riskMember()])),
  ...extra,
})

describe('risk signals', () => {
  it('lists open signals and filters by status and kind', async () => {
    const { api } = renderApp({ path: '/staff/risk', user: reviewer(), routes: base() })
    expect(await screen.findByRole('button', { name: /Shared device/ })).toBeTruthy()
    expect(screen.getByText('weight 30')).toBeTruthy()
    expect(api.callsTo('GET /staff/risk-signals')[0].query.get('status')).toBe('open')

    fireEvent.change(screen.getByLabelText('Signal status'), { target: { value: 'reviewed' } })
    await waitFor(() => expect(api.callsTo('GET /staff/risk-signals').some((c) => c.query.get('status') === 'reviewed')).toBe(true))
    fireEvent.change(screen.getByLabelText('Signal kind'), { target: { value: 'vote_burst' } })
    await waitFor(() => expect(api.callsTo('GET /staff/risk-signals').some((c) => c.query.get('kind') === 'vote_burst' && c.query.get('status') === 'reviewed')).toBe(true))
  })

  it('pages by cursor', async () => {
    const { api } = renderApp({
      path: '/staff/risk',
      user: reviewer(),
      routes: base({ 'GET /staff/risk-signals': ((call) => (call.query.get('cursor') === 'c2' ? json(page([riskSignal({ kind: 'ip_cluster' })])) : json(page([riskSignal()], 'c2')))) as Handler }),
    })
    fireEvent.click(await screen.findByRole('button', { name: 'Load more' }))
    expect(await screen.findByText('IP cluster')).toBeTruthy()
    expect(api.callsTo('GET /staff/risk-signals').some((c) => c.query.get('cursor') === 'c2')).toBe(true)
  })

  it('opens the evidence with 8-hex fingerprints only', async () => {
    renderApp({ path: '/staff/risk', user: reviewer(), routes: base() })
    fireEvent.click(await screen.findByRole('button', { name: /Shared device/ }))
    const dialog = await screen.findByRole('dialog')
    expect(await within(dialog).findByText('ab12cd34')).toBeTruthy()
    for (const code of Array.from(dialog.querySelectorAll('code'))) expect(code.textContent).toMatch(/^[0-9a-f]{8}$/)
    expect(within(dialog).getByRole('link', { name: '@badactor' }).getAttribute('href')).toBe(`/staff/risk/members/${MEM}`)
  })

  it('confirms with a note', async () => {
    const { api } = renderApp({
      path: '/staff/risk',
      user: reviewer(),
      routes: base({ 'POST /staff/risk-signals/:id/review': json({ data: riskSignal({ id: SIG, outcome: 'confirmed', status: 'reviewed' }), meta: { changed: true } }) }),
    })
    fireEvent.click(await screen.findByRole('button', { name: /Shared device/ }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(await within(dialog).findByRole('textbox', { name: /Note/ }), { target: { value: 'Same device, 4 accounts' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Confirm abuse' }))

    await waitFor(() => expect(api.callsTo('POST /staff/risk-signals/:id/review')).toHaveLength(1))
    expect(api.callsTo('POST /staff/risk-signals/:id/review')[0].path).toBe(`/staff/risk-signals/${SIG}/review`)
    expect(api.callsTo('POST /staff/risk-signals/:id/review')[0].body).toEqual({ outcome: 'confirmed', note: 'Same device, 4 accounts' })
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  })

  it('dismisses without a note', async () => {
    const { api } = renderApp({ path: '/staff/risk', user: reviewer(), routes: base({ 'POST /staff/risk-signals/:id/review': json({ data: riskSignal({ id: SIG }), meta: { changed: true } }) }) })
    fireEvent.click(await screen.findByRole('button', { name: /Shared device/ }))
    fireEvent.click(await screen.findByRole('button', { name: 'Dismiss as harmless' }))
    await waitFor(() => expect(api.callsTo('POST /staff/risk-signals/:id/review')).toHaveLength(1))
    expect(api.callsTo('POST /staff/risk-signals/:id/review')[0].body).toEqual({ outcome: 'dismissed', note: null })
  })

  it.each([
    ['own_signal', /cannot review a signal about your own account/],
    ['admin_only_signal', /Only an admin can review a signal about a staff member/],
  ])('says %s in plain words', async (code, text) => {
    renderApp({ path: '/staff/risk', user: reviewer(), routes: base({ 'POST /staff/risk-signals/:id/review': apiError(403, 'x', { code }) }) })
    fireEvent.click(await screen.findByRole('button', { name: /Shared device/ }))
    fireEvent.click(await screen.findByRole('button', { name: 'Confirm abuse' }))
    expect(await screen.findByText(text)).toBeTruthy()
    expect(screen.getByRole('dialog')).toBeTruthy()
  })

  it('shows the wait on a 429 and not found for a gone signal', async () => {
    renderApp({ path: '/staff/risk', user: reviewer(), routes: base({ 'POST /staff/risk-signals/:id/review': apiError(429, 'x', {}, { 'Retry-After': '4' }) }) })
    fireEvent.click(await screen.findByRole('button', { name: /Shared device/ }))
    fireEvent.click(await screen.findByRole('button', { name: 'Confirm abuse' }))
    expect(await screen.findByText(/Try again in 4 s/)).toBeTruthy()
  })

  it('shows not found when the signal is gone', async () => {
    renderApp({ path: '/staff/risk', user: reviewer(), routes: base({ 'GET /staff/risk-signals/:id': apiError(404, 'Not found.') }) })
    fireEvent.click(await screen.findByRole('button', { name: /Shared device/ }))
    expect(await within(await screen.findByRole('dialog')).findByText('Not found')).toBeTruthy()
  })

  it('lists members by risk', async () => {
    const { api } = renderApp({ path: '/staff/risk', user: reviewer(), routes: base() })
    fireEvent.click(await screen.findByRole('tab', { name: 'Members by risk' }))
    expect(await screen.findByText('@badactor')).toBeTruthy()
    expect(screen.getByText(/2 open · 5 flagged votes/)).toBeTruthy()
    expect(api.callsTo('GET /staff/risk/members')[0].query.get('suspended')).toBe('false')
  })
})

const memberRoutes = (extra: Record<string, unknown> = {}) => ({ ...layoutRoutes(), 'GET /staff/risk/members/:id': json({ data: memberSummary() }), ...extra })

describe('member risk summary', () => {
  it('shows the breakdown, votes, sign-ins and linked accounts, read-only for a reviewer', async () => {
    renderApp({ path: `/staff/risk/members/${MEM}`, user: reviewer(), routes: memberRoutes() })
    expect(await screen.findByRole('heading', { name: 'Bad Actor' })).toBeTruthy()
    expect(screen.getByText('Shared device', { selector: 'td' })).toBeTruthy()
    expect(screen.getByText('75%')).toBeTruthy()
    expect(screen.getByRole('link', { name: '@sidekick' }).getAttribute('href')).toBe('/staff/risk/members/01JLINKED0000000000000000001')
    expect(screen.queryByRole('button', { name: /Void votes/ })).toBeNull()
    expect(screen.queryByRole('button', { name: /Suspend for abuse/ })).toBeNull()
    expect(screen.getByText(/admin-only/)).toBeTruthy()
  })

  it('shows not found for an unknown member', async () => {
    renderApp({ path: `/staff/risk/members/${MEM}`, user: reviewer(), routes: memberRoutes({ 'GET /staff/risk/members/:id': apiError(404, 'Not found.') }) })
    expect(await screen.findByText('Not found')).toBeTruthy()
  })

  it('voids in rounds until the API says there is no more', async () => {
    const answers: VoidVotesDto[] = [
      { voided: 100, skipped_frozen: 2, failed: 0, has_more: true },
      { voided: 40, skipped_frozen: 0, failed: 1, has_more: false },
    ]
    const { api } = renderApp({
      path: `/staff/risk/members/${MEM}`,
      user: admin(),
      routes: memberRoutes({ 'POST /staff/risk/members/:id/void-votes': (() => json({ data: answers.shift() })) as Handler }),
    })
    fireEvent.click(await screen.findByRole('button', { name: /Void votes/ }))
    const dialog = await screen.findByRole('dialog')

    fireEvent.click(within(dialog).getByRole('button', { name: 'Void votes' }))
    expect(await within(dialog).findByText('Add a reason of at least 5 characters.')).toBeTruthy()
    fireEvent.change(within(dialog).getByRole('textbox', { name: 'Reason' }), { target: { value: 'Vote ring' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Void votes' }))

    expect(await screen.findByText(/Voided 140 votes, 2 on frozen days were left alone, 1 failed/)).toBeTruthy()
    expect(api.callsTo('POST /staff/risk/members/:id/void-votes')).toHaveLength(2)
    expect(api.callsTo('POST /staff/risk/members/:id/void-votes')[0].body).toEqual({ reason: 'Vote ring' })
    expect(api.callsTo('POST /staff/risk/members/:id/void-votes')[0].path).toBe(`/staff/risk/members/${MEM}/void-votes`)
  })

  it('stops when a round voided nothing even though the API still says has_more', async () => {
    const { api } = renderApp({
      path: `/staff/risk/members/${MEM}`,
      user: admin(),
      routes: memberRoutes({ 'POST /staff/risk/members/:id/void-votes': json({ data: { voided: 0, skipped_frozen: 0, failed: 100, has_more: true } }) }),
    })
    fireEvent.click(await screen.findByRole('button', { name: /Void votes/ }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByRole('textbox', { name: 'Reason' }), { target: { value: 'Vote ring' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Void votes' }))

    expect(await screen.findByText(/Stopped early/)).toBeTruthy()
    expect(api.callsTo('POST /staff/risk/members/:id/void-votes')).toHaveLength(1)
  })

  it('shows the API refusal and what was done so far', async () => {
    let n = 0
    renderApp({
      path: `/staff/risk/members/${MEM}`,
      user: admin(),
      routes: memberRoutes({
        'POST /staff/risk/members/:id/void-votes': (() => (n++ === 0 ? json({ data: { voided: 100, skipped_frozen: 0, failed: 0, has_more: true } }) : apiError(429, 'x', {}, { 'Retry-After': '8' }))) as Handler,
      }),
    })
    fireEvent.click(await screen.findByRole('button', { name: /Void votes/ }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByRole('textbox', { name: 'Reason' }), { target: { value: 'Vote ring' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Void votes' }))

    expect(await within(dialog).findByText(/Try again in 8 s/)).toBeTruthy()
    expect(within(dialog).getByText('Voided 100 before it stopped.')).toBeTruthy()
  })

  it('keeps the partial total on the page after the dialog is closed', async () => {
    let n = 0
    renderApp({
      path: `/staff/risk/members/${MEM}`,
      user: admin(),
      routes: memberRoutes({ 'POST /staff/risk/members/:id/void-votes': (() => (n++ === 0 ? json({ data: { voided: 100, skipped_frozen: 0, failed: 0, has_more: true } }) : apiError(500, 'boom'))) as Handler }),
    })
    fireEvent.click(await screen.findByRole('button', { name: /Void votes/ }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByRole('textbox', { name: 'Reason' }), { target: { value: 'Vote ring' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Void votes' }))
    await within(dialog).findByText('Voided 100 before it stopped.')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }))
    expect(await screen.findByText(/Voided 100 before it stopped. Run it again/)).toBeTruthy()
  })

  it('Esc and Cancel do nothing while a void is running, so no second loop can start', async () => {
    let release: () => void = () => {}
    const gate = new Promise<void>((r) => (release = r))
    const { api } = renderApp({
      path: `/staff/risk/members/${MEM}`,
      user: admin(),
      routes: memberRoutes({ 'POST /staff/risk/members/:id/void-votes': (async () => (await gate, json({ data: { voided: 3, skipped_frozen: 0, failed: 0, has_more: false } }))) as Handler }),
    })
    fireEvent.click(await screen.findByRole('button', { name: /Void votes/ }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByRole('textbox', { name: 'Reason' }), { target: { value: 'Vote ring' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Void votes' }))
    await waitFor(() => expect(api.callsTo('POST /staff/risk/members/:id/void-votes')).toHaveLength(1))

    fireEvent.keyDown(document, { key: 'Escape' })
    expect((within(dialog).getByRole('button', { name: 'Cancel' }) as HTMLButtonElement).disabled).toBe(true)
    expect(screen.getByRole('dialog')).toBeTruthy()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Void votes' }))
    expect(api.callsTo('POST /staff/risk/members/:id/void-votes')).toHaveLength(1)

    release()
    expect(await screen.findByText(/Voided 3 votes/)).toBeTruthy()
    expect(api.callsTo('POST /staff/risk/members/:id/void-votes')).toHaveLength(1)
  })

  it('keeps a visible error when the vote clean-up could not be queued', async () => {
    renderApp({
      path: `/staff/risk/members/${MEM}`,
      user: admin(),
      routes: memberRoutes({ 'POST /staff/risk/members/:id/suspend': json({ data: memberSummary({ suspended: true }), meta: { suspended_now: true, signals_confirmed: 0, void_votes: 'dispatch_failed' } }) }),
    })
    fireEvent.click(await screen.findByRole('button', { name: /Suspend for abuse/ }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByRole('textbox', { name: 'Reason' }), { target: { value: 'Vote ring' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Suspend' }))
    expect(await screen.findByText('Suspended, but the vote clean-up was not queued. Use Void votes.')).toBeTruthy()
  })

  it('stops the loop after the round in flight when the page is left', async () => {
    const answers = [{ voided: 100, skipped_frozen: 0, failed: 0, has_more: true }]
    const ctl = new AbortController()
    let calls = 0
    await expect(
      voidAllVotes(async () => (calls++, ctl.abort(), { ...answers[0] }), undefined, undefined, ctl.signal),
    ).rejects.toThrow()
    expect(calls).toBe(1)
  })

  it('suspends for abuse, with the void-votes switch on by default', async () => {
    let suspended = false
    const { api } = renderApp({
      path: `/staff/risk/members/${MEM}`,
      user: admin(),
      routes: memberRoutes({
        'GET /staff/risk/members/:id': (() => json({ data: memberSummary({ suspended, suspended_reason: suspended ? 'Vote ring' : null }) })) as Handler,
        'POST /staff/risk/members/:id/suspend': (() => {
          suspended = true
          return json({ data: memberSummary({ suspended: true, suspended_reason: 'Vote ring' }), meta: { suspended_now: true, signals_confirmed: 2, void_votes: 'queued' } })
        }) as Handler,
      }),
    })
    fireEvent.click(await screen.findByRole('button', { name: /Suspend for abuse/ }))
    const dialog = await screen.findByRole('dialog')
    expect((within(dialog).getByRole('checkbox') as HTMLInputElement).checked).toBe(true)
    fireEvent.change(within(dialog).getByRole('textbox', { name: 'Reason' }), { target: { value: 'Vote ring' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Suspend' }))

    await waitFor(() => expect(api.callsTo('POST /staff/risk/members/:id/suspend')).toHaveLength(1))
    expect(api.callsTo('POST /staff/risk/members/:id/suspend')[0].body).toEqual({ reason: 'Vote ring', void_votes: true })
    expect(await screen.findByText('Suspended', { selector: 'span' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: /Suspend for abuse/ })).toBeNull()
  })

  it('can suspend without voiding and shows a 403 in the dialog', async () => {
    const { api } = renderApp({ path: `/staff/risk/members/${MEM}`, user: admin(), routes: memberRoutes({ 'POST /staff/risk/members/:id/suspend': apiError(403, 'x', { code: 'cannot_suspend' }) }) })
    fireEvent.click(await screen.findByRole('button', { name: /Suspend for abuse/ }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('checkbox'))
    fireEvent.change(within(dialog).getByRole('textbox', { name: 'Reason' }), { target: { value: 'Vote ring' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Suspend' }))
    expect(await within(dialog).findByText(/cannot be suspended/)).toBeTruthy()
    expect(api.callsTo('POST /staff/risk/members/:id/suspend')[0].body).toEqual({ reason: 'Vote ring', void_votes: false })
  })
})

describe('voidAllVotes', () => {
  const r = (o: Partial<VoidVotesDto>): VoidVotesDto => ({ voided: 0, skipped_frozen: 0, failed: 0, has_more: false, ...o })

  it('stops at has_more false', async () => {
    const answers = [r({ voided: 100, has_more: true }), r({ voided: 5 })]
    const out = await voidAllVotes(async () => answers.shift()!, undefined)
    expect(out).toMatchObject({ voided: 105, calls: 2, stalled: false })
  })

  it('stops when a round voided nothing, whatever has_more says', async () => {
    let calls = 0
    const out = await voidAllVotes(async () => (++calls === 1 ? r({ voided: 3, has_more: true }) : r({ failed: 100, has_more: true })), undefined)
    expect(out).toMatchObject({ voided: 3, failed: 100, calls: 2, stalled: true })
  })

  it('has a hard cap on rounds', async () => {
    let calls = 0
    const out = await voidAllVotes(async () => (calls++, r({ voided: 1, has_more: true })), undefined)
    expect(calls).toBe(MAX_VOID_CALLS)
    expect(out.stalled).toBe(true)
  })

  it('passes the product filter through and reports progress', async () => {
    const seen: (string | undefined)[] = []
    const progress: number[] = []
    await voidAllVotes(async (p) => (seen.push(p), r({ voided: 2 })), 'P1', (t) => progress.push(t.voided))
    expect(seen).toEqual(['P1'])
    expect(progress).toEqual([2])
  })
})
