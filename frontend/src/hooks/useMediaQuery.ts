import { useEffect, useState } from 'react'

/**
 * Follows a media query from JavaScript.
 *
 * Used where hiding a duplicate with CSS is not good enough: two copies of the
 * same navigation both stay in the accessibility tree and both take a tab
 * stop, so the one that is not in use should not be rendered at all.
 */
export function useMediaQuery(query: string): boolean {
  const [matches, setMatches] = useState(() => {
    if (typeof window === 'undefined' || !window.matchMedia) return false

    return window.matchMedia(query).matches
  })

  useEffect(() => {
    if (!window.matchMedia) return

    const list = window.matchMedia(query)
    const onChange = (event: MediaQueryListEvent) => setMatches(event.matches)

    setMatches(list.matches)
    list.addEventListener('change', onChange)

    return () => list.removeEventListener('change', onChange)
  }, [query])

  return matches
}
