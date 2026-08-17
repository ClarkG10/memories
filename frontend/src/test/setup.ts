import '@testing-library/jest-dom/vitest'
import { afterEach, vi } from 'vitest'
import { cleanup } from '@testing-library/react'

afterEach(() => {
  cleanup()
  window.localStorage.clear()
})

/*
 | jsdom implements neither of these, and both are load-bearing: the timeline
 | reveals memories on intersection and the compose sheet previews files from
 | object URLs. Without stubs, components would render empty rather than fail
 | loudly, which is the worst of both.
 */

class IntersectionObserverStub implements IntersectionObserver {
  readonly root = null
  readonly rootMargin = ''
  readonly scrollMargin = ''
  readonly thresholds: ReadonlyArray<number> = []

  private readonly callback: IntersectionObserverCallback

  constructor(callback: IntersectionObserverCallback) {
    this.callback = callback
  }

  observe(target: Element): void {
    // Report everything as on screen, so tests see the settled state rather
    // than a page of elements waiting to be scrolled to.
    this.callback(
      [{ isIntersecting: true, target } as IntersectionObserverEntry],
      this as unknown as IntersectionObserver,
    )
  }

  unobserve(): void {}
  disconnect(): void {}

  takeRecords(): IntersectionObserverEntry[] {
    return []
  }
}

vi.stubGlobal('IntersectionObserver', IntersectionObserverStub)

if (!window.URL.createObjectURL) {
  window.URL.createObjectURL = vi.fn(() => 'blob:preview')
  window.URL.revokeObjectURL = vi.fn()
}

if (!window.matchMedia) {
  window.matchMedia = (query: string) =>
    ({
      matches: false,
      media: query,
      onchange: null,
      addEventListener: () => {},
      removeEventListener: () => {},
      addListener: () => {},
      removeListener: () => {},
      dispatchEvent: () => false,
    }) as MediaQueryList
}

window.scrollTo = vi.fn() as unknown as typeof window.scrollTo
