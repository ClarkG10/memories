import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import {
  aTimelineMemory,
  anArchive,
  anImage,
  aVideo,
  mockApi,
  renderArchive,
  timelinePage,
} from '../../test/harness'

const ARCHIVE = /\/api\/archive/
const TIMELINE = /\/api\/timeline\?/
const YEARS = /\/api\/timeline\/years/

describe('the timeline', () => {
  it('shows memories under the year and month they belong to', async () => {
    mockApi([
      { match: ARCHIVE, body: { data: anArchive() } },
      { match: YEARS, body: { data: [{ year: 2026, count: 1 }, { year: 2024, count: 1 }] } },
      {
        match: TIMELINE,
        body: timelinePage([
          aTimelineMemory(),
          aTimelineMemory({
            id: 'memory-2',
            title: 'A Quiet Morning',
            memory_date: '2024-03-02',
            year: 2024,
            month: 3,
            location: null,
          }),
        ]),
      },
    ])

    renderArchive()

    expect(await screen.findByText('That Beautiful Evening')).toBeInTheDocument()

    expect(screen.getByRole('heading', { name: '2026' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: '2024' })).toBeInTheDocument()
    expect(screen.getByText('August')).toBeInTheDocument()
    expect(screen.getByText('March')).toBeInTheDocument()

    // The day, without the year — the year is the section it sits in.
    expect(screen.getByText('10 August')).toBeInTheDocument()
    expect(screen.getByText('Butuan')).toBeInTheDocument()
  })

  it('shows the archive title and its epigraph', async () => {
    mockApi([
      { match: ARCHIVE, body: { data: anArchive() } },
      { match: YEARS, body: { data: [] } },
      { match: TIMELINE, body: timelinePage([aTimelineMemory()]) },
    ])

    renderArchive()

    expect(await screen.findByRole('heading', { name: 'Our Memories' })).toBeInTheDocument()
    expect(screen.getByText('Every moment is worth remembering.')).toBeInTheDocument()
  })

  it('marks a video as a video and says how long it is', async () => {
    mockApi([
      { match: ARCHIVE, body: { data: anArchive() } },
      { match: YEARS, body: { data: [] } },
      {
        match: TIMELINE,
        body: timelinePage([
          aTimelineMemory({ title: 'Our First Dance', preview: [aVideo()] }),
        ]),
      },
    ])

    renderArchive()

    expect(await screen.findByText('Our First Dance')).toBeInTheDocument()
    expect(screen.getByText('0:42')).toBeInTheDocument()
  })

  it('says how many more photos a memory holds than it shows', async () => {
    mockApi([
      { match: ARCHIVE, body: { data: anArchive() } },
      { match: YEARS, body: { data: [] } },
      {
        match: TIMELINE,
        body: timelinePage([
          aTimelineMemory({
            media_count: 7,
            preview: [
              anImage({ id: 'a' }),
              anImage({ id: 'b' }),
              anImage({ id: 'c' }),
            ],
          }),
        ]),
      },
    ])

    renderArchive()

    // Seven in the memory, three on the card: four more.
    expect(await screen.findByText('+4')).toBeInTheDocument()
  })

  it('holds the layout open with a placeholder before a photograph arrives', async () => {
    mockApi([
      { match: ARCHIVE, body: { data: anArchive() } },
      { match: YEARS, body: { data: [] } },
      { match: TIMELINE, body: timelinePage([aTimelineMemory()]) },
    ])

    const { container } = renderArchive()

    await screen.findByText('That Beautiful Evening')

    const media = container.querySelector('.media') as HTMLElement
    expect(media).toHaveAttribute('data-loaded', 'false')

    // The space is reserved from the stored ratio, so nothing shifts when the
    // photograph lands. jsdom normalises the value to "1.5 / 1".
    expect(media.style.aspectRatio.replace(/\s*\/\s*1$/, '')).toBe('1.5')
    expect(media.querySelector('.media__placeholder')).toBeInTheDocument()
  })

  it('narrows to one year and puts it in the address', async () => {
    const { calls } = mockApi([
      { match: ARCHIVE, body: { data: anArchive() } },
      { match: YEARS, body: { data: [{ year: 2026, count: 1 }, { year: 2024, count: 3 }] } },
      { match: TIMELINE, body: timelinePage([aTimelineMemory()]) },
    ])

    renderArchive()

    await screen.findByText('That Beautiful Evening')

    const strip = screen.getByRole('navigation', { name: 'Jump to a year' })
    await userEvent.click(within(strip).getByRole('button', { name: '2024' }))

    await waitFor(() => {
      expect(calls.some((call) => call.url.includes('year=2024'))).toBe(true)
    })
  })

  it('offers a way back when the timeline cannot be loaded', async () => {
    mockApi([
      { match: ARCHIVE, body: { data: anArchive() } },
      { match: YEARS, body: { data: [] } },
      { match: TIMELINE, status: 500, body: { message: 'Server error' } },
    ])

    renderArchive()

    expect(
      await screen.findByText("We couldn't load your memories just now."),
    ).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument()
  })
})

describe('an archive with nothing in it', () => {
  it('says something worth reading rather than "no data"', async () => {
    mockApi([
      { match: ARCHIVE, body: { data: anArchive() } },
      { match: YEARS, body: { data: [] } },
      { match: TIMELINE, body: timelinePage([]) },
    ])

    renderArchive()

    expect(await screen.findByText('Every beautiful story starts somewhere.')).toBeInTheDocument()
    expect(screen.queryByText(/no data/i)).not.toBeInTheDocument()
  })

  it('invites the owner to begin, and only the owner', async () => {
    mockApi([
      { match: ARCHIVE, body: { data: anArchive({ can_manage: true }) } },
      { match: YEARS, body: { data: [] } },
      { match: TIMELINE, body: timelinePage([]) },
    ])

    renderArchive()

    expect(await screen.findByRole('button', { name: 'Add your first memory' })).toBeInTheDocument()
  })

  it('tells a visitor the archive is simply empty', async () => {
    mockApi([
      { match: ARCHIVE, body: { data: anArchive({ can_manage: false }) } },
      { match: YEARS, body: { data: [] } },
      { match: TIMELINE, body: timelinePage([]) },
    ])

    renderArchive()

    expect(await screen.findByText('Nothing has been added here yet.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Add your first memory' })).not.toBeInTheDocument()
  })
})

describe('a private archive', () => {
  it('shows a stranger the door rather than an error', async () => {
    mockApi([
      { match: ARCHIVE, body: { data: anArchive({ public: false, can_manage: false }) } },
      { match: YEARS, body: { data: [] } },
      { match: TIMELINE, status: 403, body: { message: 'This archive is private.' } },
    ])

    renderArchive()

    expect(await screen.findByText('These memories are kept private.')).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: 'Sign in' }).length).toBeGreaterThan(0)
  })
})
