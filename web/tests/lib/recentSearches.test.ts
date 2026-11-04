import { afterEach, describe, expect, it, vi } from 'vitest'
import { clearRecent, loadRecent, rememberSearch } from '@/lib/recentSearches'

afterEach(() => {
  vi.restoreAllMocks()
  localStorage.clear()
})

describe('recent searches', () => {
  it('keeps the newest first, without duplicates, at most six', () => {
    let list: string[] = []
    for (const t of ['aa', 'bb', 'cc', 'dd', 'ee', 'ff', 'gg']) list = rememberSearch(list, t)
    list = rememberSearch(list, 'DD')

    expect(list).toEqual(['DD', 'gg', 'ff', 'ee', 'cc', 'bb'])
    expect(loadRecent()).toEqual(list)
  })

  it('ignores a term shorter than two characters', () => {
    expect(rememberSearch(['ab'], ' x ')).toEqual(['ab'])
  })

  it('survives corrupt or non-array storage', () => {
    localStorage.setItem('launchpad-recent-searches', '{not json')
    expect(loadRecent()).toEqual([])
    localStorage.setItem('launchpad-recent-searches', JSON.stringify({ a: 1 }))
    expect(loadRecent()).toEqual([])
    localStorage.setItem('launchpad-recent-searches', JSON.stringify(['ok', 5, null, '']))
    expect(loadRecent()).toEqual(['ok'])
  })

  it('survives blocked storage on read and write', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError')
    })
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('full', 'QuotaExceededError')
    })
    vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError')
    })

    expect(loadRecent()).toEqual([])
    expect(rememberSearch([], 'hello')).toEqual(['hello'])
    expect(clearRecent()).toEqual([])
  })
})
