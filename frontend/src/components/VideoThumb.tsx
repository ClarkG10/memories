import { useState } from 'react'
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
  const [posterFailed, setPosterFailed] = useState(false)

  const showPoster = Boolean(media.urls.poster) && !posterFailed

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
          src={media.urls.poster}
          alt={alt}
          loading="lazy"
          decoding="async"
          onError={() => setPosterFailed(true)}
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
