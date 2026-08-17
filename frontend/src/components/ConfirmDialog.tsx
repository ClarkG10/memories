import { useOverlay } from '../hooks/useOverlay'

interface Props {
  title: string
  body: string
  confirmLabel: string
  cancelLabel?: string
  busy?: boolean
  error?: string | null
  onConfirm: () => void
  onCancel: () => void
}

/**
 * A pause before something that cannot be undone.
 *
 * The wording is deliberately unalarmed — this is someone tidying their own
 * archive, not defusing something. Cancel comes first in the reading order and
 * keeps the calmer weight.
 */
export function ConfirmDialog({
  title,
  body,
  confirmLabel,
  cancelLabel = 'Cancel',
  busy = false,
  error = null,
  onConfirm,
  onCancel,
}: Props) {
  const containerRef = useOverlay(true, () => {
    if (!busy) onCancel()
  })

  return (
    <div className="scrim" role="presentation">
      <div
        className="dialog"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="confirm-title"
        aria-describedby="confirm-body"
        ref={containerRef}
        tabIndex={-1}
      >
        <h2 className="dialog__title" id="confirm-title">
          {title}
        </h2>

        <p className="dialog__body" id="confirm-body">
          {body}
        </p>

        {error && (
          <div className="dialog__error" role="alert">
            {error}
          </div>
        )}

        <div className="dialog__actions">
          <button type="button" className="button button--quiet" onClick={onCancel} disabled={busy}>
            {cancelLabel}
          </button>

          <button
            type="button"
            className="button button--primary"
            onClick={onConfirm}
            disabled={busy}
            data-autofocus
          >
            {busy ? 'Removing…' : confirmLabel}
          </button>
        </div>
      </div>
    </div>
  )
}
