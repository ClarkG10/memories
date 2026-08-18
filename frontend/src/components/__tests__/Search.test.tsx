import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import {
  aTimelineMemory,
  anArchive,
  currentLocation,
  mockApi,
  renderArchive,
  timelinePage,
} from '../../test/harness'

const ARCHIVE = /\/api\/archive/
const YEARS = /\/api\/timeline\/years/
const TIMELINE = /\/api\/timeline\?/

function withResults(seen: string[]) {
  mockApi([
    { match: ARCHIVE, body: { data: anArchive() } },
    { match: YEARS, body: { data: [{ year: 2026, count: 2 }] } },
    {
      match: TIMELINE,
      body: (url: string) => {
        seen.push(url)

        const phrase = new URL(url, 'http://api.test').searchParams.get('q') ?? ''

        if (phrase === '') {
          return timelinePage([
            aTimelineMemory({ id: 'a', title: 'That Beautiful Evening' }),
            aTimelineMemory({ id: 'b', title: 'Breakfast in the rain' }),
          ])
        }

        if (phrase.toLowerCase().includes('evening')) {
          return timelinePage([aTimelineMemory({ id: 'a', title: 'That Beautiful Evening' })])
        }

        return timelinePage([])
      },
    },
  ])
}

describe('searching the archive', () => {
  it('asks the server for what was typed', async () => {
    const seen: string[] = []
    withResults(seen)

    renderArchive()

    expect(await screen.findByText('That Beautiful Evening')).toBeInTheDocument()

    await userEvent.type(screen.getByRole('searchbox'), 'evening')

    await waitFor(() => {
      expect(seen.some((url) => url.includes('q=evening'))).toBe(true)
    })

    await waitFor(() => {
      expect(screen.queryByText('Breakfast in the rain')).not.toBeInTheDocument()
    })

    expect(screen.getByText('That Beautiful Evening')).toBeInTheDocument()
  })

  it('waits for typing to settle rather than firing on every keystroke', async () => {
    const seen: string[] = []
    withResults(seen)

    renderArchive()

    await screen.findByText('That Beautiful Evening')

    const before = seen.length
    await userEvent.type(screen.getByRole('searchbox'), 'evening')

    // Seven characters must not be seven requests.
    await waitFor(() => expect(seen.length).toBeGreaterThan(before))

    expect(seen.length - before).toBeLessThan(4)
  })

  it('says nothing matched rather than offering to start the archive', async () => {
    const seen: string[] = []
    withResults(seen)

    renderArchive()

    await screen.findByText('That Beautiful Evening')

    await userEvent.type(screen.getByRole('searchbox'), 'rollerblading')

    /*
     | Offering "add your first memory" to someone who has sixteen of them and
     | mistyped one word is the interface not listening.
     */
    expect(await screen.findByText(/Nothing matches/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /first memory/i })).not.toBeInTheDocument()
  })

  it('puts the phrase in the address bar, so a search can be sent to someone', async () => {
    const seen: string[] = []
    withResults(seen)

    renderArchive()

    await screen.findByText('That Beautiful Evening')
    await userEvent.type(screen.getByRole('searchbox'), 'evening')

    await waitFor(() => {
      expect(currentLocation().search).toContain('q=evening')
    })
  })

  it('opens already searching when the address says so', async () => {
    const seen: string[] = []
    withResults(seen)

    renderArchive('/?q=evening')

    expect(await screen.findByDisplayValue('evening')).toBeInTheDocument()

    await waitFor(() => {
      expect(seen.some((url) => url.includes('q=evening'))).toBe(true)
    })
  })

  it('gives the whole archive back when cleared', async () => {
    const seen: string[] = []
    withResults(seen)

    renderArchive('/?q=evening')

    await screen.findByText('That Beautiful Evening')
    await waitFor(() => expect(screen.queryByText('Breakfast in the rain')).not.toBeInTheDocument())

    await userEvent.click(screen.getByRole('button', { name: 'Clear the search' }))

    expect(await screen.findByText('Breakfast in the rain')).toBeInTheDocument()
  })
})
