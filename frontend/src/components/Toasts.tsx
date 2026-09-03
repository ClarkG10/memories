import { useCallback, useMemo, useRef, useState } from 'react'
import { DURATION, settleIn, settleOut, useGSAP } from '../lib/motion'
import { ToastContext, type ToastApi } from './toast-context'

interface Toast {
  id: number
  message: string
  tone: 'calm' | 'error'
  action?: { label: string; run: () => void }
  /** On its way out: still in the document for the length of one short fade. */
  leaving: boolean
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

  /*
   | Leaving is two steps. A toast is first marked as leaving, which lets it
   | fade the way it arrived, and only then taken out of the document. Removing
   | it outright made every confirmation vanish in the middle of being read.
   */
  const remove = useCallback((id: number) => {
    setToasts((current) => current.filter((toast) => toast.id !== id))
  }, [])

  const dismiss = useCallback((id: number) => {
    setToasts((current) =>
      current.map((toast) => (toast.id === id ? { ...toast, leaving: true } : toast)),
    )
  }, [])

  const push = useCallback(
    (message: string, tone: Toast['tone'], action?: Toast['action']) => {
      const id = Date.now() + Math.random()

      setToasts((current) => [...current, { id, message, tone, action, leaving: false }])

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
          <ToastItem key={toast.id} toast={toast} onDismiss={dismiss} onGone={remove} />
        ))}
      </div>
    </ToastContext.Provider>
  )
}

function ToastItem({
  toast,
  onDismiss,
  onGone,
}: {
  toast: Toast
  onDismiss: (id: number) => void
  onGone: (id: number) => void
}) {
  const ref = useRef<HTMLDivElement>(null)

  // Arrives from a little below, as everything in the archive does.
  useGSAP(
    () => {
      settleIn(ref.current, { distance: 10, duration: DURATION.base })
    },
    { scope: ref },
  )

  // Leaves the same way, then asks to be taken out of the document.
  useGSAP(
    () => {
      if (!toast.leaving) return

      settleOut(ref.current, () => onGone(toast.id), { distance: 10 })
    },
    { scope: ref, dependencies: [toast.leaving] },
  )

  return (
    <div ref={ref} className={`toast${toast.tone === 'error' ? ' toast--error' : ''}`}>
      <span>{toast.message}</span>

      <button
        type="button"
        className="toast__action"
        disabled={toast.leaving}
        onClick={() => {
          toast.action?.run()
          onDismiss(toast.id)
        }}
      >
        {toast.action?.label ?? 'Dismiss'}
      </button>
    </div>
  )
}
