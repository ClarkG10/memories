import { useCallback, useEffect, useRef, useState } from 'react'
import type { Media } from '../api/types'

interface Props {
  media: Media
  alt: string
}

/**
 * Largest first, then progressively smaller. A photograph the server cannot
 * render at 2400px can very often still be rendered at 640, and one rendition
 * that failed is no reason to show nothing at all.
 */
const LADDER = ['full', 'display', 'thumb'] as const

/**
 * The photograph, on the way in and when it will not come.
 *
 * Three layers, each better than the last and each shown the moment it exists:
 * the inline blur that arrived with the memory, the small rendition the
 * timeline already put in the browser's cache, and then the full-size one.
 * Nothing is ever a blank rectangle, and nothing waits on a network request
 * that has not finished.
 *
 * If every size fails it says so, in words, with a way to try again — where it
 * previously drew the browser's broken-image icon and left the person to guess
 * whether their photograph still existed.
 */
export function ViewerImage({ media, alt }: Props) {
  const sources = LADDER.map((size) => media.urls[size]).filter(
    (url): url is string => typeof url === 'string' && url.length > 0,
  )

  const [step, setStep] = useState(0)
  const [loaded, setLoaded] = useState(false)
  const [attempt, setAttempt] = useState(0)
  const imageRef = useRef<HTMLImageElement | null>(null)

  // Moving to another photograph starts the whole climb again.
  useEffect(() => {
    setStep(0)
    setLoaded(false)
  }, [media.id])

  const source = sources[step]

  useEffect(() => {
    // One already in the browser's cache can finish decoding before React has
    // attached its onLoad, which would leave it hidden behind the blur.
    if (imageRef.current?.complete && imageRef.current.naturalWidth > 0) setLoaded(true)
  }, [source])

  const retry = useCallback(() => {
    setStep(0)
    setLoaded(false)
    setAttempt((n) => n + 1)
  }, [])

  const exhausted = source === undefined

  return (
    <div className="viewerimage" data-loaded={loaded}>
      {media.placeholder && (
        <img className="viewerimage__blur" src={media.placeholder} alt="" aria-hidden="true" />
      )}

      {/*
        | The size the timeline already fetched. It costs nothing — the browser
        | has it — and it is the difference between opening a memory onto a
        | soft blur and opening it onto the photograph.
      */}
      {media.urls.thumb && step === 0 && (
        <img className="viewerimage__quick" src={media.urls.thumb} alt="" aria-hidden="true" />
      )}

      {!exhausted && (
        <img
          ref={imageRef}
          key={`${media.id}-${step}-${attempt}`}
          className="viewerimage__full"
          // The attempt busts a cached failure; without it a retry re-reads
          // the same 404 out of the browser's own cache and changes nothing.
          src={attempt === 0 ? source : `${source}${source.includes('?') ? '&' : '?'}retry=${attempt}`}
          alt={alt}
          decoding="async"
          fetchPriority="high"
          onLoad={() => setLoaded(true)}
          onError={() => {
            setLoaded(false)
            setStep((n) => n + 1)
          }}
        />
      )}

      {exhausted && (
        <div className="viewerimage__failed" role="alert">
          <p>We couldn't load this photograph.</p>
          <button type="button" className="button button--quiet" onClick={retry}>
            Try again
          </button>
        </div>
      )}
    </div>
  )
}
