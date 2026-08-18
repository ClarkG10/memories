import { screen, waitFor, within } from '@testing-library/react'
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

describe('filling the screen', () => {
    it('offers a way to fill the screen', async () => {
    mockApi(baseHandlers())

    // jsdom has no Fullscreen API, so stand one in.
    const request = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(document, 'fullscreenEnabled', { value: true, configurable: true })
    Object.defineProperty(document, 'fullscreenElement', { value: null, configurable: true })
    Element.prototype.requestFullscreen = request

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
