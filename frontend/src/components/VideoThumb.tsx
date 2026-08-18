import { useEffect, useState } from 'react'
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
 * Drive generates its thumbnail a little after the upload finishes, so the
 * poster is genuinely absent sometimes. Rather than a broken image or a grey
 * box, the fallback is a quiet wash of the archive's own blue — it still reads
 * as a video, just one that is still settling in.
 */
export function VideoThumb({ media, alt, className }: Props) {
  /*
   | Google generates a video's thumbnail only after it has finished processing
   | the upload, so a poster can legitimately be missing for a minute and then
   | appear. Giving up after one miss left the card blank until the whole page
   | was reloaded, which is the one thing someone will not think to do.
   */
  const [attempt, setAttempt] = useState(0)
  const [gaveUp, setGaveUp] = useState(false)

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

  const showPoster = Boolean(media.urls.poster) && !gaveUp

  return (
    <div
      className={`media media--video${className ? ` ${className}` : ''}`}
      style={{ aspectRatio: media.aspect_ratio ?? 16 / 9 }}
    >
      {media.placeholder && (
        <img className="media__placeholder" src={media.placeholder} alt="" aria-hidden="true" />
      )}

      {showPoster ? (
        <img
          className="media__image"
          // The attempt number busts the browser's cache of the 404.
          src={attempt === 0 ? media.urls.poster : `${media.urls.poster}?retry=${attempt}`}
          alt={alt}
          loading="lazy"
          decoding="async"
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
