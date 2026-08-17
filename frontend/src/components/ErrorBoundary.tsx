import { Component, type ErrorInfo, type ReactNode } from 'react'

interface Props {
  children: ReactNode
}

interface State {
  failed: boolean
}

/**
 * The last line of defence.
 *
 * Without one of these, a single unexpected shape in one memory takes the
 * whole page down to a blank screen — the worst possible outcome for something
 * whose entire purpose is to still be here in ten years. This keeps the
 * failure legible and offers the one action that usually helps.
 */
export class ErrorBoundary extends Component<Props, State> {
  state: State = { failed: false }

  static getDerivedStateFromError(): State {
    return { failed: true }
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    // Nothing is sent anywhere; this archive has no analytics. The console is
    // where the detail belongs for whoever is looking into it.
    console.error('The archive hit an unexpected error.', error, info.componentStack)
  }

  render(): ReactNode {
    if (!this.state.failed) return this.props.children

    return (
      <main className="page">
        <div className="empty">
          <p className="empty__line">Something went wrong on our side.</p>
          <p className="empty__hint">Your memories are safe. Reloading usually sorts it out.</p>

          <div className="empty__action">
            <button
              type="button"
              className="button button--primary"
              onClick={() => window.location.reload()}
            >
              Reload
            </button>
          </div>
        </div>
      </main>
    )
  }
}
