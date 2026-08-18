import { useCallback, useEffect, useRef, useState } from 'react'
import { useMemory } from '../api/queries'
import { useOverlay } from '../hooks/useOverlay'
import { formatLongDate } from '../lib/dates'
import { Notice } from './Notice'
import type { Media, Memory } from '../api/types'

interface Props {
  memoryId: string
  initialIndex: number
  onClose: () => void
  canManage: boolean
  onEdit: (memory: Memory) => void
}

/** Ignore an accidental drag; a swipe is a deliberate movement. */
const SWIPE_THRESHOLD = 56

/**
 * A memory, opened.
 *
 * Not a modal with a picture in it — the room dims, the photograph is as large
 * as the screen allows, and the words sit quietly underneath. Arrow keys and
 * swipes move between the media; Escape and the browser's back button both
 * close it.
 */
export function MemoryViewer({ memoryId, initialIndex, onClose, canManage, onEdit }: Props) {
  const query = useMemory(memoryId)
  const [index, setIndex] = useState(initialIndex)
  const containerRef = useOverlay(true, onClose)
  const touchStart = useRef<{ x: number; y: number } | null>(null)

  const media = query.data?.media ?? []
  const current: Media | undefined = media[index]
  const count = media.length

  // A memory may have fewer media than the index it was opened at — for
  // instance if one was removed in another tab.
  useEffect(() => {
    if (count > 0 && index > count - 1) setIndex(count - 1)
  }, [count, index])

  const step = useCallback(
    (direction: 1 | -1) => {
      setIndex((value) => {
        const next = value + direction

        if (next < 0 || next > count - 1) return value

        return next
      })
    },
    [count],
  )

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'ArrowRight') step(1)
      if (event.key === 'ArrowLeft') step(-1)
    }

    document.addEventListener('keydown', onKeyDown)

    return () => document.removeEventListener('keydown', onKeyDown)
  }, [step])

  const onTouchStart = (event: React.TouchEvent) => {
    const touch = event.touches[0]
    touchStart.current = { x: touch.clientX, y: touch.clientY }
  }

  const onTouchEnd = (event: React.TouchEvent) => {
    const start = touchStart.current
    touchStart.current = null

    if (!start) return

    const touch = event.changedTouches[0]
    const dx = touch.clientX - start.x
    const dy = touch.clientY - start.y

    // Only a mostly-horizontal movement counts, so scrolling never flips the
    // photograph by accident.
    if (Math.abs(dx) < SWIPE_THRESHOLD || Math.abs(dx) < Math.abs(dy)) return

    step(dx < 0 ? 1 : -1)
  }

  return (
    <div
      className="viewer"
      role="dialog"
      aria-modal="true"
      aria-label={query.data?.title ?? 'Memory'}
      ref={containerRef}
      tabIndex={-1}
    >
      <div className="viewer__bar">
        <span className="viewer__count">
          {count > 1 ? `${index + 1} / ${count}` : ''}
        </span>

        <div className="viewer__actions">
          {/*
            | Editing lives here as well as on the card. This is where a typo
            | is actually noticed — while looking at the memory — and closing
            | the viewer to go and find the card again is a poor answer to it.
          */}
          {canManage && query.data && (
            <button
              type="button"
              className="viewer__edit"
              onClick={() => onEdit(query.data)}
            >
              Edit
            </button>
          )}

          <button
            type="button"
            className="viewer__close"
            onClick={onClose}
            aria-label="Close"
            data-autofocus
          >
          <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
            <path
              d="M6 6l12 12M18 6L6 18"
              stroke="currentColor"
              strokeWidth="1.4"
              strokeLinecap="round"
              />
            </svg>
          </button>
        </div>
      </div>

      <div className="viewer__stage" onTouchStart={onTouchStart} onTouchEnd={onTouchEnd}>
        <div className="viewer__frame">
          {query.isPending && <p className="viewer__loading">Opening…</p>}

          {query.isError && (
            <Notice
              message="We couldn't open this memory just now."
              actionLabel="Try again"
              onAction={() => void query.refetch()}
            />
          )}

          {current && <Stage media={current} title={query.data?.title ?? ''} />}
        </div>

        {count > 1 && (
          <>
            <button
              type="button"
              className="viewer__step viewer__step--prev"
              onClick={() => step(-1)}
              disabled={index === 0}
              aria-label="Previous"
            >
              <Chevron direction="left" />
            </button>

            <button
              type="button"
              className="viewer__step viewer__step--next"
              onClick={() => step(1)}
              disabled={index === count - 1}
              aria-label="Next"
            >
              <Chevron direction="right" />
            </button>
          </>
        )}
      </div>

      <div className="viewer__caption">
        {query.data && (
          <>
            <time className="label viewer__date" dateTime={query.data.memory_date}>
              {formatLongDate(query.data.memory_date)}
            </time>

            <h2 className="viewer__title">{query.data.title}</h2>

            {query.data.description && (
              <p className="viewer__description">{query.data.description}</p>
            )}

            {(query.data.location || query.data.album) && (
              <p className="viewer__where">
                {[query.data.album, query.data.location].filter(Boolean).join(' · ')}
              </p>
            )}

            {count > 1 && (
              <div className="viewer__dots" role="group" aria-label="Media in this memory">
                {media.map((item, position) => (
                  <button
                    key={item.id}
                    type="button"
                    className="viewer__dot"
                    aria-current={position === index}
                    aria-label={`Show ${position + 1} of ${count}`}
                    onClick={() => setIndex(position)}
                  />
                ))}
              </div>
            )}
          </>
        )}
      </div>
    </div>
  )
}

function Stage({ media, title }: { media: Media; title: string }) {
  if (media.type === 'video') {
    return (
      <video
        key={media.id}
        className="viewer__video"
        src={media.urls.stream}
        poster={media.urls.poster}
        controls
        playsInline
        preload="metadata"
      >
        <track kind="captions" />
        Your browser cannot play this video.
      </video>
    )
  }

  return (
    <img
      key={media.id}
      className="viewer__media"
      src={media.urls.full ?? media.urls.display}
      alt={title}
      decoding="async"
    />
  )
}

function Chevron({ direction }: { direction: 'left' | 'right' }) {
  return (
    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
      <path
        d={direction === 'left' ? 'M15 5l-7 7 7 7' : 'M9 5l7 7-7 7'}
        stroke="currentColor"
        strokeWidth="1.4"
        strokeLinecap="round"
        strokeLinejoin="round"
        fill="none"
      />
    </svg>
  )
}
