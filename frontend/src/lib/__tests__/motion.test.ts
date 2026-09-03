import { describe, expect, it, vi } from 'vitest'
import { prefersReducedMotion, settleIn, settleOut } from '../motion'

/** Pretend the person has asked their system for less movement. */
function askForLessMotion() {
  vi.spyOn(window, 'matchMedia').mockImplementation(
    (query: string) =>
      ({
        matches: query.includes('reduce'),
        media: query,
        onchange: null,
        addEventListener: () => {},
        removeEventListener: () => {},
        addListener: () => {},
        removeListener: () => {},
        dispatchEvent: () => false,
      }) as MediaQueryList,
  )
}

function anElement() {
  const element = document.createElement('div')
  document.body.appendChild(element)

  return element
}

describe('motion', () => {
  it('reads no stated preference as motion allowed', () => {
    expect(prefersReducedMotion()).toBe(false)
  })

  it('settles an element to exactly where the stylesheet left it', async () => {
    const element = anElement()

    const tween = settleIn(element, { duration: 0.02 })

    // On its way in, it starts below and transparent.
    expect(tween).not.toBeNull()
    expect(element.style.opacity).toBe('0')
    expect(element.style.transform).toContain('translate')

    await tween?.then()

    // At rest, nothing GSAP set is left behind.
    expect(element.style.opacity).toBe('')
    expect(element.style.transform).toBe('')
  })

  it('moves nothing when less motion has been asked for', () => {
    askForLessMotion()

    const element = anElement()

    expect(settleIn(element)).toBeNull()
    expect(element.style.opacity).toBe('')
    expect(element.style.transform).toBe('')
  })

  it('has nothing to do with nothing', () => {
    expect(settleIn(null)).toBeNull()
    expect(settleIn([])).toBeNull()
  })

  it('takes an element out, then says so', async () => {
    const element = anElement()

    await new Promise<void>((done) => {
      settleOut(element, done, { duration: 0.02 })
    })

    expect(element.style.opacity).toBe('0')
  })

  it('is not undone by the arrival it interrupted', async () => {
    const element = anElement()

    // Dismissed while it is still coming in.
    settleIn(element, { duration: 0.4 })
    await new Promise((resolve) => setTimeout(resolve, 40))

    await new Promise<void>((done) => {
      settleOut(element, done, { duration: 0.05 })
    })

    /*
     | The arrival tween is killed rather than left to finish: allowed to run
     | on, its clearProps would fire afterwards and hand back, at full opacity,
     | an element that had just faded away.
     */
    await new Promise((resolve) => setTimeout(resolve, 450))

    expect(element.style.opacity).toBe('0')
  })

  it('says so at once when less motion has been asked for', () => {
    askForLessMotion()

    const done = vi.fn()

    expect(settleOut(anElement(), done)).toBeNull()
    expect(done).toHaveBeenCalledTimes(1)
  })
})
