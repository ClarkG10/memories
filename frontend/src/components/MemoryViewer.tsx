import { useCallback, useEffect, useRef, useState } from 'react'
import { useMemory } from '../api/queries'
import { useFullscreen } from '../hooks/useFullscreen'
import { useOverlay } from '../hooks/useOverlay'
import { formatLongDate } from '../lib/dates'
import { Notice } from './Notice'
import { referenceOf } from '../api/client'
import { ViewerImage } from './ViewerImage'
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
 * Past this many photographs, dots stop being a control and become a problem.
 *
 * Each one is six pixels of ink inside a forty-four pixel target, so a memory
 * holding forty-nine of them asks for 2,156 pixels of row on a 1,440 pixel
 * screen — and they are useless to aim at long before that. A scrubber holds
 * its width whatever the count.
 */
const DOTS_UP_TO = 12

/**
 * A memory, opened.
 *
 * Not a modal with a picture in it — the room dims, the photograph is as large
 * as the screen allows, and the words sit quietly underneath. Arrow keys and
 * swipes move between the media; Escape and the browser's back button both
 * close it.
 *
 * There is a quieter state past that one. Sometimes the date and the title are
 * the whole point of a memory, and sometimes they are in the way of it: "bare"
 * takes the words away and gives the photograph everything, edge to edge. It
 * is reached by pressing the photograph, or by the control in the bar, and it
 * takes the browser's own chrome with it where the browser allows that.
 */
export function MemoryViewer({ memoryId, initialIndex, onClose, canManage, onEdit }: Props) {
  const query = useMemory(memoryId)
  const [index, setIndex] = useState(initialIndex)
  const [bare, setBare] = useState(false)
  const touchStart = useRef<{ x: number; y: number } | null>(null)
  /* A swipe ends in a click as far as the browser is concerned. */
  const swiped = useRef(false)
  /* Only give the browser's chrome back if going bare is what took it. */
  const tookTheChrome = useRef(false)

  const leaveBare = useCallback(() => {
    setBare(false)

    if (!tookTheChrome.current) return

    tookTheChrome.current = false

    if (document.fullscreenElement) void document.exitFullscreen().catch(() => undefined)
  }, [])

  const containerRef = useOverlay(true, () => {
    // Escape steps back out of bare before it closes the memory altogether.
    if (bare) {
      leaveBare()

      return
    }

    onClose()
  })
  const { isFullscreen, toggle: toggleFullscreen, supported: canGoFullscreen } = useFullscreen(containerRef)

  const toggleBare = useCallback(() => {
    // The click a swipe leaves behind would otherwise flip this on every
    // sideways movement through a memory.
    if (swiped.current) {
      swiped.current = false

      return
    }

    if (bare) {
      leaveBare()

      return
    }

    setBare(true)

    if (canGoFullscreen && !isFullscreen) {
      tookTheChrome.current = true
      toggleFullscreen()
    }
  }, [bare, canGoFullscreen, isFullscreen, leaveBare, toggleFullscreen])

  /*
   | Leaving fullscreen by any other route — Escape, which the browser handles
   | itself before the page hears it, or the system's own control — should come
   | back to the words as well. Anything else strands someone in a bare screen
   | wondering where the memory went.
   */
  useEffect(() => {
    if (isFullscreen || !tookTheChrome.current) return

    tookTheChrome.current = false
    setBare(false)
  }, [isFullscreen])

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

  /*
   | Fetch the photographs either side of this one, quietly, while it is being
   | looked at. Stepping through a memory is a rhythm — press, look, press —
   | and a spinner between every press breaks it. By the time the arrow is
   | pressed the next one is usually already in the browser.
   */
  /*
   | Depending on the URLs rather than on the array: `media` is rebuilt on
   | every render, so an effect keyed on it would fetch the neighbours again
   | on every keystroke, hover and state change. A string is stable.
   */
  const nextSource = previewSource(media[index + 1])
  const previousSource = previewSource(media[index - 1])

  useEffect(() => {
    for (const source of [nextSource, previousSource]) {
      if (source === null) continue

      const image = new Image()
      // Behind whatever the person is actually waiting for.
      image.fetchPriority = 'low'
      image.decoding = 'async'
      image.src = source
    }
  }, [nextSource, previousSource])

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
    swiped.current = false
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

    swiped.current = true
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
      data-bare={bare}
    >
      <div className="viewer__bar">
        <span className="viewer__count">
          {count > 1 && !bare ? `${index + 1} / ${count}` : ''}
        </span>

        <div className="viewer__actions">
          {/*
            | Editing lives here as well as on the card. This is where a typo
            | is actually noticed — while looking at the memory — and closing
            | the viewer to go and find the card again is a poor answer to it.
          */}
          <button
            type="button"
            className="viewer__icon"
            onClick={toggleBare}
            aria-label={bare ? 'Show the words' : 'Photograph only'}
            title={bare ? 'Show the words' : 'Photograph only'}
          >
            {bare ? <ContractIcon /> : <ExpandIcon />}
          </button>

          {/* Kept as its own thing: a bigger window, with the words still on. */}
          {canGoFullscreen && !bare && (
            <button
              type="button"
              className="viewer__icon"
              onClick={toggleFullscreen}
              aria-label={isFullscreen ? 'Leave full screen' : 'View full screen'}
              title={isFullscreen ? 'Leave full screen' : 'View full screen'}
            >
              {isFullscreen ? <ExitFullscreenIcon /> : <FullscreenIcon />}
            </button>
          )}

          {canManage && !bare && query.data && (
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
              reference={referenceOf(query.error)}
            />
          )}

          {current && (
            <Stage
              media={current}
              title={query.data?.title ?? ''}
              onToggleBare={toggleBare}
            />
          )}
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

      {/*
        | Taken off the screen rather than merely hidden on it. "Not on this
        | screen" is a fact about the memory being shown, not about the
        | stylesheet, and leaving the words in the document to be covered up
        | is how they come back the moment a rule moves.
      */}
      <div className="viewer__caption" hidden={bare}>
        {query.data && !bare && (
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

            {count > 1 && count <= DOTS_UP_TO && (
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

            {count > DOTS_UP_TO && (
              /*
                | A range input rather than something bespoke: it can be
                | dragged with a thumb, stepped with the arrow keys, and is
                | announced properly — all of which the dots gave up the moment
                | there were too many of them to aim at.
              */
              <div className="viewer__scrub">
                <input
                  type="range"
                  className="viewer__scrubber"
                  min={1}
                  max={count}
                  step={1}
                  value={index + 1}
                  onChange={(event) => setIndex(Number(event.target.value) - 1)}
                  aria-label="Move through this memory"
                  aria-valuetext={`${index + 1} of ${count}`}
                />
              </div>
            )}
          </>
        )}
      </div>
    </div>
  )
}

/** The one image worth having ready for a photograph that is coming next. */
function previewSource(media: Media | undefined): string | null {
  if (media === undefined) return null

  const source =
    media.type === 'video' ? media.urls.poster : (media.urls.full ?? media.urls.display)

  return source ?? null
}

function Stage({
  media,
  title,
  onToggleBare,
}: {
  media: Media
  title: string
  onToggleBare: () => void
}) {
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

  /*
   | The photograph is wrapped in the control rather than having an invisible
   | one laid over it: a real button is reachable by keyboard and announces
   | itself, and there is no stack of transparent layers to get wrong.
   |
   | Deliberately unlabelled, so the photograph's own description names it.
   | Labelling it would say "Photograph only" twice in a row to anyone moving
   | through by keyboard — once here and once in the bar — and would talk over
   | the one thing on the screen worth describing.
   */
  return (
    <button type="button" className="viewer__surface" onClick={onToggleBare}>
      <ViewerImage media={media} alt={title} />
    </button>
  )
}

/** Four corners opening outwards. */
function FullscreenIcon() {
  return (
    <svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true" fill="none">
      <path
        d="M9 4H4v5M15 4h5v5M15 20h5v-5M9 20H4v-5"
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  )
}

/** The same four corners, folding back in. */
function ExitFullscreenIcon() {
  return (
    <svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true" fill="none">
      <path
        d="M4 9h5V4M20 9h-5V4M20 15h-5v5M4 15h5v5"
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  )
}

/** Two diagonals pushing out to the corners: give it everything. */
function ExpandIcon() {
  return (
    <svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true" fill="none">
      <path
        d="M14 4h6v6M10 20H4v-6M20 4l-7 7M4 20l7-7"
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  )
}

/** The same diagonals drawing back in. */
function ContractIcon() {
  return (
    <svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true" fill="none">
      <path
        d="M20 10h-6V4M4 14h6v6M14 10l6-6M10 14l-6 6"
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
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
