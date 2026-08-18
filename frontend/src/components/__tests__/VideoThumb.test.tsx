import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { VideoThumb } from '../VideoThumb'
import { aVideo } from '../../test/harness'

/**
 * The bug these exist for: the poster downloaded correctly, was in the DOM,
 * and rendered completely invisible — because `.media__image` is transparent
 * until its wrapper is marked loaded, and this component never marked it.
 *
 * Asserting the image is present was not enough to catch that. These assert
 * the state that actually decides whether anyone can see it.
 */
describe('a video in the timeline', () => {
  it('shows its poster once the frame has loaded', () => {
    const { container } = render(<VideoThumb media={aVideo()} alt="Our first dance" />)

    const wrapper = container.querySelector('.media--video')!
    const poster = container.querySelector('.media__image')!

    // Hidden until it has actually arrived, so it fades in rather than snaps.
    expect(wrapper).toHaveAttribute('data-loaded', 'false')

    fireEvent.load(poster)

    expect(wrapper).toHaveAttribute('data-loaded', 'true')
  })

  it('falls back to the quiet wash when there is no poster at all', () => {
    const media = aVideo({ urls: { stream: 'http://api.test/stream' } })
    const { container } = render(<VideoThumb media={media} alt="Our first dance" />)

    expect(container.querySelector('.media__image')).toBeNull()
    expect(container.querySelector('.media__pending')).toBeInTheDocument()
  })

  it('tries again when the poster is not ready yet, rather than giving up', () => {
    const { container } = render(<VideoThumb media={aVideo()} alt="Our first dance" />)

    const poster = () => container.querySelector('.media__image') as HTMLImageElement

    expect(poster().src).not.toContain('retry=')

    // Drive is still processing the video, so the poster 404s.
    fireEvent.error(poster())

    // A fresh URL, because the browser will otherwise serve the cached 404.
    expect(poster().src).toContain('retry=1')
  })

  it('still says how long the video runs', () => {
    render(<VideoThumb media={aVideo({ duration_ms: 42_000 })} alt="Our first dance" />)

    expect(screen.getByText('0:42')).toBeInTheDocument()
  })
})
