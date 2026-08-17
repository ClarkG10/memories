interface Props {
  message: string
  actionLabel?: string
  onAction?: () => void
}

/**
 * Something went wrong, said plainly, with the way out attached.
 *
 * Never a status code — the person reading this wants to know whether their
 * memories are alright and what to press next.
 */
export function Notice({ message, actionLabel, onAction }: Props) {
  return (
    <div className="notice" role="alert">
      <p className="notice__message">{message}</p>

      {actionLabel && onAction && (
        <div className="notice__action">
          <button type="button" className="button button--quiet" onClick={onAction}>
            {actionLabel}
          </button>
        </div>
      )}
    </div>
  )
}
