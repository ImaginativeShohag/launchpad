import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { apiError, json, type Handler } from '../helpers/mockApi'
import { failOnConsoleError } from '../helpers/consoleErrors'
import { renderApp } from '../helpers/renderApp'
import { layoutRoutes } from '../helpers/staffRoutes'
import { auditRow, page } from '../fixtures/staff'
import { apiUser } from '../fixtures/user'

failOnConsoleError()

const admin = () => apiUser({ role: 'admin', id: '01JADMIN00000000000000001A', handle: 'sofia' })
const reviewer = () => apiUser({ role: 'reviewer', id: '01JREVIEWER000000000000001', handle: 'kenji' })
const KENJI = '01JKENJI0000000000000000001'

const base = (rows = [auditRow()], extra: Record<string, unknown> = {}) => ({ ...layoutRoutes(), 'GET /admin/audit-logs': json(page(rows)), ...extra })

describe('audit log', () => {
  it('is admin only: a reviewer gets not allowed and no request', async () => {
    const { api } = renderApp({ path: '/staff/audit', user: reviewer(), routes: layoutRoutes() })
    expect(await screen.findByText('Not allowed')).toBeTruthy()
    expect(api.callsTo('GET /admin/audit-logs')).toHaveLength(0)
    expect(screen.queryByRole('link', { name: /Audit log/ })).toBeNull()
  })

  it('shows the audit tab to an admin', async () => {
    renderApp({ path: '/staff/audit', user: admin(), routes: base() })
    expect((await screen.findAllByRole('link', { name: /Audit log/ })).length).toBeGreaterThan(0)
  })

  it('shows not allowed when the API answers 403', async () => {
    renderApp({ path: '/staff/audit', user: admin(), routes: base([], { 'GET /admin/audit-logs': apiError(403, 'no') }) })
    expect(await screen.findByText('Not allowed')).toBeTruthy()
  })

  it('renders actor, subject and known meta as text, the rest as collapsed JSON', async () => {
    const hostile = '<img src=x onerror=alert(1)>'
    const rows = [
      auditRow({ meta: { decision: 'approved', review_id: 'r1', extra: { nested: hostile } } }),
      auditRow({ action: 'user.suspended', subject_type: 'user', meta: { reason: hostile } }),
      auditRow({ action: 'something.new', meta: [] }),
    ]
    const { container } = renderApp({ path: '/staff/audit', user: admin(), routes: base(rows) })

    expect((await screen.findAllByText('@kenji')).length).toBeGreaterThanOrEqual(3)
    expect(screen.getByText('decision:').parentElement?.textContent).toBe('decision: approvedreview_id: r1')
    expect(screen.getByText('reviewed')).toBeTruthy()
    expect(screen.getByText('something.new')).toBeTruthy()
    // Hostile text is text, never markup.
    expect(screen.getByText(hostile)).toBeTruthy()
    expect(container.querySelector('img[src="x"]')).toBeNull()
    const json = container.querySelector('details pre')!
    expect(json.textContent).toContain('"nested"')
  })

  it('maps the filters to the API query names and keeps the staff choice after it narrows the list', async () => {
    const { api } = renderApp({ path: '/staff/audit', user: admin(), routes: base() })
    await screen.findAllByText('@kenji')

    fireEvent.change(screen.getByLabelText('Filter by staff member'), { target: { value: KENJI } })
    await waitFor(() => expect(api.callsTo('GET /admin/audit-logs').some((c) => c.query.get('actor') === KENJI)).toBe(true))
    fireEvent.change(screen.getByLabelText('Filter by action'), { target: { value: 'report.resolved' } })
    await waitFor(() => expect(api.callsTo('GET /admin/audit-logs').some((c) => c.query.get('action') === 'report.resolved')).toBe(true))
    fireEvent.change(screen.getByLabelText('From date'), { target: { value: '2026-10-01' } })
    fireEvent.change(screen.getByLabelText('To date'), { target: { value: '2026-10-22' } })
    await waitFor(() =>
      expect(api.callsTo('GET /admin/audit-logs').some((c) => c.query.get('actor') === KENJI && c.query.get('action') === 'report.resolved' && c.query.get('from') === '2026-10-01' && c.query.get('to') === '2026-10-22')).toBe(true),
    )
    expect(within(screen.getByLabelText('Filter by staff member')).getByRole('option', { name: '@kenji' })).toBeTruthy()
  })

  it('does not send an invalid action or an end date before the start date', async () => {
    const { api } = renderApp({ path: '/staff/audit', user: admin(), routes: base() })
    await screen.findAllByText('@kenji')

    fireEvent.change(screen.getByLabelText('Filter by action'), { target: { value: 'bad action!' } })
    fireEvent.change(screen.getByLabelText('From date'), { target: { value: '2026-10-10' } })
    fireEvent.change(screen.getByLabelText('To date'), { target: { value: '2026-10-01' } })

    expect(await screen.findByText(/letters, digits, dots/)).toBeTruthy()
    expect(screen.getByText(/must not be before the start date/)).toBeTruthy()
    await new Promise((r) => setTimeout(r, 400))
    expect(api.callsTo('GET /admin/audit-logs').some((c) => c.query.has('action') || c.query.has('to'))).toBe(false)
  })

  it('loads more rows by cursor', async () => {
    const { api } = renderApp({
      path: '/staff/audit',
      user: admin(),
      routes: base([], { 'GET /admin/audit-logs': ((call) => (call.query.get('cursor') === 'c2' ? json(page([auditRow({ action: 'second.page' })])) : json(page([auditRow()], 'c2')))) as Handler }),
    })
    fireEvent.click(await screen.findByRole('button', { name: 'Load more' }))
    expect(await screen.findByText('second.page')).toBeTruthy()
    expect(api.callsTo('GET /admin/audit-logs').some((c) => c.query.get('cursor') === 'c2')).toBe(true)
  })

  describe('csv export', () => {
    let blobs: Blob[]
    const originals = { create: URL.createObjectURL, revoke: URL.revokeObjectURL, click: HTMLAnchorElement.prototype.click }
    beforeEach(() => {
      blobs = []
      URL.createObjectURL = vi.fn((b: Blob | MediaSource) => {
        blobs.push(b as Blob)
        return 'blob:test'
      })
      URL.revokeObjectURL = vi.fn()
      HTMLAnchorElement.prototype.click = vi.fn()
    })
    afterEach(() => {
      URL.createObjectURL = originals.create
      URL.revokeObjectURL = originals.revoke
      HTMLAnchorElement.prototype.click = originals.click
      vi.restoreAllMocks()
    })

    it('exports the loaded rows client-side with the formula guard', async () => {
      const rows = [auditRow({ id: 'a1', subject_id: '=cmd|calc', actor: { id: 'x', handle: 'kenji' } }), auditRow({ id: 'a2', meta: { reason: '@evil' } })]
      const { api } = renderApp({ path: '/staff/audit', user: admin(), routes: base(rows) })
      await screen.findAllByText('@kenji')
      const callsBefore = api.calls.length

      fireEvent.click(screen.getByRole('button', { name: /Export CSV/ }))

      expect(blobs).toHaveLength(1)
      const text = await blobs[0].text()
      const lines = text.split('\r\n')
      expect(lines[0]).toBe('id,timestamp_utc,actor_handle,action,subject_type,subject_id,meta')
      expect(lines).toHaveLength(3)
      expect(lines[1]).toContain("'=cmd|calc")
      expect(lines[2]).toContain('{""reason"":""@evil""}')
      expect(await screen.findByText('CSV exported')).toBeTruthy()
      await waitFor(() => expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:test'), { timeout: 3000 })
      // Entirely client-side: no request was made for it.
      expect(api.calls.length).toBe(callsBefore)
    })

    it('has nothing to export for an empty log', async () => {
      renderApp({ path: '/staff/audit', user: admin(), routes: base([]) })
      await screen.findByText('No matching entries')
      expect((screen.getByRole('button', { name: /Export CSV/ }) as HTMLButtonElement).disabled).toBe(true)
    })
  })
})
