import { useEffect, useRef } from 'react'

/**
 * The behaviour every overlay owes the person using it: the page behind stops
 * scrolling, Escape closes, focus is trapped inside, and focus returns to
 * wherever it came from on the way out.
 *
 * Shared by the memory viewer, the compose sheet and the confirmation dialog,
 * so none of them can quietly forget one of those.
 */
export function useOverlay<T extends HTMLElement = HTMLDivElement>(
  open: boolean,
  onClose: () => void,
) {
  const ref = useRef<T | null>(null)
  const restoreFocusTo = useRef<HTMLElement | null>(null)

  /*
   | Callers pass a fresh arrow function on every render. Depending on it
   | directly would tear this effect down and set it up again after every
   | keystroke — which re-runs the "move focus inside" step and pulls the
   | caret out of whatever field is being typed into. Holding it in a ref
   | keeps the effect tied to `open` alone.
   */
  const closeRef = useRef(onClose)
  closeRef.current = onClose

  useEffect(() => {
    if (!open) return

    restoreFocusTo.current = document.activeElement as HTMLElement | null

    // Compensate for the scrollbar so the page behind does not jump sideways
    // as it locks.
    const { body } = document
    const scrollbar = window.innerWidth - document.documentElement.clientWidth
    const previousOverflow = body.style.overflow
    const previousPadding = body.style.paddingRight

    body.style.overflow = 'hidden'

    // Bounded, because a document with no layout reports the full window width
    // here and would pad the page by a screen's worth.
    if (scrollbar > 0 && scrollbar <= 40) body.style.paddingRight = `${scrollbar}px`

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        closeRef.current()

        return
      }

      if (event.key !== 'Tab' || !ref.current) return

      const focusable = ref.current.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
      )

      if (focusable.length === 0) return

      const first = focusable[0]
      const last = focusable[focusable.length - 1]

      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', onKeyDown)

    // Move focus inside so a keyboard or screen reader lands in the overlay
    // rather than continuing through the page behind it.
    const focusTarget = ref.current?.querySelector<HTMLElement>('[data-autofocus]') ?? ref.current

    const frame = window.requestAnimationFrame(() => {
      // Autofill, or simply someone quick, can reach a field before this
      // frame runs. Taking focus back at that point would swallow what they
      // were typing.
      if (ref.current?.contains(document.activeElement)) return

      focusTarget?.focus()
    })

    return () => {
      window.cancelAnimationFrame(frame)
      document.removeEventListener('keydown', onKeyDown)
      body.style.overflow = previousOverflow
      body.style.paddingRight = previousPadding
      restoreFocusTo.current?.focus?.()
    }
  }, [open])

  return ref
}
