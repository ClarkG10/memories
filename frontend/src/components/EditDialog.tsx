import { useEffect, useState } from 'react'
import { ApiError } from '../api/client'
import { useAlbums, useMemory, useUpdateMemory } from '../api/queries'
import { useOverlay } from '../hooks/useOverlay'
import { todayAsInputValue } from '../lib/dates'
import type { TimelineMemory } from '../api/types'

interface Props {
  memory: TimelineMemory
  onClose: () => void
  onSaved: () => void
}

/**
 * Changing what a memory says. The photographs are not touched here — those
 * are added and removed from the memory itself.
 */
export function EditDialog({ memory, onClose, onSaved }: Props) {
  const update = useUpdateMemory()
  const albums = useAlbums()

  /*
   | The timeline card carries no description — it is deliberately left out of
   | that payload — so the full memory is fetched to edit it. Until it arrives
   | the field simply has nothing in it.
   */
  const full = useMemory(memory.id)
  const containerRef = useOverlay(true, () => {
    if (!update.isPending) onClose()
  })

  const [title, setTitle] = useState(memory.title)
  const [date, setDate] = useState(memory.memory_date)
  const [location, setLocation] = useState(memory.location ?? '')
  const [album, setAlbum] = useState(memory.album ?? '')
  const [description, setDescription] = useState('')
  const [loadedDescription, setLoadedDescription] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // Filled in once, when the memory arrives; never again, or it would wipe
  // out whatever has been typed since.
  useEffect(() => {
    if (full.data && !loadedDescription) {
      setDescription(full.data.description ?? '')
      setLoadedDescription(true)
    }
  }, [full.data, loadedDescription])

  const save = async () => {
    setError(null)

    try {
      await update.mutateAsync({
        id: memory.id,
        title: title.trim(),
        memory_date: date,
        location: location.trim() || null,
        album: album.trim() || null,
        description: description.trim() || null,
      })

      onSaved()
    } catch (caught) {
      setError(
        caught instanceof ApiError ? caught.message : "We couldn't save those changes just now.",
      )
    }
  }

  return (
    <div className="scrim" role="presentation">
      <div
        className="dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="edit-title"
        ref={containerRef}
        tabIndex={-1}
      >
        <h2 className="dialog__title" id="edit-title">
          Edit details
        </h2>

        <div className="dialog__fields">
          <label className="field">
            <span className="field__label">Title</span>
            <input
              className="field__input"
              value={title}
              onChange={(event) => setTitle(event.target.value)}
              maxLength={160}
              disabled={update.isPending}
              data-autofocus
            />
          </label>

          <label className="field">
            <span className="field__label">When</span>
            <input
              className="field__input"
              type="date"
              value={date}
              max={todayAsInputValue()}
              onChange={(event) => setDate(event.target.value)}
              disabled={update.isPending}
            />
          </label>

          <label className="field">
            <span className="field__label">Where (optional)</span>
            <input
              className="field__input"
              value={location}
              onChange={(event) => setLocation(event.target.value)}
              maxLength={160}
              disabled={update.isPending}
            />
          </label>

          <div className="field">
            <label className="field__label" htmlFor="edit-album">
              Album (optional)
            </label>
            <input
              id="edit-album"
              className="field__input"
              value={album}
              onChange={(event) => setAlbum(event.target.value)}
              maxLength={80}
              list="edit-album-names"
              aria-describedby="edit-album-hint"
              disabled={update.isPending}
            />
            <datalist id="edit-album-names">
              {(albums.data ?? []).map((name) => (
                <option key={name} value={name} />
              ))}
            </datalist>
            <span className="field__hint" id="edit-album-hint">
              Changing this moves the files in Google Drive too.
            </span>
          </div>

          <label className="field">
            <span className="field__label">A few words (optional)</span>
            <textarea
              className="field__textarea"
              value={description}
              onChange={(event) => setDescription(event.target.value)}
              maxLength={5000}
              disabled={update.isPending || !loadedDescription}
            />
          </label>
        </div>

        {error && (
          <div className="dialog__error" role="alert">
            {error}
          </div>
        )}

        <div className="dialog__actions">
          <button
            type="button"
            className="button button--quiet"
            onClick={onClose}
            disabled={update.isPending}
          >
            Cancel
          </button>

          <button
            type="button"
            className="button button--primary"
            onClick={() => void save()}
            disabled={update.isPending || title.trim() === ''}
          >
            {update.isPending ? 'Saving…' : 'Save'}
          </button>
        </div>
      </div>
    </div>
  )
}
