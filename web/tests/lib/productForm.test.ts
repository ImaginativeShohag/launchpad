import { describe, expect, it } from 'vitest'
import { imageProblem, isHttpsUrl, isValidName, isValidSlug, slugify } from '@/lib/productForm'

describe('product form helpers', () => {
  it('derives a valid slug from a name', () => {
    expect(slugify('Palette Pilot!')).toBe('palette-pilot')
    expect(slugify('  Crème  brûlée -- app ')).toBe('creme-brulee-app')
    expect(isValidSlug(slugify('Palette Pilot'))).toBe(true)
    expect(slugify('!!')).toBe('')
  })

  it('checks slug and name the way the API does', () => {
    expect(isValidSlug('ab')).toBe(false)
    expect(isValidSlug('Has-Caps')).toBe(false)
    expect(isValidSlug('double--hyphen')).toBe(false)
    expect(isValidName('   ')).toBe(false)
    expect(isValidName('!!!')).toBe(false)
    expect(isValidName('Lumen 2')).toBe(true)
  })

  it('accepts only https links with a real host', () => {
    expect(isHttpsUrl('https://example.com')).toBe(true)
    expect(isHttpsUrl('http://example.com')).toBe(false)
    expect(isHttpsUrl('https://localhost')).toBe(false)
    expect(isHttpsUrl('javascript:alert(1)')).toBe(false)
    expect(isHttpsUrl('https://example.xn--p1ai')).toBe(true)
  })

  it('rejects the wrong image type or a big file before any upload', () => {
    expect(imageProblem(new File(['x'], 'a.gif', { type: 'image/gif' }), 1024)).toBeTruthy()
    expect(imageProblem(new File([new Uint8Array(2048)], 'a.png', { type: 'image/png' }), 1024)).toBeTruthy()
    expect(imageProblem(new File(['x'], 'a.webp', { type: 'image/webp' }), 1024)).toBeUndefined()
  })
})
