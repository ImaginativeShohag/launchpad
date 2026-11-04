import { describe, expect, it } from 'vitest'
import { diffRanks, rankMap } from '@/lib/rankMoves'

describe('diffRanks', () => {
  it('reports places gained as positive and lost as negative', () => {
    const prev = rankMap([{ id: 'a' }, { id: 'b' }, { id: 'c' }])
    const next = rankMap([{ id: 'c' }, { id: 'a' }, { id: 'b' }])

    expect(diffRanks(prev, next)).toEqual({ c: 2, a: -1, b: -1 })
  })

  it('uses the API rank over the position when there is one', () => {
    const prev = rankMap([{ id: 'a', rank: 4 }, { id: 'b', rank: 5 }])
    const next = rankMap([{ id: 'b', rank: 4 }, { id: 'a', rank: 5 }])

    expect(diffRanks(prev, next)).toEqual({ b: 1, a: -1 })
  })

  it('gives a newcomer and an unchanged product no pill', () => {
    const prev = rankMap([{ id: 'a' }, { id: 'b' }])
    const next = rankMap([{ id: 'a' }, { id: 'new' }, { id: 'b' }])

    expect(diffRanks(prev, next)).toEqual({ b: -1 })
  })
})
