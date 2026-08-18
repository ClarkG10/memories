import { MediaImage } from './MediaImage'
import { VideoThumb } from './VideoThumb'
import { MemoryActions } from './MemoryActions'
import { useReveal } from '../hooks/useReveal'
import { usePrefetchMemory } from '../api/queries'
import { formatDayAndMonth } from '../lib/dates'
import type { TimelineMemory } from '../api/types'

interface Props {
  memory: TimelineMemory
  /** Alternates the layout so the column has a rhythm. */
  flip: boolean
  /** The first memories on screen should not wait for the lazy loader. */
  eager: boolean
  canManage: boolean
  onOpen: (memoryId: string, mediaIndex?: number) => void
  onEdit: (memory: TimelineMemory) => void
  onRemove: (memory: TimelineMemory) => void
}

/**
 * One memory, as it sits in the timeline.
 *
 * The photograph is the whole point, so it takes the space and the words sit
 * underneath it in the order they matter: when, then what, then where.
 */
export function MemoryPlate({
  memory,
  flip,
  eager,
  canManage,
  onOpen,
  onEdit,
  onRemove,
}: Props) {
  const { ref, visible } = useReveal<HTMLElement>()
  const prefetch = usePrefetchMemory()

  // Defensive: a memory with no usable preview is skipped rather than allowed
  // to take the timeline down with it.
  const preview = Array.isArray(memory.preview) ? memory.preview : []
  const [lead, ...companions] = preview
  const visibleCompanions = companions.slice(0, 2)

  // A memory can hold more than the timeline shows; the last companion says so.
  const hidden = memory.media_count - 1 - visibleCompanions.length

  if (!lead) return null

  return (
    <article
      ref={ref}
      className="plate reveal"
      data-visible={visible}
      data-flip={flip}
      data-testid="memory-plate"
      /*
       | Pointing at a memory, or reaching it with the keyboard, is as good a
       | signal as a click that it is about to be opened — and it arrives a
       | few hundred milliseconds earlier, which is most of the wait.
       */
      onPointerEnter={() => prefetch(memory.id, lead?.urls)}
      onFocus={() => prefetch(memory.id, lead?.urls)}
    >
      <div
        className={
          visibleCompanions.length > 0
            ? 'plate__inner'
            : `plate__inner plate__inner--${orientation(lead.aspect_ratio)}`
        }
      >
        {visibleCompanions.length > 0 ? (
          <div className="plate__cluster">
            <button
              type="button"
              className="plate__frame plate__lead"
              onClick={() => onOpen(memory.id, 0)}
              aria-label={`Open ${memory.title}`}
            >
              <Thumb media={lead} title={memory.title} eager={eager} />
            </button>

            <div className="plate__companions">
              {visibleCompanions.map((media, index) => (
                <button
                  key={media.id}
                  type="button"
                  className="plate__frame plate__companion"
                  onClick={() => onOpen(memory.id, index + 1)}
                  aria-label={`Open ${memory.title}, photo ${index + 2}`}
                >
                  <Thumb media={media} title={memory.title} eager={false} />

                  {hidden > 0 && index === visibleCompanions.length - 1 && (
                    <span className="plate__more" aria-hidden="true">
                      +{hidden}
                    </span>
                  )}
                </button>
              ))}
            </div>
          </div>
        ) : (
          <button
            type="button"
            className="plate__frame"
            onClick={() => onOpen(memory.id, 0)}
            aria-label={`Open ${memory.title}`}
          >
            <Thumb media={lead} title={memory.title} eager={eager} />
          </button>
        )}

        <div className="plate__caption">
          <div className="plate__meta">
            <time className="label plate__date" dateTime={memory.memory_date}>
              {formatDayAndMonth(memory.memory_date)}
            </time>

            <h3 className="plate__title">{memory.title}</h3>

            {(memory.location || memory.album) && (
              <p className="plate__where">
                {[memory.album, memory.location].filter(Boolean).join(' · ')}
              </p>
            )}
          </div>

          {canManage && (
            <div className="plate__actions">
              <MemoryActions
                title={memory.title}
                onEdit={() => onEdit(memory)}
                onRemove={() => onRemove(memory)}
              />
            </div>
          )}
        </div>
      </div>
    </article>
  )
}

function Thumb({
  media,
  title,
  eager,
}: {
  media: TimelineMemory['preview'][number]
  title: string
  eager: boolean
}) {
  return media.type === 'video' ? (
    <VideoThumb media={media} alt={`Video from ${title}`} />
  ) : (
    <MediaImage media={media} alt={title} size="thumb" eager={eager} />
  )
}

function orientation(aspect: number | null): 'portrait' | 'square' | 'landscape' {
  if (aspect === null) return 'landscape'
  if (aspect < 0.92) return 'portrait'
  if (aspect < 1.2) return 'square'

  return 'landscape'
}
