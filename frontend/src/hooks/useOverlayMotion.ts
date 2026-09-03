import { useCallback, useState } from 'react'
import { DURATION, EASE, canAnimate, gsap, useGSAP } from '../lib/motion'

/**
 * How an overlay leaves.
 *
 * Every overlay in the archive is rendered conditionally by its parent, so
 * calling `onClose` unmounts it that instant and there is no moment left in
 * which to animate. This inverts that: the close button, the backdrop and
 * Escape all ask to *begin* closing, the panel and the scrim go, and only then
 * is the parent's `onClose` called and the overlay taken off the page.
 *
 * The delay is one `--duration-quick`, which matters because useOverlay
 * restores focus to whatever opened the overlay when it unmounts. Any longer
 * and the caret would visibly lag behind the click.
 *
 * The way *in* is deliberately left to the stylesheet. A tween that takes hold
 * of the panel in the same frame React is moving focus into it races with that
 * focus: the caret lands on the body and the first keystroke is the only one
 * that arrives. The arrival is a plain fade either way — it is the exit that
 * CSS genuinely cannot do.
 */
export function useOverlayMotion(
  panelRef: React.RefObject<HTMLElement | null>,
  scrimRef: React.RefObject<HTMLElement | null>,
  onClose: () => void,
  variant: 'sheet' | 'dialog' = 'dialog',
) {
  const [leaving, setLeaving] = useState(false)

  // Guarded: a second click while it is already going must not queue a
  // second exit, and must not call the parent's onClose twice.
  const beginClose = useCallback(() => setLeaving(true), [])

  useGSAP(
    () => {
      if (!leaving) return

      // Nothing to play: the overlay goes now, exactly as it always did.
      if (!canAnimate() || !panelRef.current) {
        onClose()

        return
      }

      const risesFromTheEdge =
        variant === 'sheet' &&
        typeof window !== 'undefined' &&
        !window.matchMedia('(min-width: 720px)').matches

      const timeline = gsap.timeline({ onComplete: onClose })

      timeline.to(
        panelRef.current,
        risesFromTheEdge
          ? { yPercent: 100, duration: DURATION.quick, ease: EASE.settle }
          : { y: 12, scale: 0.985, opacity: 0, duration: DURATION.quick, ease: EASE.settle },
        0,
      )

      if (scrimRef.current) {
        timeline.to(
          scrimRef.current,
          { opacity: 0, duration: DURATION.quick, ease: EASE.settle },
          0.04,
        )
      }
    },
    { dependencies: [leaving] },
  )

  return { leaving, beginClose }
}
