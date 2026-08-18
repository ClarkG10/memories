import { describe, expect, it } from 'vitest'
import { formatBytes } from '../bytes'

describe('sizes people can read', () => {
  it('drops a decimal that says nothing', () => {
    // "8.0 GB" is not how anybody says it.
    expect(formatBytes(8 * 1024 ** 3)).toBe('8 GB')
    expect(formatBytes(250 * 1024 ** 2)).toBe('250 MB')
  })

  it('keeps the decimal where it carries information', () => {
    expect(formatBytes(3.4 * 1024 ** 3)).toBe('3.4 GB')
  })

  it('rounds away detail nobody needs', () => {
    expect(formatBytes(1536)).toBe('2 KB')
    expect(formatBytes(15.6 * 1024 ** 3)).toBe('16 GB')
  })

  it('does not produce nonsense for nothing at all', () => {
    expect(formatBytes(0)).toBe('0 KB')
    expect(formatBytes(Number.NaN)).toBe('0 KB')
  })
})
