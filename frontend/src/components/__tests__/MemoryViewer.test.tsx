import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import {
  aMemory,
  aTimelineMemory,
  aVideo,
  anArchive,
  anImage,
  mockApi,
  renderArchive,
  timelinePage,
} from '../../test/harness'

const ARCHIVE = /\/api\/archive/
const TIMELINE = /\/api\/timeline\?/
const YEARS = /\/api\/timeline\/years/
const MEMORY = /\/api\/memories\/memory-1/

function baseHandlers(memory = aMemory()) {
  return [
    { match: ARCHIVE, body: { data: anArchive() } },
    { match: YEARS, body: { data: [] } },
    { match: TIMELINE, body: timelinePage([aTimelineMemory()]) },
    { match: MEMORY, body: { data: memory } },
  ]
}

describe('opening a memory', () => {
  it('opens from the timeline and shows the whole memory', async () => {
    mockApi(baseHandlers())

    renderArchive()

    await userEvent.click(
      await screen.findByRole('button', { name: 'Open That Beautiful Evening' }),
    )

    const viewer = await screen.findByRole('dialog', { name: 'That Beautiful Evening' })

    // The description is not in the timeline payload; it arrives with the
    // memory itself.
    expect(
      await screen.findByText('One of those evenings we wish we could replay.'),
    ).toBeInTheDocument()
    expect(within(viewer).getByText('10 August 2026')).toBeInTheDocument()
    expect(within(viewer).getByText('Butuan')).toBeInTheDocument()
  })

  it('can be opened straight from its own address', async () => {
    mockApi(baseHandlers())

    renderArchive('/m/memory-1')

    expect(await screen.findByRole('dialog', { name: 'That Beautiful Evening' })).toBeInTheDocument()
  })

  it('closes with the Escape key', async () => {
    mockApi(baseHandlers())

    renderArchive('/m/memory-1')

    await screen.findByRole('dialog', { name: 'That Beautiful Evening' })

    await userEvent.keyboard('{Escape}')

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
  })

  it('closes with the close button', async () => {
    mockApi(baseHandlers())

    renderArchive('/m/memory-1')

    await screen.findByRole('dialog')
    await userEvent.click(screen.getByRole('button', { name: 'Close' }))

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
  })

  it('lands on the timeline when closed after following a shared link', async () => {
    mockApi(baseHandlers())

    // Arrived straight at the memory: there is nothing behind it to go back
    // to, and going back anyway would leave the archive altogether.
    renderArchive('/m/memory-1', { fromLink: true })

    await screen.findByRole('dialog')
    await userEvent.click(screen.getByRole('button', { name: 'Close' }))

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })

    // Still inside the archive, looking at the timeline.
    expect(await screen.findByText('That Beautiful Evening')).toBeInTheDocument()
  })

  it('moves between photographs with the arrow keys', async () => {
    const memory = aMemory({
      media_count: 3,
      media: [
        anImage({ id: 'one' }),
        anImage({ id: 'two' }),
        anImage({ id: 'three' }),
      ],
    })

    mockApi(baseHandlers(memory))

    renderArchive('/m/memory-1')

    // The viewer opens straight away and fills in as the memory arrives.
    expect(await screen.findByText('1 / 3')).toBeInTheDocument()

    await userEvent.keyboard('{ArrowRight}')
    expect(screen.getByText('2 / 3')).toBeInTheDocument()

    await userEvent.keyboard('{ArrowRight}')
    expect(screen.getByText('3 / 3')).toBeInTheDocument()

    // The end is the end; it does not wrap around to the beginning.
    await userEvent.keyboard('{ArrowRight}')
    expect(screen.getByText('3 / 3')).toBeInTheDocument()

    await userEvent.keyboard('{ArrowLeft}')
    expect(screen.getByText('2 / 3')).toBeInTheDocument()
  })

  it('does not offer navigation for a memory holding one photograph', async () => {
    mockApi(baseHandlers())

    renderArchive('/m/memory-1')

    await screen.findByRole('dialog')

    expect(screen.queryByRole('button', { name: 'Next' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Previous' })).not.toBeInTheDocument()
  })

  it('plays a video with controls and never starts it on its own', async () => {
    const memory = aMemory({ media: [aVideo()], media_count: 1 })

    mockApi(baseHandlers(memory))

    const { container } = renderArchive('/m/memory-1')

    await screen.findByRole('dialog')
    await waitFor(() => expect(container.querySelector('video')).toBeInTheDocument())

    const video = container.querySelector('video') as HTMLVideoElement

    expect(video).toHaveAttribute('controls')
    expect(video).not.toHaveAttribute('autoplay')
    expect(video).toHaveAttribute('poster')
    // Only the metadata, so opening a memory never pulls down a whole film.
    expect(video).toHaveAttribute('preload', 'metadata')
  })

  it('says so plainly when a memory cannot be opened', async () => {
    mockApi([
      { match: ARCHIVE, body: { data: anArchive() } },
      { match: YEARS, body: { data: [] } },
      { match: TIMELINE, body: timelinePage([aTimelineMemory()]) },
      { match: MEMORY, status: 500, body: { message: 'boom' } },
    ])

    renderArchive('/m/memory-1')

    expect(await screen.findByText("We couldn't open this memory just now.")).toBeInTheDocument()
  })
})

/** jsdom has no Fullscreen API, so stand one in. */
function stubFullscreen() {
  const request = vi.fn().mockResolvedValue(undefined)
  const exit = vi.fn().mockResolvedValue(undefined)

  Object.defineProperty(document, 'fullscreenEnabled', { value: true, configurable: true })
  Object.defineProperty(document, 'fullscreenElement', { value: null, configurable: true })
  Element.prototype.requestFullscreen = request
  document.exitFullscreen = exit

  return { request, exit }
}

describe('a memory holding a great many photographs', () => {
  function aMemoryOf(count: number) {
    return aMemory({
      media_count: count,
      media: Array.from({ length: count }, (_, i) => anImage({ id: `m${i}` })),
    })
  }

  it('shows a dot per photograph while there are few enough to aim at', async () => {
    mockApi(baseHandlers(aMemoryOf(5)))

    renderArchive('/m/memory-1')

    await screen.findByRole('dialog')

    expect(await screen.findByRole('button', { name: 'Show 1 of 5' })).toBeInTheDocument()
    expect(screen.queryByRole('slider')).not.toBeInTheDocument()
  })

  it('swaps to a scrubber once there are too many', async () => {
    mockApi(baseHandlers(aMemoryOf(49)))

    renderArchive('/m/memory-1')

    await screen.findByRole('dialog')

    /*
     | Forty-nine dots is 2,156 pixels of row on a 1,440 pixel screen. The row
     | pushed the viewer's grid column wider than the window, and everything
     | centred inside it — the photograph, the title — slid off to the right.
     */
    expect(await screen.findByRole('slider')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Show 1 of 49' })).not.toBeInTheDocument()
  })

  it('moves to the photograph the scrubber is dragged to', async () => {
    mockApi(baseHandlers(aMemoryOf(49)))

    renderArchive('/m/memory-1')

    expect(await screen.findByText('1 / 49')).toBeInTheDocument()

    fireEvent.change(await screen.findByRole('slider'), { target: { value: '30' } })

    expect(screen.getByText('30 / 49')).toBeInTheDocument()
  })

  it('still counts from one, however many there are', async () => {
    mockApi(baseHandlers(aMemoryOf(49)))

    renderArchive('/m/memory-1')

    const slider = await screen.findByRole('slider')

    // The photographs are numbered for a person, not indexed for a machine.
    expect(slider).toHaveAttribute('min', '1')
    expect(slider).toHaveAttribute('max', '49')
    expect(slider).toHaveValue('1')
  })
})

describe('filling the screen', () => {
  it('offers a way to fill the screen', async () => {
    mockApi(baseHandlers())

    const { request } = stubFullscreen()

    renderArchive('/m/memory-1')

    await screen.findByRole('dialog')

    /*
     | The viewer already fills the window, but the browser's own tabs and
     | address bar still take a third of a laptop screen — worth reclaiming
     | for a photograph.
     */
    await userEvent.click(screen.getByRole('button', { name: 'View full screen' }))

    expect(request).toHaveBeenCalled()
  })
})

describe('the photograph on its own', () => {
  it('takes the words away, and the browser chrome with them', async () => {
    mockApi(baseHandlers())

    const { request } = stubFullscreen()

    renderArchive('/m/memory-1')

    // The viewer opens before the memory arrives; wait for the memory.
    const viewer = await screen.findByRole('dialog', { name: 'That Beautiful Evening' })

    // The words are there to begin with.
    expect(within(viewer).getByText('10 August 2026')).toBeInTheDocument()
    expect(viewer).toHaveAttribute('data-bare', 'false')

    await userEvent.click(screen.getByRole('button', { name: 'Photograph only' }))

    expect(viewer).toHaveAttribute('data-bare', 'true')
    // Asking for the whole screen is part of asking for the whole photograph.
    expect(request).toHaveBeenCalled()
    // Nothing left to read: not the date, not the title, not the count.
    expect(within(viewer).queryByText('10 August 2026')).not.toBeInTheDocument()
    expect(within(viewer).queryByRole('heading', { name: 'That Beautiful Evening' })).not.toBeInTheDocument()
    expect(within(viewer).queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument()
  })

  it('comes back, so nobody is stranded in it', async () => {
    mockApi(baseHandlers())

    stubFullscreen()

    renderArchive('/m/memory-1')

    // The viewer opens before the memory arrives; wait for the memory.
    const viewer = await screen.findByRole('dialog', { name: 'That Beautiful Evening' })

    await userEvent.click(screen.getByRole('button', { name: 'Photograph only' }))
    await userEvent.click(screen.getByRole('button', { name: 'Show the words' }))

    expect(viewer).toHaveAttribute('data-bare', 'false')
    expect(within(viewer).getByText('10 August 2026')).toBeInTheDocument()
  })

  it('is reached by pressing the photograph itself', async () => {
    mockApi(baseHandlers())

    stubFullscreen()

    renderArchive('/m/memory-1')

    // The viewer opens before the memory arrives; wait for the memory.
    const viewer = await screen.findByRole('dialog', { name: 'That Beautiful Evening' })

    // The photograph is the control: that was the whole point of the gesture.
    await userEvent.click(within(viewer).getByRole('button', { name: 'That Beautiful Evening' }))

    expect(viewer).toHaveAttribute('data-bare', 'true')
  })

  it('steps back out of bare on Escape rather than closing the memory', async () => {
    mockApi(baseHandlers())

    stubFullscreen()

    renderArchive('/m/memory-1')

    // The viewer opens before the memory arrives; wait for the memory.
    const viewer = await screen.findByRole('dialog', { name: 'That Beautiful Evening' })

    await userEvent.click(screen.getByRole('button', { name: 'Photograph only' }))
    await userEvent.keyboard('{Escape}')

    // Still open, words back on.
    expect(viewer).toHaveAttribute('data-bare', 'false')
    expect(screen.getByRole('dialog')).toBeInTheDocument()

    // The second one closes it, as it always did.
    await userEvent.keyboard('{Escape}')

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
  })

  it('is not flipped by the click a swipe leaves behind', async () => {
    const memory = aMemory({
      media_count: 2,
      media: [anImage({ id: 'one' }), anImage({ id: 'two' })],
    })

    mockApi(baseHandlers(memory))

    stubFullscreen()

    const { container } = renderArchive('/m/memory-1')

    // The viewer opens before the memory arrives; wait for the memory.
    const viewer = await screen.findByRole('dialog', { name: 'That Beautiful Evening' })
    expect(await screen.findByText('1 / 2')).toBeInTheDocument()

    const stage = container.querySelector('.viewer__stage') as HTMLElement

    // A swipe ends in a click on the photograph as far as the browser is
    // concerned, and that click must not also strip the words away.
    fireEvent.touchStart(stage, { touches: [{ clientX: 300, clientY: 200 }] })
    fireEvent.touchEnd(stage, { changedTouches: [{ clientX: 100, clientY: 210 }] })
    fireEvent.click(within(viewer).getByRole('button', { name: 'That Beautiful Evening' }))

    expect(screen.getByText('2 / 2')).toBeInTheDocument()
    expect(viewer).toHaveAttribute('data-bare', 'false')
  })
})
