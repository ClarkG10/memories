interface Props {
  message: string
  actionLabel?: string
  onAction?: () => void
  /**
   * The server's name for the request that failed. Shown only when there is
   * one, and shown quietly.
   */
  reference?: string | null
}

/**
 * Something went wrong, said plainly, with the way out attached.
 *
 * Never a status code — the person reading this wants to know whether their
 * memories are alright and what to press next. The reference is the exception,
 * and it is not for them: it is for whoever they show this to, and it is the
 * only thing that turns "it broke" into a line in a log.
 */
export function Notice({ message, actionLabel, onAction, reference }: Props) {
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

      {reference && (
        <p className="notice__reference">
          Reference <code>{reference}</code>
        </p>
      )}
    </div>
  )
}
