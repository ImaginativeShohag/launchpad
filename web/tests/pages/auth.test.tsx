import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { resetEmailedLinks } from '@/lib/useStripQuery'
import { apiError, json, noContent, validation } from '../helpers/mockApi'
import { renderApp } from '../helpers/renderApp'
import { apiUser } from '../fixtures/user'

vi.mock('@/lib/leaveSite', () => ({ leaveSite: vi.fn() }))
import { leaveSite } from '@/lib/leaveSite'

const STRONG = 'correct-horse-battery-9!'

beforeEach(() => vi.mocked(leaveSite).mockClear())

/** `/me` is a guest until the login route ran. */
function loginRoutes(user = apiUser()) {
  let signedIn = false
  return {
    'GET /me/notifications/unread-count': json({ unread_count: 0, capped: false }),
    'GET /me': () => (signedIn ? json({ data: user }) : apiError(401, 'Unauthenticated.')),
    'POST /auth/login': () => {
      signedIn = true
      return json({ data: user })
    },
  }
}

const fillSignIn = async (email = 'amina@example.com', password = STRONG) => {
  fireEvent.change(await screen.findByLabelText('Email'), { target: { value: email } })
  fireEvent.change(screen.getByLabelText('Password'), { target: { value: password } })
  fireEvent.click(screen.getByRole('button', { name: /^Sign in$/ }))
}

describe('sign in', () => {
  it('signs in, refetches the session and returns to the page the member wanted', async () => {
    const { api, router } = renderApp({ path: '/signin?next=%2Fsettings', routes: loginRoutes() })

    await fillSignIn()

    await waitFor(() => expect(router.state.location.pathname).toBe('/settings'))
    expect(api.callsTo('POST /auth/login')[0].body).toEqual({ email: 'amina@example.com', password: STRONG, remember: false })
    expect(api.callsTo('GET /me').length).toBeGreaterThanOrEqual(2)
    expect(await screen.findByRole('heading', { name: 'Settings' })).toBeTruthy()
  })

  it.each([true, false])('sends remember=%s after toggling the checkbox', async (remember) => {
    // Given a sign-in form with remember me off by default
    const { api, router } = renderApp({ path: '/signin', routes: loginRoutes() })
    const checkbox = await screen.findByRole('checkbox', { name: 'Remember me' })
    expect((checkbox as HTMLInputElement).checked).toBe(false)

    // When the member checks it (and optionally unchecks it) before signing in
    fireEvent.click(checkbox)
    if (!remember) fireEvent.click(checkbox)
    await fillSignIn()

    // Then the choice reaches the login API through the session provider
    await waitFor(() => expect(router.state.location.pathname).toBe('/'))
    expect(api.callsTo('POST /auth/login')[0].body).toEqual({ email: 'amina@example.com', password: STRONG, remember })
  })

  it('shows the API message on the email input for a wrong password', async () => {
    renderApp({ path: '/signin', routes: { 'POST /auth/login': validation({ email: ['These credentials do not match our records.'] }) } })

    await fillSignIn('amina@example.com', 'wrong-password-123')

    const message = await screen.findByText('These credentials do not match our records.')
    expect(within(message.closest('div.space-y-1\\.5') as HTMLElement).getByLabelText('Email')).toBeTruthy()
  })

  it('ignores a next that leaves the site', async () => {
    const { router } = renderApp({ path: '/signin?next=%2F%2Fevil.com', routes: loginRoutes() })

    await fillSignIn()

    await waitFor(() => expect(router.state.location.pathname).toBe('/'))
    expect(router.state.location.search).toBe('')
  })

  it('says how long to wait after a 429 and disables the button', async () => {
    renderApp({ path: '/signin', routes: { 'POST /auth/login': apiError(429, 'Too Many Attempts.', {}, { 'Retry-After': '30' }) } })

    await fillSignIn()

    expect((await screen.findByRole('alert')).textContent).toContain('Try again in 30 s')
    const button = screen.getByRole('button', { name: /Try again in/ })
    expect((button as HTMLButtonElement).disabled).toBe(true)
  })

  it.each(['__proto__', 'constructor', 'toString'])('shows a generic message for ?error=%s', async (code) => {
    renderApp({ path: `/signin?error=${code}` })
    expect((await screen.findByRole('alert')).textContent).toContain('Google sign-in did not work')
  })

  it('sends a signed-in visitor straight on', async () => {
    const { router } = renderApp({ path: '/signin?next=%2Ffeed', user: apiUser() })
    await waitFor(() => expect(router.state.location.pathname).toBe('/feed'))
  })

  it('explains a Google error code and starts the Google flow from the button', async () => {
    renderApp({ path: '/signin?error=google_identity_mismatch' })

    expect((await screen.findByRole('alert')).textContent).toContain('different Google identity')
    fireEvent.click(screen.getByRole('button', { name: /Continue with Google/ }))

    expect(leaveSite).toHaveBeenCalledWith('http://api.test/api/v1/auth/google/redirect')
  })
})

describe('sign up', () => {
  const fill = async (over: Partial<Record<'Full name' | 'Email' | 'Handle' | 'Password', string>> = {}) => {
    const v = { 'Full name': 'Ada Lovelace', Email: 'ada@example.com', Handle: '', Password: STRONG, ...over }
    fireEvent.change(await screen.findByLabelText('Full name'), { target: { value: v['Full name'] } })
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: v.Email } })
    if (v.Handle) fireEvent.change(screen.getByLabelText('Handle'), { target: { value: v.Handle } })
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: v.Password } })
    fireEvent.click(screen.getByRole('button', { name: /Create account/ }))
  }

  it('registers with a handle suggested from the name and lands on the inbox screen', async () => {
    let created = false
    const { api, router } = renderApp({
      path: '/signup',
      routes: {
        'GET /me': () => (created ? json({ data: apiUser({ email_verified: false, email: 'ada@example.com', name: 'Ada Lovelace' }) }) : apiError(401, 'Unauthenticated.')),
        'POST /auth/register': () => {
          created = true
          return json({ data: apiUser({ email_verified: false, email: 'ada@example.com', name: 'Ada Lovelace' }) }, 201)
        },
      },
    })

    await fill()

    await waitFor(() => expect(router.state.location.pathname).toBe('/verify-email'))
    expect(api.callsTo('POST /auth/register')[0].body).toEqual({ name: 'Ada Lovelace', email: 'ada@example.com', handle: 'ada_lovelace', password: STRONG })
    expect(await screen.findByText('Check your inbox')).toBeTruthy()
  })

  it('puts 422 messages under the right inputs', async () => {
    renderApp({
      path: '/signup',
      routes: { 'POST /auth/register': validation({ email: ['The email has already been taken.'], handle: ['This handle is already taken.'], password: ['The password has appeared in a data leak.'] }) },
    })

    await fill()

    expect(await screen.findByText('The email has already been taken.')).toBeTruthy()
    expect(screen.getByText('This handle is already taken.')).toBeTruthy()
    expect(screen.getByText('The password has appeared in a data leak.')).toBeTruthy()
    const emailBlock = screen.getByText('The email has already been taken.').closest('div.space-y-1\\.5') as HTMLElement
    expect(within(emailBlock).getByLabelText('Email')).toBeTruthy()
  })

  it('blocks a password the API would refuse (under 12 characters) without calling it', async () => {
    const { api } = renderApp({ path: '/signup' })

    await fill({ Password: 'short-1!' })

    expect(await screen.findByText(/at least 12 characters/)).toBeTruthy()
    expect(api.callsTo('POST /auth/register')).toHaveLength(0)
  })
})

describe('verify email', () => {
  const link = '?id=01JAMINA0000000000000000000&expires=1790000000&hash=abc123&signature=sig456'

  it('posts the emailed query verbatim, strips it from the address bar and shows the success', async () => {
    let verified = false
    const { api, router } = renderApp({
      path: `/verify-email${link}`,
      user: apiUser({ email_verified: false }),
      routes: {
        'GET /me': () => json({ data: apiUser({ email_verified: verified }) }),
        'POST /auth/email/verify': () => {
          verified = true
          return json({ data: apiUser({ email_verified: true }) })
        },
      },
    })

    expect(await screen.findByText(/verified/)).toBeTruthy()
    const posts = api.callsTo('POST /auth/email/verify')
    expect(posts).toHaveLength(1)
    expect(posts[0].search).toBe(link)
    expect(router.state.location.search).toBe('')
    expect(document.head.querySelector('meta[name="referrer"]')?.getAttribute('content')).toBe('no-referrer')
  })

  it('asks a guest to sign in and keeps the link so the member can come back to it', async () => {
    const { router } = renderApp({ path: `/verify-email${link}`, routes: { 'POST /auth/email/verify': apiError(401, 'Unauthenticated.') } })

    expect(await screen.findByText('Sign in to confirm')).toBeTruthy()
    expect(router.state.location.search).toBe(link)
    const href = screen.getByRole('link', { name: 'Sign in' }).getAttribute('href') as string
    expect(decodeURIComponent(href.split('next=')[1])).toBe(`/verify-email${link}`)
  })

  it('explains a link that is invalid, expired or for another account', async () => {
    renderApp({ path: `/verify-email${link}`, user: apiUser({ email_verified: false }), routes: { 'POST /auth/email/verify': apiError(403, 'Invalid signature.') } })

    expect(await screen.findByText('We couldn’t confirm that link')).toBeTruthy()
    expect(screen.getByText(/invalid, expired, or belongs to a different account/)).toBeTruthy()
  })

  it('without a link shows the inbox screen with a working, rate-limit-aware resend', async () => {
    let calls = 0
    const { api } = renderApp({
      path: '/verify-email',
      user: apiUser({ email_verified: false, email: 'amina@example.com' }),
      routes: { 'POST /auth/email/resend': () => (++calls === 1 ? noContent() : apiError(429, 'Too Many Attempts.', {}, { 'Retry-After': '45' })) },
    })

    fireEvent.click(await screen.findByRole('button', { name: /Resend link/ }))
    await waitFor(() => expect(api.callsTo('POST /auth/email/resend')).toHaveLength(1))
    expect(await screen.findByText(/Resend in/)).toBeTruthy()
    expect(screen.getByText('Check your inbox')).toBeTruthy()
  })

  it('tells a guest with no link that there is nothing in progress', async () => {
    renderApp({ path: '/verify-email' })
    expect(await screen.findByText('No account in progress')).toBeTruthy()
  })
})

describe('forgot password', () => {
  const submit = async (email: string) => {
    fireEvent.change(await screen.findByLabelText('Email'), { target: { value: email } })
    fireEvent.click(screen.getByRole('button', { name: /Send reset link/ }))
  }

  it('gives the same answer whatever the address', async () => {
    const { api, unmount } = renderApp({ path: '/forgot-password', routes: { 'POST /auth/password/forgot': json({ message: 'If that email has an account, a reset link is on its way.' }, 202) } })
    await submit('known@example.com')
    const first = (await screen.findByText(/a reset link is on its way/)).textContent?.replace(/.*account exists for [^,]*,/, '')
    unmount()

    renderApp({ path: '/forgot-password', routes: { 'POST /auth/password/forgot': json({ message: 'If that email has an account, a reset link is on its way.' }, 202) } })
    await submit('nobody@example.com')
    const second = (await screen.findByText(/a reset link is on its way/)).textContent?.replace(/.*account exists for [^,]*,/, '')

    expect(first).toBe(second)
    expect(api.callsTo('POST /auth/password/forgot')[0].body).toEqual({ email: 'known@example.com' })
  })

  it('does not call the API for something that is not an email', async () => {
    const { api } = renderApp({ path: '/forgot-password' })
    await submit('not-an-email')
    expect(await screen.findByText(/doesn’t look like an email/)).toBeTruthy()
    expect(api.callsTo('POST /auth/password/forgot')).toHaveLength(0)
  })

  it('shows a rate-limit message', async () => {
    renderApp({ path: '/forgot-password', routes: { 'POST /auth/password/forgot': apiError(429, 'Too Many Attempts.', {}, { 'Retry-After': '20' }) } })
    await submit('a@b.co')
    expect((await screen.findByRole('alert')).textContent).toContain('Try again in 20 s')
  })
})

describe('reset password', () => {
  const path = '/reset-password?token=tok-123&email=amina%40example.com'
  const fill = async () => {
    fireEvent.change(await screen.findByLabelText('New password'), { target: { value: STRONG } })
    fireEvent.change(screen.getByLabelText('Confirm new password'), { target: { value: STRONG } })
    fireEvent.click(screen.getByRole('button', { name: /Update password/ }))
  }

  it('posts email, token and the new password, and removes the secret from the URL', async () => {
    const { api, router } = renderApp({ path, routes: { 'POST /auth/password/reset': json({ message: 'Your password has been updated.' }) } })

    await fill()

    expect(await screen.findByText('Password updated')).toBeTruthy()
    expect(api.callsTo('POST /auth/password/reset')[0].body).toEqual({ email: 'amina@example.com', token: 'tok-123', password: STRONG })
    expect(router.state.location.search).toBe('')
  })

  it('says the link is dead when the API refuses the token', async () => {
    renderApp({ path, routes: { 'POST /auth/password/reset': validation({ email: ['This reset link is invalid or has expired.'] }) } })

    await fill()

    expect(await screen.findByText('This link doesn’t work')).toBeTruthy()
  })

  it('shows password rule errors on the input', async () => {
    renderApp({ path, routes: { 'POST /auth/password/reset': validation({ password: ['The password has appeared in a data leak.'] }) } })

    await fill()

    expect(await screen.findByText('The password has appeared in a data leak.')).toBeTruthy()
    expect(screen.queryByText('This link doesn’t work')).toBeNull()
  })

  it('treats a page without token and email as an invalid link', async () => {
    renderApp({ path: '/reset-password' })
    expect(await screen.findByText('This link doesn’t work')).toBeTruthy()
  })
})

describe('emailed links', () => {
  it('confirms an email change with the query verbatim, then asks to sign in again', async () => {
    const q = '?expires=1790000000&hash=h1&id=01JAMINA0000000000000000000&signature=s1'
    const { api, router } = renderApp({ path: `/confirm-email-change${q}`, routes: { 'POST /auth/email/change/confirm': json({ message: 'Your email address has been updated. Sign in again.' }) } })

    expect(await screen.findByText('Email updated')).toBeTruthy()
    expect(api.callsTo('POST /auth/email/change/confirm')).toHaveLength(1)
    expect(api.callsTo('POST /auth/email/change/confirm')[0].search).toBe(q)
    expect(router.state.location.search).toBe('')
  })

  it('reports a confirm link that is no longer valid', async () => {
    renderApp({ path: '/confirm-email-change?id=x&hash=y&signature=z', routes: { 'POST /auth/email/change/confirm': apiError(422, 'This link is invalid or has expired.') } })
    expect(await screen.findByText(/invalid or has expired/)).toBeTruthy()
  })

  it('cancels an email change and says so when nothing was left to cancel', async () => {
    const { unmount, api, router } = renderApp({ path: '/cancel-email-change?id=x&hash=y&signature=z', routes: { 'POST /auth/email/change/cancel': json({ message: 'The email change was cancelled.', code: 'cancelled' }) } })
    expect(await screen.findByText('Change cancelled')).toBeTruthy()
    expect(api.callsTo('POST /auth/email/change/cancel')).toHaveLength(1)
    expect(api.callsTo('POST /auth/email/change/cancel')[0].search).toBe('?id=x&hash=y&signature=z')
    expect(router.state.location.search).toBe('')
    unmount()
    resetEmailedLinks()

    renderApp({ path: '/cancel-email-change?id=x&hash=y&signature=z', routes: { 'POST /auth/email/change/cancel': json({ message: 'There is no pending email change.', code: 'no_pending_change' }) } })
    expect(await screen.findByText('Nothing to cancel')).toBeTruthy()
  })

  it('unsubscribes with the signed query as-is, without a session', async () => {
    const q = '?signature=sig&type=daily_top5&user=01JAMINA0000000000000000000'
    const { api, router } = renderApp({ path: `/unsubscribe${q}`, routes: { 'POST /email/unsubscribe': json({ type: 'daily_top5', enabled: false }) } })

    expect(await screen.findByText('You’re unsubscribed')).toBeTruthy()
    expect(screen.getByText(/daily top 5 emails/)).toBeTruthy()
    expect(api.callsTo('POST /email/unsubscribe')).toHaveLength(1)
    expect(api.callsTo('POST /email/unsubscribe')[0].search).toBe(q)
    expect(router.state.location.search).toBe('')
  })

  it('does not spend a one-time link twice when the page really remounts after the query was stripped', async () => {
    const q = '?signature=sig&type=daily_top5&user=01JAMINA0000000000000000000'
    const { api, router } = renderApp({ path: `/unsubscribe${q}`, routes: { 'POST /email/unsubscribe': json({ type: 'daily_top5', enabled: false }) } })
    await screen.findByText('You’re unsubscribed')

    await router.navigate('/forgot-password')
    await router.navigate('/unsubscribe')

    expect(await screen.findByText('You’re unsubscribed')).toBeTruthy()
    expect(api.callsTo('POST /email/unsubscribe')).toHaveLength(1)
  })

  it('rejects a forged unsubscribe link', async () => {
    renderApp({ path: '/unsubscribe?signature=bad&type=daily_top5&user=x', routes: { 'POST /email/unsubscribe': apiError(403, 'Invalid signature.') } })
    expect(await screen.findByText('This unsubscribe link is not valid.')).toBeTruthy()
  })
})

describe('route guard', () => {
  it('sends a guest from a members-only page to sign-in with a way back', async () => {
    const { router } = renderApp({ path: '/settings' })
    await waitFor(() => expect(router.state.location.pathname).toBe('/signin'))
    expect(router.state.location.search).toBe('?next=%2Fsettings')
  })

  it('shows a not-allowed wall for the wrong role', async () => {
    renderApp({ path: '/staff', user: apiUser() })
    expect(await screen.findByText('Not allowed')).toBeTruthy()
  })

  it('asks an unverified member to verify before /submit', async () => {
    renderApp({ path: '/submit', user: apiUser({ email_verified: false }) })
    expect(await screen.findByText('Verify your email first')).toBeTruthy()
  })
})
