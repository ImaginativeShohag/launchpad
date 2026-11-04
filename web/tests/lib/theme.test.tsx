import { act, fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ThemeProvider, useTheme } from '@/theme/ThemeProvider'

function Toggle() {
  const { theme, toggleTheme } = useTheme()
  return <button onClick={toggleTheme}>{theme}</button>
}

describe('ThemeProvider', () => {
  it('toggles the dark class and remembers the choice', () => {
    localStorage.setItem('launchpad-theme', 'light')
    render(
      <ThemeProvider>
        <Toggle />
      </ThemeProvider>,
    )
    expect(document.documentElement.classList.contains('dark')).toBe(false)

    act(() => {
      fireEvent.click(screen.getByRole('button'))
    })

    expect(screen.getByRole('button').textContent).toBe('dark')
    expect(document.documentElement.classList.contains('dark')).toBe(true)
    expect(localStorage.getItem('launchpad-theme')).toBe('dark')
  })
})
