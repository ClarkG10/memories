import { useId, useState } from "react";
import { useOverlay } from "../hooks/useOverlay";

interface Props {
  title: string;
  body: string;
  confirmLabel: string;
  cancelLabel?: string;
  /**
   * When set, the exact words that must be typed before the action is allowed.
   * Reserved for things that cannot be undone.
   */
  confirmPhrase?: string;
  busy?: boolean;
  error?: string | null;
  onConfirm: () => void;
  onCancel: () => void;
}

/**
 * A pause before something that cannot be undone.
 *
 * The wording is deliberately unalarmed — this is someone tidying their own
 * archive, not defusing something. Cancel comes first in the reading order.
 *
 * Where a confirmation phrase is required, the pause becomes a deliberate act:
 * a mis-tap cannot delete a memory, because a mis-tap cannot type a title.
 */
export function ConfirmDialog({
  title,
  body,
  confirmLabel,
  cancelLabel = "Cancel",
  confirmPhrase,
  busy = false,
  error = null,
  onConfirm,
  onCancel,
}: Props) {
  const containerRef = useOverlay(true, () => {
    if (!busy) onCancel();
  });

  const [typed, setTyped] = useState("");
  const inputId = useId();

  // Trimmed only at the edges: someone pasting a title should not be defeated
  // by a trailing space, but the words themselves have to match.
  const matches =
    confirmPhrase === undefined || typed.trim() === confirmPhrase.trim();

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

        <div className="dialog__scroll">
          <p className="dialog__body" id="confirm-body">
            {body}
          </p>

          {confirmPhrase !== undefined && (
            <div className="dialog__fields">
              <label className="field__label" htmlFor={inputId}>
                Type the title to confirm
              </label>

              {/* Selectable on purpose: copying it is the expected way through. */}
              <p className="confirm__phrase">{confirmPhrase}</p>

              <input
                id={inputId}
                className="field__input"
                value={typed}
                onChange={(event) => setTyped(event.target.value)}
                autoComplete="off"
                spellCheck={false}
                disabled={busy}
                data-autofocus
              />
            </div>
          )}

          {error && (
            <div className="dialog__error" role="alert">
              {error}
            </div>
          )}
        </div>

        <div className="dialog__actions">
          <button
            type="button"
            className="button button--quiet"
            onClick={onCancel}
            disabled={busy}
          >
            {cancelLabel}
          </button>

          <button
            type="button"
            className="button button--danger"
            onClick={onConfirm}
            disabled={busy || !matches}
            {...(confirmPhrase === undefined ? { "data-autofocus": true } : {})}
          >
            {busy ? "Removing…" : confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
