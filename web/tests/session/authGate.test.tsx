import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import { AppProviders } from '@/AppProviders'
import { AuthGateProvider, useAuthGate } from '@/components/layout/AuthGate'
import { createQueryClient } from '@/lib/queryClient'
import { apiError, json, mockApi, noContent } from '../helpers/mockApi'
import { apiUser } from '../fixtures/user'
import type { MeDto } from '@/api/types'

function Action({ run }: { run: () => void }) {
  const gate = useAuthGate()
  return <button onClick={() => gate('vote on launches', run)}>vote</button>
}

function setup(user: MeDto | null, extra: Parameters<typeof mockApi>[0] = {}) {
  const run = vi.fn()
  const api = mockApi({ 'GET /me': user ? json({ data: user }) : apiError(401, 'Unauthenticated.'), ...extra })
  render(
    <AppProviders client={createQueryClient({ retry: false })}>
      <MemoryRouter>
        <AuthGateProvider>
          <Action run={run} />
        </AuthGateProvider>
      </MemoryRouter>
    </AppProviders>,
  )
  return { run, api }
}

describe('AuthGate', () => {
  it('runs the action for a verified member', async () => {
    const { run } = setup(apiUser())
    await screen.findByText('vote')
    await vi.waitFor(() => {
      fireEvent.click(screen.getByText('vote'))
      expect(run).toHaveBeenCalled()
    })
  })

  it('shows the sign-in prompt to a guest and does not run the action', async () => {
    const { run } = setup(null)
    await vi.waitFor(() => {
      fireEvent.click(screen.getByText('vote'))
      expect(screen.getByText(/Sign in to vote on launches/)).toBeTruthy()
    })
    expect(run).not.toHaveBeenCalled()
  })

  it('asks an unverified member to verify and can resend the link', async () => {
    const { run, api } = setup(apiUser({ email_verified: false }), { 'POST /auth/email/resend': noContent() })
    await vi.waitFor(() => {
      fireEvent.click(screen.getByText('vote'))
      expect(screen.getByText('Verify your email')).toBeTruthy()
    })
    expect(run).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: 'Resend link' }))

    expect(await screen.findByText('Link sent')).toBeTruthy()
    expect(api.callsTo('POST /auth/email/resend')).toHaveLength(1)
  })
})
