import { describe, expect, it } from 'vitest'
import { toFounderProduct } from '@/api/mappers'
import { rankSlots, sourceLabel, votesPerSlot } from '@/lib/dashboard'
import { completeDraft, founderProduct, listRow, review, shot } from '../fixtures/founder'
import { hourly } from '../fixtures/dashboard'

describe('toFounderProduct', () => {
  it('maps a full single read: nulls become empty text, screenshots and topics are ordered', () => {
    const p = toFounderProduct(
      completeDraft({
        tagline: null,
        screenshots: [shot({ id: 'b', position: 1, alt_text: 'Second' }), shot({ id: 'a', position: 0 })],
        links: [{ id: 'l', kind: 'github', url: 'https://github.com/x', position: 0 }],
      }),
    )
    expect(p.tagline).toBe('')
    expect(p.screenshots?.map((s) => s.id)).toEqual(['a', 'b'])
    expect(p.screenshots?.[1].altText).toBe('Second')
    expect(p.links).toEqual([{ kind: 'github', url: 'https://github.com/x' }])
    expect(p.editableFields).toContain('slug')
    expect(p.mediaEditable).toBe(true)
  })

  it('leaves relations undefined on a list row, which does not load them', () => {
    const p = toFounderProduct(listRow({ status: 'pending' }))
    expect(p.screenshots).toBeUndefined()
    expect(p.topics).toBeUndefined()
    expect(p.reviews).toBeUndefined()
    expect(p.editableFields).toEqual([])
    expect(p.mediaEditable).toBe(false)
  })

  it('takes the reviewer note from the decision flagged current', () => {
    const p = toFounderProduct(
      founderProduct({
        status: 'changes_requested',
        reviews: [review({ id: 'old', note: 'Old note', current: false }), review({ id: 'new', note: 'Fix the logo', current: true })],
      }),
    )
    expect(p.reviewNote).toBe('Fix the logo')
    expect(p.reviews).toHaveLength(2)
  })
})

describe('dashboard helpers', () => {
  it('drops the hours that have not happened yet', () => {
    const day = '2026-10-22'
    const points = hourly([5, 1, 2, 9], day)
    expect(votesPerSlot(points, Date.parse(`${day}T02:30:00Z`))).toEqual([5, 1, 2])
  })

  it('puts each 10-minute mark in its slot and leaves gaps as null', () => {
    const day = '2026-10-22'
    const slots = rankSlots(
      [
        { t: `${day}T00:00:00Z`, rank: 4, score: 1 },
        { t: `${day}T00:30:00Z`, rank: 2, score: 2 },
      ],
      day,
      Date.parse(`${day}T00:45:00Z`),
    )
    expect(slots).toEqual([4, null, null, 2, null])
  })

  it('names known sources and shows an unknown host as written', () => {
    expect(sourceLabel({ source: 'hacker_news' })).toBe('Hacker News')
    expect(sourceLabel({ source: 'blog.example.org' })).toBe('blog.example.org')
  })
})
