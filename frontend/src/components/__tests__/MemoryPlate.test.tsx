import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { MemoryPlate } from '../MemoryPlate'
import { aTimelineMemory } from '../../test/harness'

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

function renderPlate() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <MemoryPlate
        memory={aTimelineMemory()}
        flip={false}
        eager
        canManage={false}
        onOpen={() => {}}
        onEdit={() => {}}
        onRemove={() => {}}
      />
    </QueryClientProvider>,
  )
}

describe('how a memory arrives', () => {
  it('is held back on the way in, then handed back to the stylesheet', async () => {
    const { container } = renderPlate()

    const frame = container.querySelector('.plate__frame') as HTMLElement
    const caption = container.querySelector('.plate__caption') as HTMLElement

    /*
     | Held: the photograph is covered, and the words below it are not yet
     | there — so what follows is a real arrival rather than a no-op.
     */
    expect(frame.style.clipPath).toContain('inset(100%')
    expect(caption.style.opacity).toBe('0')

    /*
     | And let go of. Once settled there is no inline opacity or transform
     | left, so what is on screen is the stylesheet's own state — which is
     | what a page whose script never ran would show.
     */
    await waitFor(
      () => {
        expect(frame.style.clipPath).toBe('')
        expect(frame.style.transform).toBe('')
        expect(caption.style.opacity).toBe('')
        expect(caption.style.transform).toBe('')
      },
      { timeout: 3000 },
    )
  })

  it('moves nothing, and hides nothing, when less motion has been asked for', async () => {
    askForLessMotion()

    const { container } = renderPlate()

    const frame = container.querySelector('.plate__frame') as HTMLElement
    const caption = container.querySelector('.plate__caption') as HTMLElement

    /*
     | Never held back at all. A memory hidden by a preference — including one
     | switched on while it was still off screen — would be a photograph the
     | archive simply refused to show.
     */
    expect(frame.style.clipPath).toBe('')
    expect(frame.style.transform).toBe('')
    expect(caption.style.opacity).toBe('')

    await new Promise((resolve) => setTimeout(resolve, 60))

    expect(frame.style.opacity).toBe('')
    expect(caption.style.opacity).toBe('')
  })
})
