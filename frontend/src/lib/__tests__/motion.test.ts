import { describe, expect, it, vi } from 'vitest'
import { canAnimate, prefersReducedMotion, rememberOrigin, settleIn, settleOut, takeOrigin } from '../motion'

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

  it('hides nothing when the page is not being drawn', () => {
    /*
     | A tab nobody is looking at gets no animation frames, so a tween started
     | there would paint its opening state — transparent — and sit on it. The
     | archive would be blank rather than merely unanimated.
     */
    const hidden = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')

    const element = anElement()

    expect(canAnimate()).toBe(false)
    expect(settleIn(element)).toBeNull()
    expect(element.style.opacity).toBe('')

    const done = vi.fn()
    expect(settleOut(element, done)).toBeNull()
    expect(done).toHaveBeenCalledTimes(1)

    hidden.mockRestore()
    expect(canAnimate()).toBe(true)
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

describe('where a memory was opened from', () => {
  it('is handed to the memory that was opened, and only to that one', () => {
    rememberOrigin('memory-1', anElement())

    // A different memory must never fly in from someone else's thumbnail.
    expect(takeOrigin('memory-2')).toBeNull()
  })

  it('is used once and then gone', () => {
    rememberOrigin('memory-1', anElement())

    expect(takeOrigin('memory-1')).not.toBeNull()
    expect(takeOrigin('memory-1')).toBeNull()
  })

  it('remembers nothing when there is nothing to remember', () => {
    rememberOrigin('memory-1', null)

    expect(takeOrigin('memory-1')).toBeNull()
  })

  it('remembers nothing when the archive is not animating', () => {
    askForLessMotion()

    rememberOrigin('memory-1', anElement())

    expect(takeOrigin('memory-1')).toBeNull()
  })
})
