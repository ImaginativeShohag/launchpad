import { describe, expect, it } from 'vitest'
import { safeNext, signInPath } from '@/lib/nextPath'

describe('safeNext', () => {
  it.each(['/', '/settings', '/p/lumen?tab=a', '/verify-email?id=1&signature=x'])('keeps %s', (p) => expect(safeNext(p)).toBe(p))

  it.each([null, undefined, '', 'settings', '//evil.com', '//evil.com/x', '/\\evil.com', 'https://evil.com', 'javascript:alert(1)', '/ok\nbad', 'http://localhost/x'])('rejects %j', (p) => {
    expect(safeNext(p)).toBe('/')
  })

  it('uses the given fallback', () => expect(safeNext('//x', '/home')).toBe('/home'))
})

describe('signInPath', () => {
  it('encodes the way back', () => {
    expect(signInPath('/settings?tab=email')).toBe('/signin?next=%2Fsettings%3Ftab%3Demail')
    expect(signInPath('/')).toBe('/signin')
  })
})
