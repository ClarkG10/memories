import { fireEvent, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import {
  aTimelineMemory,
  anArchive,
  mockApi,
  renderArchive,
  timelinePage,
} from '../../test/harness'

const ARCHIVE = /\/api\/archive/
const TIMELINE = /\/api\/timeline\?/
const YEARS = /\/api\/timeline\/years/

function asOwner() {
  return [
    { match: ARCHIVE, body: { data: anArchive({ can_manage: true }) } },
    { match: YEARS, body: { data: [{ year: 2026, count: 1 }] } },
    { match: TIMELINE, body: timelinePage([aTimelineMemory()]) },
  ]
}

describe('the owner’s controls', () => {
  it('offers adding a memory, and never shows it to a visitor', async () => {
    mockApi(asOwner())

    renderArchive()

    expect(await screen.findByRole('button', { name: 'Add a memory' })).toBeInTheDocument()
  })

  it('hides every control from someone just looking', async () => {
    mockApi([
      { match: ARCHIVE, body: { data: anArchive({ can_manage: false }) } },
      { match: YEARS, body: { data: [] } },
      { match: TIMELINE, body: timelinePage([aTimelineMemory()]) },
    ])

    renderArchive()

    await screen.findByText('That Beautiful Evening')

    expect(screen.queryByRole('button', { name: 'Add a memory' })).not.toBeInTheDocument()
    expect(
      screen.queryByRole('button', { name: /More for That Beautiful Evening/ }),
    ).not.toBeInTheDocument()
  })
})

describe('removing a memory', () => {
  it('asks first, gently, and says what will happen', async () => {
    mockApi(asOwner())

    renderArchive()

    await userEvent.click(
      await screen.findByRole('button', { name: 'More for That Beautiful Evening' }),
    )
    await userEvent.click(screen.getByRole('menuitem', { name: 'Remove memory' }))

    const dialog = await screen.findByRole('alertdialog')

    expect(screen.getByText('Remove this memory?')).toBeInTheDocument()
    expect(
      screen.getByText(
        'Your memory and its photos and videos will be removed from the timeline and from your Google Drive.',
      ),
    ).toBeInTheDocument()
    expect(dialog).toBeInTheDocument()
  })

  it('does nothing at all if the question is declined', async () => {
    const { calls } = mockApi(asOwner())

    renderArchive()

    await userEvent.click(
      await screen.findByRole('button', { name: 'More for That Beautiful Evening' }),
    )
    await userEvent.click(screen.getByRole('menuitem', { name: 'Remove memory' }))
    await userEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    await waitFor(() => {
      expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
    })

    expect(calls.some((call) => call.method === 'DELETE')).toBe(false)
  })

  it('removes it once confirmed, and says so', async () => {
    const { calls } = mockApi([
      ...asOwner(),
      { method: 'DELETE', match: /\/api\/memories\/memory-1/, body: { data: { removed: true } } },
    ])

    renderArchive()

    await userEvent.click(
      await screen.findByRole('button', { name: 'More for That Beautiful Evening' }),
    )
    await userEvent.click(screen.getByRole('menuitem', { name: 'Remove memory' }))
    await userEvent.click(screen.getByRole('button', { name: 'Remove memory' }))

    await waitFor(() => {
      expect(
        calls.some((call) => call.method === 'DELETE' && call.url.includes('/api/memories/memory-1')),
      ).toBe(true)
    })

    expect(await screen.findByText('That memory has been removed.')).toBeInTheDocument()
  })

  it('admits it when removal does not work, and keeps the way out open', async () => {
    mockApi([
      ...asOwner(),
      {
        method: 'DELETE',
        match: /\/api\/memories\/memory-1/,
        status: 503,
        body: { message: "We couldn't completely remove this memory." },
      },
    ])

    renderArchive()

    await userEvent.click(
      await screen.findByRole('button', { name: 'More for That Beautiful Evening' }),
    )
    await userEvent.click(screen.getByRole('menuitem', { name: 'Remove memory' }))
    await userEvent.click(screen.getByRole('button', { name: 'Remove memory' }))

    expect(
      await screen.findByText("We couldn't completely remove this memory."),
    ).toBeInTheDocument()

    // The dialog stays, so it can be tried again.
    expect(screen.getByRole('alertdialog')).toBeInTheDocument()
  })
})

describe('adding a memory', () => {
  it('will not save without a photograph and a title', async () => {
    mockApi(asOwner())

    renderArchive()

    await userEvent.click(await screen.findByRole('button', { name: 'Add a memory' }))

    expect(await screen.findByText('Choose photos and videos')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Save memory' })).toBeDisabled()
  })

  it('previews what has been chosen', async () => {
    mockApi(asOwner())

    renderArchive()

    await userEvent.click(await screen.findByRole('button', { name: 'Add a memory' }))

    const file = new File(['photo-bytes'], 'evening.jpg', { type: 'image/jpeg' })
    await userEvent.upload(screen.getByLabelText('Photos and videos'), file)

    expect(await screen.findByRole('button', { name: 'Remove evening.jpg' })).toBeInTheDocument()

    // Still not saveable: it has no title yet.
    expect(screen.getByRole('button', { name: 'Save memory' })).toBeDisabled()

    await userEvent.type(screen.getByLabelText('Title'), 'That Beautiful Evening')

    expect(screen.getByRole('button', { name: 'Save memory' })).toBeEnabled()
  })

  it('lets a whole title be typed without losing the caret', async () => {
    // A regression guard: the overlay used to re-run its focus trap on every
    // render, which pulled focus back to the panel after the first keystroke
    // and made the form impossible to fill in.
    mockApi(asOwner())

    renderArchive()

    await userEvent.click(await screen.findByRole('button', { name: 'Add a memory' }))

    const title = screen.getByLabelText('Title')
    await userEvent.type(title, 'That Beautiful Evening')

    expect(title).toHaveValue('That Beautiful Evening')
    expect(title).toHaveFocus()

    const where = screen.getByLabelText('Where (optional)')
    await userEvent.type(where, 'Butuan')

    expect(where).toHaveValue('Butuan')
  })

  it('turns away a file that is not a photo or a video', async () => {
    mockApi(asOwner())

    renderArchive()

    await userEvent.click(await screen.findByRole('button', { name: 'Add a memory' }))

    /*
     | Dropped rather than picked: the file input carries accept="image/*,
     | video/*", so the browser (and userEvent) filters a text file out before
     | the app ever sees it. Drag-and-drop has no such filter, which is exactly
     | why the app validates too.
     */
    const file = new File(['notes'], 'notes.txt', { type: 'text/plain' })

    fireEvent.drop(screen.getByText('Choose photos and videos').closest('.dropzone')!, {
      dataTransfer: { files: [file] },
    })

    expect(await screen.findByText("notes.txt isn't a photo or a video.")).toBeInTheDocument()
  })

  it('keeps the files and the words when saving fails', async () => {
    mockApi([
      ...asOwner(),
      // The upload cannot even be opened.
      { method: 'POST', match: /\/api\/uploads$/, status: 503, body: { message: 'Storage is unavailable.' } },
    ])

    renderArchive()

    await userEvent.click(await screen.findByRole('button', { name: 'Add a memory' }))

    const file = new File(['photo-bytes'], 'evening.jpg', { type: 'image/jpeg' })
    await userEvent.upload(screen.getByLabelText('Photos and videos'), file)
    await userEvent.type(screen.getByLabelText('Title'), 'That Beautiful Evening')

    await userEvent.click(screen.getByRole('button', { name: 'Save memory' }))

    expect(await screen.findByText('Storage is unavailable.')).toBeInTheDocument()

    // Nothing was thrown away: the photo, the title, and a way to try again.
    expect(screen.getByRole('button', { name: 'Remove evening.jpg' })).toBeInTheDocument()
    expect(screen.getByLabelText('Title')).toHaveValue('That Beautiful Evening')
    expect(screen.getByRole('button', { name: 'Try again' })).toBeEnabled()
  })

  it('warns up front when there is nowhere to store anything', async () => {
    mockApi([
      { match: ARCHIVE, body: { data: anArchive({ can_manage: true, storage_connected: false }) } },
      { match: YEARS, body: { data: [] } },
      { match: TIMELINE, body: timelinePage([aTimelineMemory()]) },
    ])

    renderArchive()

    await userEvent.click(await screen.findByRole('button', { name: 'Add a memory' }))

    expect(
      await screen.findByText(/isn't connected to Google Drive yet/),
    ).toBeInTheDocument()
  })
})
