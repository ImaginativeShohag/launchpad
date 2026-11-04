import { describe, expect, it } from 'vitest'
import { toFeedCard, toProductCard } from '@/api/mappers'
import { hueFromId } from '@/lib/hue'
import { apiProduct, memberReason, rankedProduct, topicReason, topicsBody } from '../fixtures/product'

describe('toProductCard', () => {
  it('maps a ranked card of a signed-in member', () => {
    const topic = topicsBody().data[0]
    const card = toProductCard(rankedProduct(3, { id: '01JPRODUCT00000000000000001', has_voted: true, votes_count: 42, comments_count: 7, logo_url: 'http://api.test/logo.webp', topics: [topic as never] }))

    expect(card).toMatchObject({
      id: '01JPRODUCT00000000000000001',
      rank: 3,
      votes: 42,
      commentsCount: 7,
      hasVoted: true,
      logoUrl: 'http://api.test/logo.webp',
      makerId: '01JMAKER0000000000000000000',
      hue: hueFromId('01JPRODUCT00000000000000001'),
    })
    expect(card.topics).toEqual([expect.objectContaining({ slug: 'ai', name: 'AI' })])
  })

  it('leaves has_voted undefined for a guest (the key is absent) and rank undefined off the ranked lists', () => {
    const card = toProductCard(apiProduct())

    expect('hasVoted' in card && card.hasVoted !== undefined).toBe(false)
    expect(card.rank).toBeUndefined()
  })

  it('turns null text and missing counts into safe defaults', () => {
    const card = toProductCard(apiProduct({ tagline: null, description: null, logo_url: null, votes_count: undefined, comments_count: undefined, launch_date: null, maker: undefined }))

    expect(card).toMatchObject({ tagline: '', description: '', votes: 0, commentsCount: 0, makerId: '' })
    expect(card.logoUrl).toBeUndefined()
    expect(card.launchDate).toBeUndefined()
  })
})

describe('toFeedCard', () => {
  it('maps member and topic reasons', () => {
    const card = toFeedCard({ ...apiProduct(), reasons: [memberReason('Mia'), topicReason('AI', 'ai')] })

    expect(card.reasons).toEqual([
      { type: 'member', name: 'Mia', handle: 'maker' },
      { type: 'topic', name: 'AI', slug: 'ai' },
    ])
  })

  it('has no reasons when the API sends none', () => {
    expect(toFeedCard(apiProduct()).reasons).toEqual([])
  })
})
