import { useEffect, useRef, useState } from 'react'
import type { Media } from '../api/types'

interface Props {
  media: Media
  /** Which rendition to request. The timeline wants thumbs; the viewer wants full. */
  size?: 'thumb' | 'display' | 'full'
  alt: string
  className?: string
  /** Above-the-fold media should not wait for the lazy loader. */
  eager?: boolean
}

/**
 * A photograph, and the small ceremony of getting it onto the screen.
 *
 * The blurred placeholder that arrives with the timeline payload is shown
 * immediately and dissolves once the real image has decoded, so a memory is
 * never a blank rectangle. The space is reserved from the stored aspect ratio,
 * so nothing below it shifts when the photo lands.
 */
export function MediaImage({ media, size = 'thumb', alt, className, eager = false }: Props) {
  const [loaded, setLoaded] = useState(false)
  const imageRef = useRef<HTMLImageElement | null>(null)

  const source = media.urls[size] ?? media.urls.display ?? media.urls.thumb ?? media.urls.poster

  useEffect(() => {
    // An image restored from the browser cache can finish before React attaches
    // its onLoad, which would leave the placeholder up for good.
    if (imageRef.current?.complete) setLoaded(true)
  }, [source])

  return (
    <div
      className={`media${className ? ` ${className}` : ''}`}
      style={{ aspectRatio: media.aspect_ratio ?? undefined }}
      data-loaded={loaded}
    >
      {media.placeholder && (
        <img className="media__placeholder" src={media.placeholder} alt="" aria-hidden="true" />
      )}

      {source && (
        <img
          ref={imageRef}
          className="media__image"
          src={source}
          alt={alt}
          loading={eager ? 'eager' : 'lazy'}
          decoding="async"
          width={media.width ?? undefined}
          height={media.height ?? undefined}
          onLoad={() => setLoaded(true)}
          /* A photo that will not load should not sit as a broken icon. */
          onError={() => setLoaded(false)}
        />
      )}
    </div>
  )
}
