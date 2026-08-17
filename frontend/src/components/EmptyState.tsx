interface Props {
  /** True when a year filter is hiding everything, rather than the archive being new. */
  filtered: boolean
  canManage: boolean
  onAdd: () => void
}

/**
 * What an archive with nothing in it says.
 *
 * "No data found" would be the wrong sentence in a place built to hold
 * someone's memories. An empty archive is a beginning, and it should read
 * like one.
 */
export function EmptyState({ filtered, canManage, onAdd }: Props) {
  if (filtered) {
    return (
      <div className="empty">
        <p className="empty__line">Nothing from this year yet.</p>
        <p className="empty__hint">Choose another year, or add something to this one.</p>
      </div>
    )
  }

  return (
    <div className="empty">
      <p className="empty__line">Every beautiful story starts somewhere.</p>

      {canManage ? (
        <>
          <p className="empty__hint">This is where yours will live.</p>

          <div className="empty__action">
            <button type="button" className="button button--primary" onClick={onAdd}>
              Add your first memory
            </button>
          </div>
        </>
      ) : (
        <p className="empty__hint">Nothing has been added here yet.</p>
      )}
    </div>
  )
}
