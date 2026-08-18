import { useState } from 'react'
import { useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ApiError } from '../api/client'
import { useArchive, useDeleteMemory, useSignOut, useYears } from '../api/queries'
import { ComposeSheet } from '../components/ComposeSheet'
import { ConfirmDialog } from '../components/ConfirmDialog'
import { EditDialog, type EditableMemory } from '../components/EditDialog'
import { MemoryViewer } from '../components/MemoryViewer'
import { Notice } from '../components/Notice'
import { SignInDialog } from '../components/SignInDialog'
import { Timeline } from '../components/Timeline'
import { TimelineSkeleton } from '../components/TimelineSkeleton'
import { useToasts } from '../hooks/useToasts'
import { YearNav } from '../components/YearNav'
import type { TimelineMemory } from '../api/types'

/**
 * The archive.
 *
 * Everything lives on one page: the timeline is the application, and the
 * viewer, the compose sheet and the confirmations are all things that happen
 * on top of it. There is no dashboard to go to, because there is nothing an
 * archive needs that is not a memory.
 */
export function ArchivePage() {
  const navigate = useNavigate()
  const location = useLocation()
  const params = useParams<{ memoryId?: string }>()
  const [search, setSearch] = useSearchParams()
  const toasts = useToasts()

  const archive = useArchive()
  const years = useYears()
  const remove = useDeleteMemory()
  const signOut = useSignOut()

  const [composing, setComposing] = useState(false)
  const [signingIn, setSigningIn] = useState(false)
  const [editing, setEditing] = useState<EditableMemory | null>(null)
  const [removing, setRemoving] = useState<TimelineMemory | null>(null)
  const [removeError, setRemoveError] = useState<string | null>(null)

  const yearParam = search.get('year')
  const selectedYear = yearParam === null ? null : Number(yearParam)
  const openMemoryId = params.memoryId ?? null
  const openIndex = Number(search.get('i') ?? 0)

  const canManage = archive.data?.can_manage ?? false

  const selectYear = (year: number | null) => {
    const next = new URLSearchParams(search)

    if (year === null) next.delete('year')
    else next.set('year', String(year))

    setSearch(next, { replace: true })
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  /*
   | Opening a memory is a navigation, so the browser's back button closes it.
   | On a phone that is the gesture people reach for first, and a viewer that
   | ignores it feels broken.
   */
  const openMemory = (memoryId: string, mediaIndex = 0) => {
    const next = new URLSearchParams(search)
    next.set('i', String(mediaIndex))

    navigate({ pathname: `/m/${memoryId}`, search: next.toString() })
  }

  /*
   | Going back is right when the viewer was opened from the timeline. When
   | someone has followed a shared link straight to a memory there is nothing
   | behind it, and going back would take them out of the archive entirely.
   */
  const closeViewer = () => {
    if (location.key === 'default') navigate('/', { replace: true })
    else navigate(-1)
  }

  const confirmRemoval = async () => {
    if (!removing) return

    setRemoveError(null)

    try {
      await remove.mutateAsync(removing.id)

      setRemoving(null)
      toasts.say('That memory has been removed.')
    } catch (caught) {
      setRemoveError(
        caught instanceof ApiError
          ? caught.message
          : "We couldn't completely remove this memory. Please try again.",
      )
    }
  }

  if (archive.isPending) {
    return (
      <main className="page">
        <TimelineSkeleton />
      </main>
    )
  }

  if (archive.isError) {
    return (
      <main className="page">
        <Notice
          message="We couldn't reach this archive just now."
          actionLabel="Try again"
          onAction={() => void archive.refetch()}
        />
      </main>
    )
  }

  const locked = !archive.data.public && !canManage

  return (
    <>
      <header className="topbar">
        {canManage ? (
          <>
            <button
              type="button"
              className="addbutton"
              onClick={() => setComposing(true)}
              aria-label="Add a memory"
            >
              <span className="addbutton__plus" aria-hidden="true">
                +
              </span>
              <span className="addbutton__label">Add memory</span>
            </button>

            <button
              type="button"
              className="button button--plain"
              onClick={() => {
                // useSignOut clears the token once the request has actually
                // been sent, so the server gets a chance to revoke it.
                void signOut.mutateAsync().catch(() => undefined)
                toasts.say('Signed out.')
              }}
            >
              Sign out
            </button>
          </>
        ) : (
          <button
            type="button"
            className="button button--plain"
            onClick={() => setSigningIn(true)}
          >
            Sign in
          </button>
        )}
      </header>

      <main className="page" id="main">
        <div className="masthead">
          <h1 className="display masthead__title">{archive.data.title}</h1>

          {archive.data.quote && <p className="masthead__quote">{archive.data.quote}</p>}

          <hr className="masthead__rule" />
        </div>

        {/* After the masthead, so the archive introduces itself before it
            offers navigation. On a narrow screen this is a strip that sticks
            to the top once scrolled past; on a wide one it is a fixed rail,
            and its position here makes no difference. */}
        {!locked && (
          <YearNav years={years.data ?? []} selected={selectedYear} onSelect={selectYear} />
        )}

        {locked ? (
          <div className="empty">
            <p className="empty__line">These memories are kept private.</p>
            <p className="empty__hint">Sign in to see them.</p>

            <div className="empty__action">
              <button
                type="button"
                className="button button--primary"
                onClick={() => setSigningIn(true)}
              >
                Sign in
              </button>
            </div>
          </div>
        ) : (
          <Timeline
            year={selectedYear}
            canManage={canManage}
            onOpen={openMemory}
            onEdit={setEditing}
            onRemove={(memory) => {
              setRemoveError(null)
              setRemoving(memory)
            }}
            onAdd={() => setComposing(true)}
          />
        )}
      </main>

      {openMemoryId && (
        <MemoryViewer
          memoryId={openMemoryId}
          initialIndex={Number.isFinite(openIndex) ? openIndex : 0}
          onClose={closeViewer}
          canManage={canManage}
          onEdit={setEditing}
        />
      )}

      {composing && (
        <ComposeSheet
          archive={archive.data}
          onClose={() => setComposing(false)}
          onSaved={(title) => {
            setComposing(false)
            toasts.say(`“${title}” is now part of your timeline.`)
          }}
        />
      )}

      {editing && (
        <EditDialog
          memory={editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            toasts.say('Saved.')
          }}
        />
      )}

      {removing && (
        <ConfirmDialog
          title="Remove this memory?"
          body={`This cannot be undone. ${
            removing.media_count === 1
              ? 'The photo or video'
              : `All ${removing.media_count} photos and videos`
          } will be permanently deleted from your Google Drive, along with everything written about this memory.`}
          confirmPhrase={removing.title}
          confirmLabel="Remove memory"
          busy={remove.isPending}
          error={removeError}
          onConfirm={() => void confirmRemoval()}
          onCancel={() => setRemoving(null)}
        />
      )}

      {signingIn && (
        <SignInDialog
          onClose={() => setSigningIn(false)}
          onSignedIn={() => {
            setSigningIn(false)
            toasts.say('Welcome back.')
          }}
        />
      )}
    </>
  )
}
