import { useEffect, useRef, useState } from 'react'
import { formatDuration } from '../lib/dates'
import type { Media } from '../api/types'

interface Props {
  media: Media
  alt: string
  className?: string
}

/**
 * How a video announces itself in the timeline.
 *
 * A still frame, so a video can be recognised before it is played. Drive
 * generates one only after it has finished processing the upload, so it can
 * legitimately be missing for a minute and then appear — hence the retries.
 * Failing that, the fallback is a quiet wash of the archive's own blue: it
 * still reads as a video, just one still settling in.
 */
export function VideoThumb({ media, alt, className }: Props) {
  const [attempt, setAttempt] = useState(0)
  const [gaveUp, setGaveUp] = useState(false)

  /*
   | The poster is transparent until this says otherwise, exactly as a
   | photograph is, so it fades in over the blur rather than snapping on.
   | Forgetting to set it is why every video poster loaded correctly and then
   | rendered completely invisible.
   */
  const [loaded, setLoaded] = useState(false)
  const imageRef = useRef<HTMLImageElement | null>(null)

  const showPoster = Boolean(media.urls.poster) && !gaveUp

  useEffect(() => {
    // A poster already in the browser's cache can finish before React attaches
    // its onLoad, which would leave it hidden for good.
    if (imageRef.current?.complete && imageRef.current.naturalWidth > 0) setLoaded(true)
  }, [media.urls.poster, attempt])

  useEffect(() => {
    if (attempt === 0 || gaveUp) return

    // Spaced out, and only a few times: this is a picture, not a heartbeat.
    const delay = [4000, 12000, 30000][attempt - 1]

    if (delay === undefined) {
      setGaveUp(true)

      return
    }

    const timer = window.setTimeout(() => setAttempt((n) => n + 1), delay)

    return () => window.clearTimeout(timer)
  }, [attempt, gaveUp])

  return (
    <div
      className={`media media--video${className ? ` ${className}` : ''}`}
      style={{ aspectRatio: media.aspect_ratio ?? 16 / 9 }}
      data-loaded={loaded}
    >
      {media.placeholder && (
        <img className="media__placeholder" src={media.placeholder} alt="" aria-hidden="true" />
      )}

      {showPoster ? (
        <img
          ref={imageRef}
          className="media__image"
          // The attempt number busts the browser's cache of a 404.
          src={attempt === 0 ? media.urls.poster : `${media.urls.poster}?retry=${attempt}`}
          alt={alt}
          loading="lazy"
          decoding="async"
          onLoad={() => setLoaded(true)}
          onError={() => setAttempt((n) => n + 1)}
        />
      ) : (
        <div className="media__pending" role="img" aria-label={alt} />
      )}

      <span className="media__play" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none">
          <path d="M9 7.5v9l7-4.5-7-4.5Z" fill="currentColor" />
        </svg>
      </span>

      {media.duration_ms !== null && (
        <span className="media__duration">{formatDuration(media.duration_ms)}</span>
      )}
    </div>
  )
}
