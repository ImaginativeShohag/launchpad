import { describe, expect, it } from 'vitest'
import { toComment, toFollowedUser, toProductDetail, toProfileCard, toUser } from '@/api/mappers'
import { hueFromId } from '@/lib/hue'
import { apiComment, commentAuthor, placeholderComment } from '../fixtures/comment'
import { apiProduct } from '../fixtures/product'
import { followedUser } from '../fixtures/profile'
import { publicUser } from '../fixtures/user'

describe('toComment', () => {
  it('maps a visible comment with its author card, mention, edit mark and embedded replies', () => {
    const reply = apiComment({ body: 'Reply', parent_id: 'p1', reply_to: { id: 'u1', handle: 'carl', name: 'Carl' }, can_edit: true, can_delete: true })
    const c = toComment(apiComment({ id: 'p1', body: 'Hello', author: commentAuthor({ is_maker: true, avatar_url: 'http://x/a.webp' }), edited_at: '2026-10-21T09:05:00.000000Z', replies_count: 5, replies: [reply] }))

    expect(c).toMatchObject({ id: 'p1', state: 'visible', body: 'Hello', repliesCount: 5, editedAt: '2026-10-21T09:05:00.000000Z', canEdit: false, canDelete: false })
    expect(c.author).toMatchObject({ handle: 'carl', isMaker: true, suspended: false, avatarUrl: 'http://x/a.webp', hue: hueFromId('01JCARL00000000000000000000') })
    expect(c.replies).toHaveLength(1)
    expect(c.replies[0]).toMatchObject({ parentId: 'p1', replyTo: { handle: 'carl' }, canEdit: true, canDelete: true })
  })

  it('keeps no body or author on a removed or deleted placeholder, even if one is sent', () => {
    expect(toComment(placeholderComment('deleted'))).toMatchObject({ state: 'deleted', body: null, author: null, replyTo: null })
    expect(toComment({ ...placeholderComment('removed'), body: 'leaked text' }).body).toBeNull()
  })

  it('treats absent can_* keys (a guest) as false, and a client-made id as pending', () => {
    expect(toComment(apiComment({ can_edit: undefined, can_delete: undefined }))).toMatchObject({ canEdit: false, canDelete: false, pending: undefined })
    expect(toComment(apiComment({ id: 'tmp-3' })).pending).toBe(true)
  })

  it('marks a suspended author', () => {
    expect(toComment(apiComment({ author: commentAuthor({ is_suspended: true }) })).author?.suspended).toBe(true)
  })

  it('has no replies when the API sent none (a single-comment answer)', () => {
    expect(toComment({ ...apiComment(), replies: undefined }).replies).toEqual([])
  })
})

describe('toProductDetail', () => {
  it('orders screenshots by position and falls back to a generated alt text', () => {
    const d = toProductDetail(
      apiProduct({
        name: 'Orbit',
        screenshots: [
          { id: 'b', url: 'http://x/b.webp', position: 1, width: 10, height: 6, alt_text: ' ' },
          { id: 'a', url: 'http://x/a.webp', position: 0, width: null, height: null, alt_text: 'Dashboard' },
        ],
      }),
    )

    expect(d.screenshots.map((s) => [s.id, s.alt])).toEqual([
      ['a', 'Dashboard'],
      ['b', 'Orbit screenshot 2'],
    ])
  })

  it('maps nulls to empty values and the maker to a user', () => {
    const d = toProductDetail(apiProduct({ tagline: null, description: null, website_url: null, logo_url: null, comments_count: undefined, topics: undefined, screenshots: undefined, links: undefined }))

    expect(d).toMatchObject({ tagline: '', description: '', website: '', logoUrl: undefined, commentsCount: 0, topics: [], screenshots: [], links: [] })
    expect(d.maker).toMatchObject({ handle: 'maker', name: 'Mia Maker' })
  })

  it('keeps the guest/member vote fields of the card', () => {
    expect(toProductDetail(apiProduct({ has_voted: undefined })).hasVoted).toBeUndefined()
    expect(toProductDetail(apiProduct({ has_voted: true })).hasVoted).toBe(true)
  })
})

describe('profile mappers', () => {
  it('toUser reads the counts and is_following of a public profile, and omits is_following for a guest', () => {
    expect(toUser(publicUser({ followers_count: 5, following_count: 2, is_following: true }))).toMatchObject({ followersCount: 5, followingCount: 2, isFollowing: true })
    expect(toUser(publicUser({ is_following: undefined })).isFollowing).toBeUndefined()
    expect(toUser(publicUser({ is_suspended: true })).suspended).toBe(true)
  })

  it('toProfileCard drops the numbers the list does not have', () => {
    expect(toProfileCard(apiProduct({ votes_count: undefined, comments_count: undefined }))).toMatchObject({ commentsCount: undefined, votes: 0 })
  })

  it('toFollowedUser makes a card of a followed member', () => {
    expect(toFollowedUser(followedUser({ is_suspended: true, headline: null }))).toMatchObject({ handle: 'rafi', headline: '', suspended: true, role: 'member' })
  })
})
