import { describe, expect, it } from 'vitest'
import { inferLinkKind, toTopic, toUser } from '@/api/mappers'
import { hueFromId } from '@/lib/hue'
import { apiLink, apiTopic, apiUser, publicUser } from '../fixtures/user'

describe('toUser', () => {
  it('maps the signed-in member (UserResource)', () => {
    const user = toUser(apiUser({ links: [apiLink({ kind: 'github', label: null, url: 'https://github.com/amina' })], pending_email: 'new@example.com' }))

    expect(user).toMatchObject({
      id: '01JAMINA0000000000000000000',
      handle: 'amina_r',
      name: 'Amina Rahman',
      email: 'amina@example.com',
      emailVerified: true,
      pendingEmail: 'new@example.com',
      hasPassword: true,
      role: 'member',
      joinedAt: '2026-03-12',
      headline: 'Product designer',
    })
    expect(user.hue).toBe(hueFromId('01JAMINA0000000000000000000'))
    // A link without a label falls back to its kind's name
    expect(user.links).toEqual([{ kind: 'github', label: 'GitHub', url: 'https://github.com/amina' }])
  })

  it('treats a null headline, no avatar and no links as empty values', () => {
    const user = toUser(apiUser({ headline: null, avatar_url: null, links: undefined }))
    expect(user.headline).toBe('')
    expect(user.avatarUrl).toBeUndefined()
    expect(user.links).toEqual([])
  })

  it('reads a Google-only account as having no password', () => {
    expect(toUser(apiUser({ has_password: false })).hasPassword).toBe(false)
  })

  it('keeps the staff role', () => {
    expect(toUser(apiUser({ role: 'admin' })).role).toBe('admin')
  })

  it('maps a public profile with follow counts and a photo', () => {
    const user = toUser(publicUser({ is_following: true }))

    expect(user).toMatchObject({ handle: 'rafi', role: 'member', joinedAt: '2025-11-02', avatarUrl: 'http://api.test/storage/avatars/rafi.webp', followersCount: 12, followingCount: 3, isFollowing: true })
    expect(user.suspended).toBeUndefined()
    expect(user.email).toBeUndefined()
  })

  it('maps a suspended member and a guest view (no is_following)', () => {
    const suspended = toUser(publicUser({ is_suspended: true, headline: null, avatar_url: null, links: [], followers_count: undefined, following_count: undefined }))
    expect(suspended.suspended).toBe(true)
    expect(suspended.headline).toBe('')
    expect(suspended.isFollowing).toBeUndefined()
  })
})

describe('toTopic', () => {
  it('keeps the embedded fields and nulls become undefined', () => {
    expect(toTopic(apiTopic({ id: 't1', description: null }))).toEqual({ id: 't1', slug: 'ai', name: 'AI', emoji: '✨', description: undefined, position: 1 })
  })
})

describe('inferLinkKind', () => {
  it.each([
    ['https://github.com/a', 'github'],
    ['https://www.x.com/a', 'x'],
    ['https://twitter.com/a', 'x'],
    ['https://www.linkedin.com/in/a', 'linkedin'],
    ['https://youtu.be/abc', 'youtube'],
    ['https://apps.apple.com/app/x', 'app_store'],
    ['https://play.google.com/store/apps/x', 'play_store'],
    ['https://amina.dev', 'website'],
    ['not a url', 'other'],
  ])('%s -> %s', (url, kind) => {
    expect(inferLinkKind(url)).toBe(kind)
  })
})

describe('toStaffProduct', () => {
  it('maps the single read, keeping the microsecond review token and newest review first', async () => {
    const { toStaffProduct } = await import('@/api/mappers')
    const { staffProduct } = await import('../fixtures/staff')
    const p = toStaffProduct(
      staffProduct({
        id: '01JPRKDX00000000000000000X',
        tagline: null,
        submitted_at: '2026-10-22T08:15:30.123456Z',
        reviews: [
          { id: 'r1', decision: 'rejected', note: null, created_at: '2026-10-01T10:00:00+00:00', reviewer: { id: 'k', handle: 'kenji' } },
          { id: 'r2', decision: 'approved', note: 'ok', created_at: '2026-10-05T10:00:00+00:00' },
        ],
      }),
    )
    expect(p.submittedAt).toBe('2026-10-22T08:15:30.123456Z')
    expect(p.tagline).toBe('')
    expect(p.reviews.map((r) => r.id)).toEqual(['r2', 'r1'])
    expect(p.reviews[1]).toMatchObject({ note: '', reviewer: 'kenji' })
    expect(p.maker).toMatchObject({ handle: 'rafi' })
    expect(p.topics[0].slug).toBe('ai')
  })

  it('maps a list row with its counts and no heavy fields', async () => {
    const { toStaffProduct } = await import('@/api/mappers')
    const { staffRow } = await import('../fixtures/staff')
    const p = toStaffProduct(staffRow({ votes_count: 7, comments_count: 2, takedown_reason: 'Scam', status: 'taken_down' }))
    expect(p).toMatchObject({ votes: 7, commentsCount: 2, takedownReason: 'Scam', status: 'taken_down', description: '', links: [], screenshots: [], reviews: [] })
  })
})
