import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { MeDto } from '@/api/types'
import { apiError, json, noContent, validation, type Handler, type MockResult } from '../helpers/mockApi'
import { renderApp } from '../helpers/renderApp'
import { apiLink, apiPrefs, apiUser } from '../fixtures/user'
import { installFakeXhr } from '../helpers/fakeXhr'

beforeEach(() => installFakeXhr())
afterEach(() => vi.unstubAllGlobals())

const STRONG = 'correct-horse-battery-9!'

/** A `/me` that PATCH/avatar/email calls can change, like the real one. */
function meRoutes(initial: MeDto) {
  let me = initial
  return {
    set: (patch: Partial<MeDto>) => (me = { ...me, ...patch }),
    routes: { 'GET /me': (() => json({ data: me })) as Handler },
  }
}

const deferred = () => {
  let resolve!: (v: MockResult) => void
  const promise = new Promise<MockResult>((r) => (resolve = r))
  return { promise, resolve }
}

describe('profile tab', () => {
  it('saves only the fields that changed and shows the server value', async () => {
    const me = meRoutes(apiUser())
    const { api } = renderApp({
      path: '/settings',
      user: apiUser(),
      routes: {
        ...me.routes,
        'PATCH /me': (call) => {
          me.set(call.body as Partial<MeDto>)
          return json({ data: apiUser({ headline: 'Design lead' }) })
        },
      },
    })

    fireEvent.change(await screen.findByLabelText('Headline'), { target: { value: 'Design lead' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() => expect(api.callsTo('PATCH /me')).toHaveLength(1))
    expect(api.callsTo('PATCH /me')[0].body).toEqual({ headline: 'Design lead' })
    await waitFor(() => expect((screen.getByLabelText('Headline') as HTMLInputElement).value).toBe('Design lead'))
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull())
  })

  it('sends links as {kind, label, url} with the kind read from the URL', async () => {
    const { api } = renderApp({ path: '/settings', user: apiUser(), routes: { 'PATCH /me': json({ data: apiUser() }) } })

    fireEvent.click(await screen.findByRole('button', { name: /Add link/ }))
    fireEvent.change(screen.getByLabelText('Link 1 label'), { target: { value: 'Code' } })
    fireEvent.change(screen.getByLabelText('Link 1 URL'), { target: { value: 'https://github.com/amina' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() => expect(api.callsTo('PATCH /me')).toHaveLength(1))
    expect(api.callsTo('PATCH /me')[0].body).toEqual({ links: [{ kind: 'github', label: 'Code', url: 'https://github.com/amina' }] })
  })

  it('refuses a non-https link in the browser, without a request', async () => {
    const { api } = renderApp({ path: '/settings', user: apiUser() })

    fireEvent.click(await screen.findByRole('button', { name: /Add link/ }))
    fireEvent.change(screen.getByLabelText('Link 1 label'), { target: { value: 'Site' } })
    fireEvent.change(screen.getByLabelText('Link 1 URL'), { target: { value: 'http://amina.dev' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    expect(await screen.findByText('Use a full URL starting with https://')).toBeTruthy()
    expect(api.callsTo('PATCH /me')).toHaveLength(0)
  })

  it('shows 422 messages on the handle input and on the right link row', async () => {
    renderApp({
      path: '/settings',
      user: apiUser({ links: [apiLink({ label: 'Site', url: 'https://amina.dev' })] }),
      routes: { 'PATCH /me': validation({ handle: ['This handle is already taken.'], 'links.0.url': ['This URL is not allowed.'] }) },
    })

    fireEvent.change(await screen.findByLabelText('Handle'), { target: { value: 'taken_one' } })
    fireEvent.change(screen.getByLabelText('Link 1 URL'), { target: { value: 'https://blocked.example.com' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    expect(await screen.findByText('This handle is already taken.')).toBeTruthy()
    expect(screen.getByText('This URL is not allowed.')).toBeTruthy()
  })

  it('shows a banner with resend and disables saving and photo changes while unverified', async () => {
    const { api } = renderApp({ path: '/settings', user: apiUser({ email_verified: false }), routes: { 'POST /auth/email/resend': noContent() } })

    expect(await screen.findByText(/Confirm your email/)).toBeTruthy()
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Amina R' } })
    expect((screen.getByRole('button', { name: 'Save changes' }) as HTMLButtonElement).disabled).toBe(true)
    expect((screen.getByRole('button', { name: /Upload photo/ }) as HTMLButtonElement).disabled).toBe(true)

    fireEvent.click(screen.getByRole('button', { name: 'Resend link' }))
    await waitFor(() => expect(api.callsTo('POST /auth/email/resend')).toHaveLength(1))
  })

  it('opens the verify prompt when the API answers email_unverified', async () => {
    renderApp({ path: '/settings', user: apiUser(), routes: { 'PATCH /me': apiError(403, 'Confirm your email address first.', { code: 'email_unverified' }) } })

    fireEvent.change(await screen.findByLabelText('Name'), { target: { value: 'Amina R' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    expect(await screen.findByRole('heading', { name: 'Verify your email' })).toBeTruthy()
  })

  it('uploads a photo and removes it again', async () => {
    const me = meRoutes(apiUser())
    const { api } = renderApp({
      path: '/settings',
      user: apiUser(),
      routes: {
        ...me.routes,
        'POST /me/avatar': () => {
          me.set({ avatar_url: 'http://api.test/storage/avatars/a.webp' })
          return json({ data: apiUser({ avatar_url: 'http://api.test/storage/avatars/a.webp' }) })
        },
        'DELETE /me/avatar': () => {
          me.set({ avatar_url: null })
          return noContent()
        },
      },
    })
    const input = (await screen.findByLabelText('Upload photo')) as HTMLInputElement

    fireEvent.change(input, { target: { files: [new File(['x'], 'me.png', { type: 'image/png' })] } })

    await waitFor(() => expect(api.callsTo('POST /me/avatar')).toHaveLength(1))
    expect(api.callsTo('POST /me/avatar')[0].form?.get('avatar')).toBeInstanceOf(Blob)
    fireEvent.click(await screen.findByRole('button', { name: /Remove/ }))
    await waitFor(() => expect(api.callsTo('DELETE /me/avatar')).toHaveLength(1))
    await waitFor(() => expect(screen.queryByRole('button', { name: /Remove/ })).toBeNull())
  })

  it('shows upload progress on the button', async () => {
    const gate = deferred()
    renderApp({ path: '/settings', user: apiUser(), routes: { 'POST /me/avatar': () => gate.promise } })
    const input = (await screen.findByLabelText('Upload photo')) as HTMLInputElement

    fireEvent.change(input, { target: { files: [new File(['x'], 'me.png', { type: 'image/png' })] } })

    expect(await screen.findByRole('button', { name: /Uploading 50%/ })).toBeTruthy()
    gate.resolve(json({ data: apiUser() }))
    await waitFor(() => expect(screen.queryByRole('button', { name: /Uploading/ })).toBeNull())
  })

  it('rejects a wrong file type or size before uploading, and shows the server verdict otherwise', async () => {
    const { api } = renderApp({ path: '/settings', user: apiUser(), routes: { 'POST /me/avatar': validation({ avatar: ['The avatar must be an image at most 4000 pixels wide.'] }) } })
    const input = (await screen.findByLabelText('Upload photo')) as HTMLInputElement

    fireEvent.change(input, { target: { files: [new File(['x'], 'doc.pdf', { type: 'application/pdf' })] } })
    expect(await screen.findByText('Use a JPEG, PNG or WebP image.')).toBeTruthy()
    expect(api.callsTo('POST /me/avatar')).toHaveLength(0)

    const big = new File([new Uint8Array(2 * 1024 * 1024 + 1)], 'big.png', { type: 'image/png' })
    fireEvent.change(input, { target: { files: [big] } })
    expect(await screen.findByText('The photo must be 2 MB or smaller.')).toBeTruthy()

    fireEvent.change(input, { target: { files: [new File(['x'], 'wide.png', { type: 'image/png' })] } })
    expect(await screen.findByText(/at most 4000 pixels wide/)).toBeTruthy()
  })
})

describe('account tab', () => {
  const path = '/settings?tab=account'

  it('hides the password form for a Google-only account', async () => {
    renderApp({ path, user: apiUser({ has_password: false }) })

    expect(await screen.findByText('You sign in with Google')).toBeTruthy()
    expect(screen.queryByLabelText('Current password')).toBeNull()
  })

  it('changes the password and puts a wrong current password on its input', async () => {
    let wrong = true
    const { api } = renderApp({
      path,
      user: apiUser(),
      routes: { 'PUT /me/password': () => (wrong ? validation({ current_password: ['The current password is incorrect.'] }) : noContent()) },
    })
    const fill = async (current: string) => {
      fireEvent.change(await screen.findByLabelText('Current password'), { target: { value: current } })
      fireEvent.change(screen.getByLabelText('New password'), { target: { value: STRONG } })
      fireEvent.change(screen.getByLabelText('Confirm new password'), { target: { value: STRONG } })
      fireEvent.click(screen.getByRole('button', { name: 'Update password' }))
    }

    await fill('old-password-123')
    expect(await screen.findByText('The current password is incorrect.')).toBeTruthy()

    wrong = false
    fireEvent.click(screen.getByRole('button', { name: 'Update password' }))
    expect(await screen.findByText('Password changed')).toBeTruthy()
    expect(api.callsTo('PUT /me/password').at(-1)?.body).toEqual({ current_password: 'old-password-123', password: STRONG })
  })

  it('requests an email change and shows the pending banner', async () => {
    const me = meRoutes(apiUser())
    const { api } = renderApp({
      path,
      user: apiUser(),
      routes: {
        ...me.routes,
        'POST /me/email': () => {
          me.set({ pending_email: 'new@example.com' })
          return json({ message: 'Check the new address for a confirmation link.' }, 202)
        },
      },
    })

    fireEvent.click(await screen.findByRole('button', { name: 'Change email' }))
    fireEvent.change(screen.getByLabelText('New email address'), { target: { value: 'New@Example.com' } })
    const form = screen.getByLabelText('New email address').closest('form') as HTMLElement
    fireEvent.change(within(form).getByLabelText('Current password'), { target: { value: 'my-password-1234' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send confirmation' }))

    expect(await screen.findByText('new@example.com')).toBeTruthy()
    expect(api.callsTo('POST /me/email')[0].body).toEqual({ email: 'New@Example.com', current_password: 'my-password-1234' })
    expect(screen.getByText(/Waiting for you to confirm/)).toBeTruthy()
  })

  it('shows the taken-address error on the email input', async () => {
    renderApp({ path, user: apiUser(), routes: { 'POST /me/email': validation({ email: ['The email has already been taken.'] }) } })

    fireEvent.click(await screen.findByRole('button', { name: 'Change email' }))
    fireEvent.change(screen.getByLabelText('New email address'), { target: { value: 'taken@example.com' } })
    const form = screen.getByLabelText('New email address').closest('form') as HTMLElement
    fireEvent.change(within(form).getByLabelText('Current password'), { target: { value: 'my-password-1234' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send confirmation' }))

    expect(await screen.findByText('The email has already been taken.')).toBeTruthy()
  })

  it('asks a Google-only member to sign in again when the API wants a recent login', async () => {
    renderApp({ path, user: apiUser({ has_password: false }), routes: { 'POST /me/email': apiError(403, 'Please sign in again to continue.', { code: 'reauth_required' }) } })

    fireEvent.click(await screen.findByRole('button', { name: 'Change email' }))
    fireEvent.change(screen.getByLabelText('New email address'), { target: { value: 'new@example.com' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send confirmation' }))

    expect((await screen.findByRole('alert')).textContent).toContain('sign out and sign in again')
  })
})

describe('account tab, unverified', () => {
  it('shows the banner and will not start an email change', async () => {
    renderApp({ path: '/settings?tab=account', user: apiUser({ email_verified: false }) })

    expect(await screen.findByText(/Confirm your email/)).toBeTruthy()
    expect((screen.getByRole('button', { name: 'Change email' }) as HTMLButtonElement).disabled).toBe(true)
  })
})

describe('delete account', () => {
  const path = '/settings?tab=account'
  const open = async () => {
    fireEvent.click(await screen.findByRole('button', { name: /Delete account/ }))
    return screen.findByRole('dialog')
  }
  const confirm = (dialog: HTMLElement) => fireEvent.click(within(dialog).getByRole('button', { name: /Delete account/ }))

  it('asks for the password first and does nothing until confirmed', async () => {
    const { api } = renderApp({ path, user: apiUser() })

    const dialog = await open()

    expect(within(dialog).getByText('Delete your account?')).toBeTruthy()
    expect((within(dialog).getByRole('button', { name: /Delete account/ }) as HTMLButtonElement).disabled).toBe(true)
    expect(api.callsTo('DELETE /me')).toHaveLength(0)
  })

  it('deletes with the password and leaves the site as a guest', async () => {
    let gone = false
    const { api, router } = renderApp({
      path,
      user: apiUser(),
      routes: {
        'GET /me': () => (gone ? apiError(401, 'Unauthenticated.') : json({ data: apiUser() })),
        'DELETE /me': () => {
          gone = true
          return noContent()
        },
      },
    })
    const dialog = await open()

    fireEvent.change(within(dialog).getByLabelText('Confirm with your password'), { target: { value: 'my-password-1234' } })
    confirm(dialog)

    await waitFor(() => expect(router.state.location.pathname).toBe('/'))
    expect(api.callsTo('DELETE /me')[0].body).toEqual({ current_password: 'my-password-1234' })
    expect(await screen.findByRole('link', { name: 'Sign in' })).toBeTruthy()
  })

  it('shows a wrong password on the input', async () => {
    renderApp({ path, user: apiUser(), routes: { 'DELETE /me': validation({ current_password: ['The current password is incorrect.'] }) } })
    const dialog = await open()

    fireEvent.change(within(dialog).getByLabelText('Confirm with your password'), { target: { value: 'nope-nope-nope' } })
    confirm(dialog)

    expect(await within(dialog).findByText('The current password is incorrect.')).toBeTruthy()
  })

  it('asks a Google-only member to sign in again (reauth_required)', async () => {
    renderApp({ path, user: apiUser({ has_password: false }), routes: { 'DELETE /me': apiError(403, 'Please sign in again to continue.', { code: 'reauth_required' }) } })
    const dialog = await open()

    confirm(dialog)

    expect(await within(dialog).findByText('Please sign in again to continue.')).toBeTruthy()
    expect(within(dialog).getByRole('button', { name: 'Sign in again' })).toBeTruthy()
  })

  it('explains an account that still has a live product', async () => {
    renderApp({ path, user: apiUser(), routes: { 'DELETE /me': apiError(403, 'Withdraw or finish your launches before deleting your account.', { code: 'has_active_product' }) } })
    const dialog = await open()

    fireEvent.change(within(dialog).getByLabelText('Confirm with your password'), { target: { value: 'my-password-1234' } })
    confirm(dialog)

    expect(await within(dialog).findByText('Withdraw or finish your launches before deleting your account.')).toBeTruthy()
  })
})

describe('email tab', () => {
  const path = '/settings?tab=email'

  it('moves the switch at once and keeps it when the API agrees', async () => {
    const gate = deferred()
    let current = apiPrefs()
    const { api } = renderApp({
      path,
      user: apiUser(),
      routes: { 'GET /me/email-preferences': () => json(current), 'PATCH /me/email-preferences': () => gate.promise },
    })
    const toggle = await screen.findByRole('switch', { name: 'Daily top 5' })
    expect(toggle.getAttribute('aria-checked')).toBe('true')

    fireEvent.click(toggle)

    // optimistic: flipped before the server answered
    await waitFor(() => expect(screen.getByRole('switch', { name: 'Daily top 5' }).getAttribute('aria-checked')).toBe('false'))
    current = apiPrefs({ daily_top5: false })
    gate.resolve(json(current))
    await waitFor(() => expect(api.callsTo('PATCH /me/email-preferences')).toHaveLength(1))
    expect(api.callsTo('PATCH /me/email-preferences')[0].body).toEqual({ preferences: { daily_top5: false } })
    expect(screen.getByRole('switch', { name: 'Daily top 5' }).getAttribute('aria-checked')).toBe('false')
  })

  it('rolls the switch back and says so when the API refuses', async () => {
    renderApp({ path, user: apiUser(), routes: { 'GET /me/email-preferences': json(apiPrefs()), 'PATCH /me/email-preferences': apiError(500, 'boom') } })
    const toggle = await screen.findByRole('switch', { name: 'Weekly best' })

    fireEvent.click(toggle)

    expect(await screen.findByText('Could not save that setting')).toBeTruthy()
    await waitFor(() => expect(screen.getByRole('switch', { name: 'Weekly best' }).getAttribute('aria-checked')).toBe('true'))
  })

  it('rolls back only the failed key when two toggles overlap', async () => {
    const first = deferred()
    const second = deferred()
    let n = 0
    renderApp({
      path,
      user: apiUser(),
      routes: { 'GET /me/email-preferences': () => json(apiPrefs()), 'PATCH /me/email-preferences': () => (++n === 1 ? first.promise : second.promise) },
    })
    fireEvent.click(await screen.findByRole('switch', { name: 'Daily top 5' }))
    await waitFor(() => expect(screen.getByRole('switch', { name: 'Daily top 5' }).getAttribute('aria-checked')).toBe('false'))
    fireEvent.click(screen.getByRole('switch', { name: 'Weekly best' }))
    await waitFor(() => expect(screen.getByRole('switch', { name: 'Weekly best' }).getAttribute('aria-checked')).toBe('false'))

    first.resolve(apiError(500, 'boom'))

    await waitFor(() => expect(screen.getByRole('switch', { name: 'Daily top 5' }).getAttribute('aria-checked')).toBe('true'))
    expect(screen.getByRole('switch', { name: 'Weekly best' }).getAttribute('aria-checked')).toBe('false')
    second.resolve(json(apiPrefs({ weekly_best: false })))
  })

  it('reflects switched-off types from the API and offers Retry when loading fails', async () => {
    let fail = true
    renderApp({
      path,
      user: apiUser(),
      routes: { 'GET /me/email-preferences': () => (fail ? apiError(500, 'boom') : json(apiPrefs({ new_comment: false }))) },
    })

    fireEvent.click(await screen.findByRole('button', { name: /Try again/ }))
    fail = false
    // the first click happened against the failing route; click again once it recovers
    fireEvent.click(await screen.findByRole('button', { name: /Try again/ }))

    expect((await screen.findByRole('switch', { name: 'New comments' })).getAttribute('aria-checked')).toBe('false')
  })
})
