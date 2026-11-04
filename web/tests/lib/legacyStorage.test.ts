import { afterEach, expect, it, vi } from 'vitest'
import { removeLegacyStorage } from '@/lib/legacyStorage'

afterEach(() => vi.restoreAllMocks())

it('removes the prototype keys and leaves everything else', () => {
  localStorage.setItem('launchpad-proto-v1', '{"votes":{}}')
  localStorage.setItem('launchpad-proto-emails', '{}')
  localStorage.setItem('launchpad-theme', 'dark')

  removeLegacyStorage()

  expect(localStorage.getItem('launchpad-proto-v1')).toBeNull()
  expect(localStorage.getItem('launchpad-proto-emails')).toBeNull()
  expect(localStorage.getItem('launchpad-theme')).toBe('dark')
})

it('does not throw when storage is blocked', () => {
  vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
    throw new DOMException('blocked', 'SecurityError')
  })
  expect(() => removeLegacyStorage()).not.toThrow()
})
