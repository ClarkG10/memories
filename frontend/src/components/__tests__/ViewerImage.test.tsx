import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { ViewerImage } from '../ViewerImage'
import { anImage } from '../../test/harness'

function full() {
  return document.querySelector('.viewerimage__full') as HTMLImageElement | null
}

describe('a photograph arriving', () => {
  it('shows the blur and the size the timeline already fetched, straight away', () => {
    const { container } = render(<ViewerImage media={anImage()} alt="An evening" />)

    // Both are on screen before any full-size request has finished, which is
    // why opening a memory is not a wait on a blank rectangle.
    expect(container.querySelector('.viewerimage__blur')).toBeInTheDocument()
    expect(container.querySelector('.viewerimage__quick')).toHaveAttribute(
      'src',
      'http://api.test/api/media/media-1/image?w=640',
    )

    expect(full()).toHaveAttribute('src', 'http://api.test/api/media/media-1/image?w=2400')
  })

  it('marks itself loaded so the layers underneath can clear', () => {
    const { container } = render(<ViewerImage media={anImage()} alt="An evening" />)

    expect(container.querySelector('.viewerimage')).toHaveAttribute('data-loaded', 'false')

    fireEvent.load(full() as HTMLImageElement)

    expect(container.querySelector('.viewerimage')).toHaveAttribute('data-loaded', 'true')
  })

  it('steps down to a smaller size rather than showing a broken photograph', () => {
    render(<ViewerImage media={anImage()} alt="An evening" />)

    /*
     | The server could not render 2400px — too large to decode, a format it
     | cannot read, whatever it was. 1600 very often still works, and this is
     | the difference between the memory being there and a broken-image icon.
     */
    fireEvent.error(full() as HTMLImageElement)
    expect(full()).toHaveAttribute('src', 'http://api.test/api/media/media-1/image?w=1600')

    fireEvent.error(full() as HTMLImageElement)
    expect(full()).toHaveAttribute('src', 'http://api.test/api/media/media-1/image?w=640')
  })

  it('says so, in words, once every size has failed', async () => {
    render(<ViewerImage media={anImage()} alt="An evening" />)

    for (let i = 0; i < 3; i++) fireEvent.error(full() as HTMLImageElement)

    expect(full()).not.toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent("We couldn't load this photograph.")

    // And trying again starts at the top, past the browser's cached failure.
    await userEvent.click(screen.getByRole('button', { name: 'Try again' }))

    expect(full()?.getAttribute('src')).toContain('w=2400')
    expect(full()?.getAttribute('src')).toContain('retry=1')
  })

  it('starts again from the top when a different photograph is shown', () => {
    const { rerender } = render(<ViewerImage media={anImage({ id: 'one' })} alt="One" />)

    fireEvent.error(full() as HTMLImageElement)
    expect(full()?.getAttribute('src')).toContain('w=1600')

    rerender(
      <ViewerImage
        media={anImage({
          id: 'two',
          urls: {
            thumb: 'http://api.test/api/media/two/image?w=640',
            display: 'http://api.test/api/media/two/image?w=1600',
            full: 'http://api.test/api/media/two/image?w=2400',
          },
        })}
        alt="Two"
      />,
    )

    // One photograph's failure is not evidence about the next one.
    expect(full()?.getAttribute('src')).toContain('w=2400')
  })
})
