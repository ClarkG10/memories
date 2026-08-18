import { useCallback, useRef, useState } from 'react'
import { formatBytes } from '../lib/bytes'
import type { Media } from '../api/types'
import type { PendingFile } from '../hooks/useUploadQueue'

/** A place in the strip: either a file already in the memory, or a new one. */
export type EditorItem =
  | { kind: 'existing'; key: string; media: Media }
  | { kind: 'new'; key: string; file: PendingFile }

interface Props {
  /** Every place in the strip, in the order they will be saved. */
  items: EditorItem[]
  /** Keys marked to be let go of. Kept in place, greyed, until saved. */
  removing: ReadonlySet<string>
  onToggleRemove: (key: string) => void
  onMove: (key: string, to: number) => void
  onAdd: (files: FileList) => void
  disabled?: boolean
}

/**
 * The photographs in a memory, arranged.
 *
 * Two things this owes the person. Nothing is destroyed by a stray click: a
 * removal is a mark on a tile that stays visible, undoable, and does not
 * happen until the whole edit is saved. And the order is the order — the first
 * tile is the photograph the timeline shows, said in as many words, because
 * otherwise "why is that one the big one" has no answer anyone can act on.
 *
 * Dragging works where there is a pointer to drag with. The arrows work
 * everywhere, which is why they are always there rather than appearing on
 * hover: a phone has no hover, and a keyboard has no drag.
 */
export function MediaEditor({
  items,
  removing,
  onToggleRemove,
  onMove,
  onAdd,
  disabled = false,
}: Props) {
  const inputRef = useRef<HTMLInputElement | null>(null)
  const [dragging, setDragging] = useState<string | null>(null)

  const kept = items.filter((item) => !removing.has(item.key))

  const move = useCallback(
    (key: string, delta: number) => {
      const from = items.findIndex((item) => item.key === key)
      const to = from + delta

      if (from < 0 || to < 0 || to > items.length - 1) return

      onMove(key, to)
    },
    [items, onMove],
  )

  return (
    <div className="mediaedit">
      <div className="mediaedit__head">
        <span className="field__label">Photos and videos</span>
        <span className="mediaedit__count">
          {kept.length} {kept.length === 1 ? 'file' : 'files'}
          {removing.size > 0 && ` · ${removing.size} to remove`}
        </span>
      </div>

      <ol className="mediaedit__strip">
        {items.map((item, index) => {
          const marked = removing.has(item.key)
          const position = kept.findIndex((k) => k.key === item.key) + 1

          return (
            <li
              key={item.key}
              className="mediaedit__tile"
              data-removing={marked}
              data-lead={!marked && position === 1}
              data-dragging={dragging === item.key}
              draggable={!disabled && !marked}
              onDragStart={() => setDragging(item.key)}
              onDragEnd={() => setDragging(null)}
              onDragOver={(event) => {
                // Without this the drop is never offered at all.
                event.preventDefault()
              }}
              onDrop={(event) => {
                event.preventDefault()

                if (dragging && dragging !== item.key) onMove(dragging, index)

                setDragging(null)
              }}
            >
              <Thumb item={item} />

              {/*
                | Said on the tile rather than left to be inferred from its
                | position, which is not obvious once the strip wraps onto a
                | second row.
              */}
              {!marked && position === 1 && <span className="mediaedit__lead">Cover</span>}

              {marked && <span className="mediaedit__mark">Removing</span>}

              {item.kind === 'new' && item.file.status === 'uploading' && (
                <span className="mediaedit__progress" aria-hidden="true">
                  <span style={{ width: `${Math.round(item.file.progress * 100)}%` }} />
                </span>
              )}

              <div className="mediaedit__tools">
                <button
                  type="button"
                  className="mediaedit__tool"
                  onClick={() => move(item.key, -1)}
                  disabled={disabled || marked || index === 0}
                  aria-label={`Move ${label(item)} earlier`}
                  title="Move earlier"
                >
                  ‹
                </button>

                <button
                  type="button"
                  className="mediaedit__tool"
                  onClick={() => move(item.key, 1)}
                  disabled={disabled || marked || index === items.length - 1}
                  aria-label={`Move ${label(item)} later`}
                  title="Move later"
                >
                  ›
                </button>

                <button
                  type="button"
                  className="mediaedit__tool mediaedit__tool--remove"
                  onClick={() => onToggleRemove(item.key)}
                  disabled={disabled}
                  aria-label={marked ? `Keep ${label(item)}` : `Remove ${label(item)}`}
                  title={marked ? 'Keep this' : 'Remove this'}
                >
                  {marked ? '↺' : '×'}
                </button>
              </div>
            </li>
          )
        })}

        <li className="mediaedit__add">
          <button
            type="button"
            className="mediaedit__addbutton"
            onClick={() => inputRef.current?.click()}
            disabled={disabled}
          >
            <span aria-hidden="true">+</span>
            Add
          </button>

          <input
            ref={inputRef}
            type="file"
            className="visually-hidden"
            multiple
            accept="image/*,video/*"
            aria-label="Add photos and videos"
            onChange={(event) => {
              if (event.target.files) onAdd(event.target.files)
              // Reset so re-choosing the same file still fires a change.
              event.target.value = ''
            }}
          />
        </li>
      </ol>

      {kept.length === 0 && (
        <p className="mediaedit__warning" role="alert">
          A memory has to keep at least one photo or video.
        </p>
      )}
    </div>
  )
}

function Thumb({ item }: { item: EditorItem }) {
  if (item.kind === 'new') {
    return item.file.kind === 'video' ? (
      <video className="mediaedit__image" src={item.file.previewUrl} muted playsInline />
    ) : (
      <img className="mediaedit__image" src={item.file.previewUrl} alt="" />
    )
  }

  const source = item.media.urls.thumb ?? item.media.urls.poster

  return source ? (
    <img className="mediaedit__image" src={source} alt="" loading="lazy" decoding="async" />
  ) : (
    <span className="mediaedit__image mediaedit__image--none" aria-hidden="true" />
  )
}

/** What to call this file when describing an action on it out loud. */
function label(item: EditorItem): string {
  if (item.kind === 'new') return `${item.file.file.name} (${formatBytes(item.file.file.size)})`

  return item.media.type === 'video' ? 'this video' : 'this photo'
}
