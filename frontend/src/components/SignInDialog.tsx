import { useRef, useState } from "react";
import { ApiError } from "../api/client";
import { useSignIn } from "../api/queries";
import { useOverlay } from "../hooks/useOverlay";
import { useOverlayMotion } from "../hooks/useOverlayMotion";

interface Props {
  onClose: () => void;
  onSignedIn: () => void;
}

/**
 * The owner letting themselves in.
 *
 * There is no sign-up here and no account to create — an archive belongs to
 * one person, and that account is put in place once from the command line.
 * This only exchanges a password for a token.
 */
export function SignInDialog({ onClose, onSignedIn }: Props) {
  const signIn = useSignIn();
  const scrimRef = useRef<HTMLDivElement>(null);
  const containerRef = useOverlay<HTMLFormElement>(true, () => {
    if (!signIn.isPending) beginClose();
  });
  const { leaving, beginClose } = useOverlayMotion(containerRef, scrimRef, onClose);

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);

    try {
      // useSignIn stores the token itself, before the refetches it triggers.
      await signIn.mutateAsync({ email, password });

      onSignedIn();
    } catch (caught) {
      setError(
        caught instanceof ApiError
          ? caught.message
          : "That did not work. Please try again.",
      );
    }
  };

  return (
    <div className="scrim" role="presentation" ref={scrimRef}>
      <form
        className="dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="signin-title"
        ref={containerRef}
        tabIndex={-1}
        onSubmit={submit}
      >
        <h2 className="dialog__title" id="signin-title">
          Welcome back
        </h2>

        <div className="dialog__scroll">
          <p className="dialog__body">Sign in to add to this archive.</p>

          <div className="dialog__fields">
            <label className="field">
              <span className="field__label">Email</span>
              <input
                className="field__input"
                type="email"
                autoComplete="username"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                disabled={signIn.isPending}
                required
                data-autofocus
              />
            </label>

            <label className="field">
              <span className="field__label">Password</span>
              <input
                className="field__input"
                type="password"
                autoComplete="current-password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                disabled={signIn.isPending}
                required
              />
            </label>
          </div>

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
            onClick={beginClose}
            disabled={signIn.isPending || leaving}
          >
            Cancel
          </button>

          <button
            type="submit"
            className="button button--primary"
            disabled={signIn.isPending}
          >
            {signIn.isPending ? "Signing in…" : "Sign in"}
          </button>
        </div>
      </form>
    </div>
  );
}
