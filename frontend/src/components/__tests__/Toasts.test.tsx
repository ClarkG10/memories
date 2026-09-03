import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { ToastProvider } from '../Toasts'
import { useToasts } from '../../hooks/useToasts'

function Speaker() {
  const toasts = useToasts()

  return (
    <>
      <button type="button" onClick={() => toasts.say('Saved.')}>
        Say it
      </button>
      <button type="button" onClick={() => toasts.warn('That did not work.')}>
        Warn
      </button>
    </>
  )
}

describe('toasts', () => {
  it('leaves when dismissed, and is gone from the document afterwards', async () => {
    render(
      <ToastProvider>
        <Speaker />
      </ToastProvider>,
    )

    await userEvent.click(screen.getByRole('button', { name: 'Say it' }))
    expect(await screen.findByText('Saved.')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Dismiss' }))

    /*
     | Still there, on its way out. A confirmation that is taken off the screen
     | the instant it is dismissed disappears in the middle of being read; this
     | one fades first, and cannot be dismissed twice while it does.
     */
    expect(screen.getByText('Saved.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Dismiss' })).toBeDisabled()

    // And then it does go.
    await waitFor(() => expect(screen.queryByText('Saved.')).not.toBeInTheDocument())
  })

  it('keeps a failure until it is dealt with', async () => {
    render(
      <ToastProvider>
        <Speaker />
      </ToastProvider>,
    )

    await userEvent.click(screen.getByRole('button', { name: 'Warn' }))
    expect(await screen.findByText('That did not work.')).toBeInTheDocument()

    // A failure has no timer; it waits to be dismissed.
    await new Promise((resolve) => setTimeout(resolve, 100))
    expect(screen.getByText('That did not work.')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Dismiss' }))
    await waitFor(() => expect(screen.queryByText('That did not work.')).not.toBeInTheDocument())
  })
})
