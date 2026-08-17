import { useEffect, useRef, useState } from 'react'

/**
 * Marks an element once it has scrolled into view, so it can fade up.
 *
 * Reveals happen once and then the observer lets go — a memory that has been
 * seen should not animate again when scrolled back past.
 */
export function useReveal<T extends HTMLElement>(rootMargin = '0px 0px -8% 0px') {
  const ref = useRef<T | null>(null)
  const [visible, setVisible] = useState(false)

  useEffect(() => {
    const element = ref.current

    if (!element || visible) return

    // Without IntersectionObserver (or in a test environment), show it rather
    // than leaving the page blank.
    if (typeof IntersectionObserver === 'undefined') {
      setVisible(true)

      return
    }

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          setVisible(true)
          observer.disconnect()
        }
      },
      { rootMargin, threshold: 0.05 },
    )

    observer.observe(element)

    return () => observer.disconnect()
  }, [rootMargin, visible])

  return { ref, visible }
}
