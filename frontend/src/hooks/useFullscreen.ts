import { useCallback, useEffect, useState } from 'react'

/**
 * Real fullscreen for an element, and knowing whether it currently is.
 *
 * The viewer already fills the window, but a browser's own chrome — tabs,
 * address bar, bookmarks — still takes a third of a laptop screen. For a
 * photograph that is worth reclaiming.
 *
 * Escape leaves fullscreen before any other handler sees it, which is why the
 * state is read back from the browser rather than assumed.
 */
export function useFullscreen<T extends HTMLElement>(ref: React.RefObject<T | null>) {
  const [isFullscreen, setIsFullscreen] = useState(false)

  const supported =
    typeof document !== 'undefined' &&
    (document.fullscreenEnabled || 'webkitFullscreenEnabled' in document)

  useEffect(() => {
    const sync = () => setIsFullscreen(document.fullscreenElement !== null)

    document.addEventListener('fullscreenchange', sync)

    return () => {
      document.removeEventListener('fullscreenchange', sync)
    }
  }, [])

  const toggle = useCallback(() => {
    const element = ref.current

    if (!element) return

    if (document.fullscreenElement) {
      void document.exitFullscreen().catch(() => undefined)

      return
    }

    // Safari on iPhone refuses this for anything but a <video>, so the promise
    // rejecting is a normal outcome rather than a fault.
    void element.requestFullscreen?.().catch(() => undefined)
  }, [ref])

  return { isFullscreen, toggle, supported }
}
