import { useRef, useState } from 'react'
import { FieldCount } from './FieldCount'
import { ApiError } from '../api/client'
import { idempotencyKey, useAlbums, useCreateMemory } from '../api/queries'
import { useOverlay } from '../hooks/useOverlay'
import { useUploadQueue } from '../hooks/useUploadQueue'
import { todayAsInputValue } from '../lib/dates'
import { DEFAULT_TEXT_LIMITS, type Archive } from '../api/types'
import { formatBytes } from '../lib/bytes'

interface Props {
  archive: Archive
  onClose: () => void
  onSaved: (title: string) => void
}

/**
 * Adding a memory.
 *
 * One panel, not a wizard: choose the photos, say what this was and when, save.
 * If it fails, everything stays exactly where it was — the files, the words,
 * and the uploads that already succeeded — so trying again costs one tap
 * rather than starting over.
 */
export function ComposeSheet({ archive, onClose, onSaved }: Props) {
  const queue = useUploadQueue(archive)
  const limits = archive.text ?? DEFAULT_TEXT_LIMITS
  const create = useCreateMemory()
  const albums = useAlbums()
  const containerRef = useOverlay(true, () => {
    if (!busy) onClose()
  })

  const inputRef = useRef<HTMLInputElement | null>(null)
  const [dragging, setDragging] = useState(false)

  const [title, setTitle] = useState('')
  const [date, setDate] = useState(todayAsInputValue())
  const [location, setLocation] = useState('')
  const [description, setDescription] = useState('')
  const [album, setAlbum] = useState('')

  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  /*
   | One key per attempt, held across retries. A retry of a save that timed out
   | is then recognised by the server as the same memory rather than a second
   | one; it is only replaced once a memory has actually been created.
   */
  const requestKey = useRef(idempotencyKey())

  const busy = saving || queue.isUploading
  const canSave = queue.files.length > 0 && title.trim().length > 0 && date !== '' && !busy

  const save = async () => {
    setError(null)
    setSaving(true)

    try {
      const uploads = await queue.uploadAll()

      const memory = await create.mutateAsync({
        title: title.trim(),
        description: description.trim() || null,
        memory_date: date,
        location: location.trim() || null,
        album: album.trim() || null,
        uploads,
        requestKey: requestKey.current,
      })

      requestKey.current = idempotencyKey()
      queue.reset()
      setAlbum('')
      onSaved(memory.title)
    } catch (caught) {
      const cancelled = caught instanceof ApiError && caught.message === 'Upload cancelled.'

      setError(
        cancelled
          ? 'Stopped. Nothing has been saved yet — press Try again when you are ready.'
          : caught instanceof ApiError
            ? caught.message
            : "We couldn't save this memory. Please try again.",
      )
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="sheet" role="presentation">
      <div
        className="sheet__panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="compose-title"
        ref={containerRef}
        tabIndex={-1}
      >
        <header className="sheet__head">
          <h2 className="sheet__title" id="compose-title">
            Add a memory
          </h2>

          <button
            type="button"
            className="button button--plain"
            onClick={onClose}
            disabled={busy}
          >
            Close
          </button>
        </header>

        <div className="sheet__body">
          {!archive.storage_connected && (
            <div className="compose__rejections">
              <span>
                This archive isn't connected to Google Drive yet, so nothing can be saved.
              </span>
            </div>
          )}

          <div
            className="dropzone"
            data-active={dragging}
            onDragOver={(event) => {
              event.preventDefault()
              setDragging(true)
            }}
            onDragLeave={() => setDragging(false)}
            onDrop={(event) => {
              event.preventDefault()
              setDragging(false)
              queue.add(event.dataTransfer.files)
            }}
          >
            <p className="dropzone__lead">Choose photos and videos</p>
            <p className="dropzone__hint">Everything you pick becomes one memory.</p>

            {/*
              | Said before anything is chosen rather than after something is
              | refused. These are wide enough that most people will never meet
              | them, which is exactly why they should be visible: meeting one
              | unannounced reads as the archive being broken.
            */}
            <p className="dropzone__limits">
              Up to {archive.upload.max_files} files ·
              {' '}photos to {formatBytes(archive.upload.max_image_bytes)} ·
              {' '}videos to {formatBytes(archive.upload.max_video_bytes)}
              {archive.storage?.drive_free_bytes != null && (
                <> · {formatBytes(archive.storage.drive_free_bytes)} left in Drive</>
              )}
            </p>

            <button
              type="button"
              className="button button--quiet"
              onClick={() => inputRef.current?.click()}
              disabled={busy}
            >
              Browse
            </button>

            <input
              ref={inputRef}
              type="file"
              className="visually-hidden"
              multiple
              accept="image/*,video/*"
              aria-label="Photos and videos"
              onChange={(event) => {
                if (event.target.files) queue.add(event.target.files)
                // Reset so re-choosing the same file still fires a change.
                event.target.value = ''
              }}
            />
          </div>

          {queue.rejections.length > 0 && (
            <div className="compose__rejections" role="alert">
              {queue.rejections.map((message) => (
                <span key={message}>{message}</span>
              ))}
            </div>
          )}

          {queue.files.length > 0 && (
            <div className="picks">
              {queue.files.map((item) => (
                <div key={item.id} className="pick" data-status={item.status}>
                  {item.kind === 'video' ? (
                    <video className="pick__preview" src={item.previewUrl} muted playsInline />
                  ) : (
                    <img className="pick__preview" src={item.previewUrl} alt="" />
                  )}

                  <span className="pick__badge">{item.kind}</span>

                  {item.status === 'uploading' && (
                    <>
                      <span
                        className="pick__progress"
                        style={{ clipPath: `inset(0 0 ${item.progress * 100}% 0)` }}
                      />
                      <span className="pick__percent">{Math.round(item.progress * 100)}%</span>
                    </>
                  )}

                  {item.status === 'uploaded' && (
                    <span className="pick__done" aria-label="Uploaded">
                      <svg viewBox="0 0 24 24" width="12" height="12" aria-hidden="true">
                        <path
                          d="M5 12.5l4.5 4.5L19 7"
                          stroke="currentColor"
                          strokeWidth="2.4"
                          fill="none"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        />
                      </svg>
                    </span>
                  )}

                  {!busy && (
                    <button
                      type="button"
                      className="pick__remove"
                      onClick={() => queue.remove(item.id)}
                      aria-label={`Remove ${item.file.name}`}
                    >
                      <svg viewBox="0 0 24 24" width="12" height="12" aria-hidden="true">
                        <path
                          d="M6 6l12 12M18 6L6 18"
                          stroke="currentColor"
                          strokeWidth="2"
                          strokeLinecap="round"
                        />
                      </svg>
                    </button>
                  )}
                </div>
              ))}
            </div>
          )}

          <div className="compose__fields">
            <label className="field">
              <span className="field__label">Title</span>
              <input
                className="field__input"
                value={title}
                onChange={(event) => setTitle(event.target.value)}
                maxLength={limits.title}
                placeholder="That beautiful evening"
                disabled={busy}
              />
              <FieldCount value={title} limit={limits.title} />
            </label>

            <div className="compose__row">
              <label className="field">
                <span className="field__label">When</span>
                <input
                  className="field__input"
                  type="date"
                  value={date}
                  max={todayAsInputValue()}
                  onChange={(event) => setDate(event.target.value)}
                  disabled={busy}
                />
              </label>

              <label className="field">
                <span className="field__label">Where (optional)</span>
                <input
                  className="field__input"
                  value={location}
                  onChange={(event) => setLocation(event.target.value)}
                  maxLength={limits.location}
                  placeholder="Butuan"
                  disabled={busy}
                />
                <FieldCount value={location} limit={limits.location} />
              </label>
            </div>

            <div className="field">
              {/* Explicit htmlFor rather than a wrapping label: the hint below
                  is a description, not part of the field's name, and nesting it
                  inside the label would make screen readers announce the two
                  run together. */}
              <label className="field__label" htmlFor="memory-album">
                Album (optional)
              </label>

              {/* A datalist rather than a select: albums already in use are
                  offered, but a new one is simply typed. Nothing to create. */}
              <input
                id="memory-album"
                className="field__input"
                value={album}
                onChange={(event) => setAlbum(event.target.value)}
                maxLength={limits.album}
                list="album-names"
                placeholder="Our Wedding"
                aria-describedby="memory-album-hint"
                disabled={busy}
              />

              <datalist id="album-names">
                {(albums.data ?? []).map((name) => (
                  <option key={name} value={name} />
                ))}
              </datalist>

              <span className="field__hint" id="memory-album-hint">
                {album.trim()
                  ? `Files go to Memory Archive / Albums / ${album.trim()}`
                  : 'Left empty, files are filed by date.'}
              </span>
            </div>

            <label className="field">
              <span className="field__label">A few words (optional)</span>
              <textarea
                className="field__textarea"
                value={description}
                onChange={(event) => setDescription(event.target.value)}
                maxLength={limits.description}
                placeholder="One of those evenings we wish we could replay."
                disabled={busy}
              />
              <FieldCount value={description} limit={limits.description} />
            </label>
          </div>

          {busy && (
            <div className="compose__progress" aria-live="polite">
              <div className="compose__progressbar">
                <div
                  className="compose__progressfill"
                  style={{ width: `${Math.round(queue.progress * 100)}%` }}
                />
              </div>

              <div className="compose__progresstext">
                <span>Keeping this safe…</span>
                <span>{Math.round(queue.progress * 100)}%</span>
              </div>
            </div>
          )}

          {error && (
            <div className="dialog__error" role="alert">
              {error}
            </div>
          )}
        </div>

        <footer className="sheet__foot">
          {/* A long video has to be stoppable. Cancelling aborts the transfer
              in flight and leaves the files and the words where they are, so
              it can be picked up again. */}
          <button
            type="button"
            className="button button--quiet"
            onClick={busy ? queue.cancel : onClose}
          >
            {busy ? 'Stop' : 'Cancel'}
          </button>

          <button
            type="button"
            className="button button--primary"
            onClick={() => void save()}
            disabled={!canSave || !archive.storage_connected}
          >
            {busy ? 'Saving…' : error ? 'Try again' : 'Save memory'}
          </button>
        </footer>
      </div>
    </div>
  )
}
