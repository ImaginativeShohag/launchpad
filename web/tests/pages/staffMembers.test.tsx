import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { apiError, json, validation, type Handler } from '../helpers/mockApi'
import { failOnConsoleError } from '../helpers/consoleErrors'
import { renderApp } from '../helpers/renderApp'
import { layoutRoutes } from '../helpers/staffRoutes'
import { adminMember, page, riskMember } from '../fixtures/staff'
import { apiUser } from '../fixtures/user'

failOnConsoleError()

const ADMIN_ID = '01JADMIN00000000000000001A'
const admin = () => apiUser({ role: 'admin', id: ADMIN_ID, handle: 'sofia' })
const reviewer = () => apiUser({ role: 'reviewer', id: '01JREVIEWER000000000000001', handle: 'kenji' })

const members = () => [
  adminMember({ id: ADMIN_ID, handle: 'sofia', name: 'Sofia', role: 'admin' }),
  adminMember({ id: '01JMEMBER0000000000000001A', handle: 'amina', name: 'Amina', email: 'amina@example.com' }),
  adminMember({ id: '01JMEMBER0000000000000001B', handle: 'spammer', name: 'Spammer', is_suspended: true, suspended_reason: 'Vote ring', risk_score: 90 }),
  adminMember({ id: '01JMEMBER0000000000000001C', handle: 'lee', name: 'Lee', role: 'reviewer' }),
]
const base = (extra: Record<string, unknown> = {}) => ({
  ...layoutRoutes(),
  'GET /admin/users': json(page(members())),
  'GET /staff/risk/members': json(page([])),
  ...extra,
})

describe('members', () => {
  it('lists the directory with e-mail, role and suspension', async () => {
    renderApp({ path: '/staff/members', user: admin(), routes: base() })
    expect(await screen.findByText('Amina')).toBeTruthy()
    expect(screen.getByText(/@amina · amina@example.com/)).toBeTruthy()
    expect(screen.getByText('Suspended: Vote ring')).toBeTruthy()
    expect(screen.getByText('You')).toBeTruthy()
  })

  it('is not there for a reviewer and nothing is requested', async () => {
    const { api } = renderApp({ path: '/staff/members', user: reviewer(), routes: layoutRoutes() })
    expect(await screen.findByText('Not allowed')).toBeTruthy()
    expect(api.callsTo('GET /admin/users')).toHaveLength(0)
  })

  it('shows not allowed when the API answers 403 anyway', async () => {
    renderApp({ path: '/staff/members', user: admin(), routes: base({ 'GET /admin/users': apiError(403, 'no') }) })
    expect(await screen.findByText('Not allowed')).toBeTruthy()
  })

  it('maps the tabs, role and search to query parameters and keeps the search above two characters', async () => {
    const { api } = renderApp({ path: '/staff/members', user: admin(), routes: base() })
    await screen.findByText('Amina')

    fireEvent.click(screen.getByRole('tab', { name: 'Suspended' }))
    await waitFor(() => expect(api.callsTo('GET /admin/users').some((c) => c.query.get('status') === 'suspended')).toBe(true))
    fireEvent.change(screen.getByLabelText('Filter by role'), { target: { value: 'reviewer' } })
    await waitFor(() => expect(api.callsTo('GET /admin/users').some((c) => c.query.get('role') === 'reviewer' && c.query.get('status') === 'suspended')).toBe(true))
    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'sp' } })
    await waitFor(() => expect(api.callsTo('GET /admin/users').some((c) => c.query.get('q') === 'sp')).toBe(true))
  })

  it('loads more by cursor', async () => {
    const { api } = renderApp({
      path: '/staff/members',
      user: admin(),
      routes: base({ 'GET /admin/users': ((call) => (call.query.get('cursor') === 'c2' ? json(page([adminMember({ name: 'Second Page' })])) : json(page(members(), 'c2')))) as Handler }),
    })
    fireEvent.click(await screen.findByRole('button', { name: 'Load more' }))
    expect(await screen.findByText('Second Page')).toBeTruthy()
    expect(api.callsTo('GET /admin/users').some((c) => c.query.get('cursor') === 'c2')).toBe(true)
  })

  it('links an admin to the abuse suspension on the risk page', async () => {
    renderApp({ path: '/staff/members', user: admin(), routes: base() })
    const row = (await screen.findByText('Amina')).closest('li')!
    expect(within(row).getByRole('link', { name: 'Suspend for abuse (void votes)' }).getAttribute('href')).toBe('/staff/risk/members/01JMEMBER0000000000000001A')
  })

  it('offers no moderation for yourself or another admin', async () => {
    renderApp({ path: '/staff/members', user: admin(), routes: base() })
    const row = (await screen.findByText('Sofia')).closest('li')!
    expect(within(row).queryByRole('button')).toBeNull()
  })

  it('suspends with a required reason and shows the row suspended', async () => {
    const { api } = renderApp({
      path: '/staff/members',
      user: admin(),
      routes: base({
        'POST /admin/users/:handle/suspend': json({ data: { id: '01JMEMBER0000000000000001A', handle: 'amina', is_suspended: true, suspended_at: '2026-10-22T10:00:00+00:00', suspended_reason: 'Spam comments' } }),
      }),
    })
    const row = (await screen.findByText('Amina')).closest('li')!
    fireEvent.click(within(row).getByRole('button', { name: /Suspend/ }))
    const dialog = await screen.findByRole('dialog')

    fireEvent.click(within(dialog).getByRole('button', { name: 'Suspend' }))
    expect(await within(dialog).findByText('Add a reason before continuing.')).toBeTruthy()
    expect(api.callsTo('POST /admin/users/:handle/suspend')).toHaveLength(0)

    fireEvent.change(within(dialog).getByRole('textbox', { name: 'Reason' }), { target: { value: 'Spam comments' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Suspend' }))

    await waitFor(() => expect(api.callsTo('POST /admin/users/:handle/suspend')).toHaveLength(1))
    expect(api.callsTo('POST /admin/users/:handle/suspend')[0].path).toBe('/admin/users/amina/suspend')
    expect(api.callsTo('POST /admin/users/:handle/suspend')[0].body).toEqual({ reason: 'Spam comments' })
    expect(await screen.findByText('Suspended: Spam comments')).toBeTruthy()
    expect(within((await screen.findByText('Amina')).closest('li')!).getByRole('button', { name: /Restore/ })).toBeTruthy()
  })

  it('shows cannot_suspend, 422 and 429 in the dialog', async () => {
    const { api } = renderApp({ path: '/staff/members', user: admin(), routes: base({ 'POST /admin/users/:handle/suspend': apiError(403, 'x', { code: 'cannot_suspend' }) }) })
    const row = (await screen.findByText('Amina')).closest('li')!
    fireEvent.click(within(row).getByRole('button', { name: /Suspend/ }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByRole('textbox', { name: 'Reason' }), { target: { value: 'Because' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Suspend' }))
    expect(await within(dialog).findByText(/cannot be suspended/)).toBeTruthy()

    api.set('POST /admin/users/:handle/suspend', validation({ reason: ['The reason may not be greater than 500 characters.'] }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Suspend' }))
    expect(await within(dialog).findByText(/may not be greater than 500/)).toBeTruthy()

    api.set('POST /admin/users/:handle/suspend', apiError(429, 'x', {}, { 'Retry-After': '30' }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Suspend' }))
    expect(await within(dialog).findByText(/Try again in 30 s/)).toBeTruthy()
  })

  it('restores a suspended member without a reason', async () => {
    const { api } = renderApp({
      path: '/staff/members',
      user: admin(),
      routes: base({ 'POST /admin/users/:handle/restore': json({ data: { id: '01JMEMBER0000000000000001B', handle: 'spammer', is_suspended: false, suspended_at: null, suspended_reason: null } }) }),
    })
    const row = (await screen.findByText('Spammer')).closest('li')!
    fireEvent.click(within(row).getByRole('button', { name: /Restore/ }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Restore access' }))
    await waitFor(() => expect(api.callsTo('POST /admin/users/:handle/restore')).toHaveLength(1))
    expect(api.callsTo('POST /admin/users/:handle/restore')[0].path).toBe('/admin/users/spammer/restore')
    await waitFor(() => expect(screen.queryByText('Suspended: Vote ring')).toBeNull())
  })

  it('changes a role', async () => {
    const { api } = renderApp({
      path: '/staff/members',
      user: admin(),
      routes: base({ 'PATCH /admin/users/:handle/role': json({ data: { id: '01JMEMBER0000000000000001A', handle: 'amina', name: 'Amina', email: 'amina@example.com', role: 'reviewer', created_at: null } }) }),
    })
    const row = (await screen.findByText('Amina')).closest('li')!
    fireEvent.click(within(row).getByRole('button', { name: 'Make reviewer' }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Make reviewer' }))
    await waitFor(() => expect(api.callsTo('PATCH /admin/users/:handle/role')).toHaveLength(1))
    expect(api.callsTo('PATCH /admin/users/:handle/role')[0].body).toEqual({ role: 'reviewer' })
    await waitFor(() => expect(within((screen.getByText('Amina')).closest('li')!).getByRole('button', { name: 'Make member' })).toBeTruthy())
  })

  it('shows a refused role change in the dialog', async () => {
    renderApp({ path: '/staff/members', user: admin(), routes: base({ 'PATCH /admin/users/:handle/role': apiError(403, 'This action is unauthorized.') }) })
    const row = (await screen.findByText('Amina')).closest('li')!
    fireEvent.click(within(row).getByRole('button', { name: 'Make reviewer' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Make reviewer' }))
    expect(await within(dialog).findByText(/not allowed to do that/)).toBeTruthy()
  })

  it('lists members above the risk threshold with a link to their summary', async () => {
    renderApp({ path: '/staff/members', user: admin(), routes: base({ 'GET /staff/risk/members': json(page([riskMember()])) }) })
    const section = await screen.findByRole('region', { name: 'Suspicious voting' })
    expect(within(section).getByText('@badactor')).toBeTruthy()
    expect(within(section).getByRole('link', { name: /badactor/ }).getAttribute('href')).toBe('/staff/risk/members/01JBADMEM00000000000000001')
  })
})
