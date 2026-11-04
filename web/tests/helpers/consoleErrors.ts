import { afterEach, beforeEach, expect, vi, type MockInstance } from 'vitest'

/** Fails a test file's tests on any console.error (React warnings included), except the act() chatter. */
export function failOnConsoleError() {
  let spy: MockInstance<typeof console.error>
  const errors: string[] = []
  beforeEach(() => {
    errors.length = 0
    spy = vi.spyOn(console, 'error').mockImplementation((...a: unknown[]) => void errors.push(a.map(String).join(' ').slice(0, 400)))
  })
  afterEach(() => {
    spy.mockRestore()
    expect(errors.filter((e) => !e.includes('not wrapped in act'))).toEqual([])
  })
}
