import { useCallback, useMemo, useState } from 'react'
import { ToastContext, type ToastApi } from './toast-context'

interface Toast {
  id: number
  message: string
  tone: 'calm' | 'error'
  action?: { label: string; run: () => void }
}

/**
 * Brief confirmations, and failures that need a way out.
 *
 * A success announces itself and leaves; a failure stays until it is dealt
 * with, because the alternative is someone wondering whether their memory was
 * saved.
 */
export function ToastProvider({ children }: { children: React.ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([])

  const dismiss = useCallback((id: number) => {
    setToasts((current) => current.filter((toast) => toast.id !== id))
  }, [])

  const push = useCallback(
    (message: string, tone: Toast['tone'], action?: Toast['action']) => {
      const id = Date.now() + Math.random()

      setToasts((current) => [...current, { id, message, tone, action }])

      if (tone === 'calm') window.setTimeout(() => dismiss(id), 4200)
    },
    [dismiss],
  )

  const api = useMemo<ToastApi>(
    () => ({
      say: (message) => push(message, 'calm'),
      warn: (message, action) => push(message, 'error', action),
    }),
    [push],
  )

  return (
    <ToastContext.Provider value={api}>
      {children}

      <div className="toasts" role="status" aria-live="polite">
        {toasts.map((toast) => (
          <div key={toast.id} className={`toast${toast.tone === 'error' ? ' toast--error' : ''}`}>
            <span>{toast.message}</span>

            <button
              type="button"
              className="toast__action"
              onClick={() => {
                toast.action?.run()
                dismiss(toast.id)
              }}
            >
              {toast.action?.label ?? 'Dismiss'}
            </button>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}
