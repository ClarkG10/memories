import { createContext } from 'react'

export interface ToastApi {
  /** A brief confirmation that shows and then leaves. */
  say: (message: string) => void
  /** A failure, which stays until it is dealt with. */
  warn: (message: string, action?: { label: string; run: () => void }) => void
}

/**
 * Kept apart from the provider component so that the module holding the
 * provider exports nothing but a component — which is what lets React's fast
 * refresh replace it without remounting the tree beneath it.
 */
export const ToastContext = createContext<ToastApi | null>(null)
