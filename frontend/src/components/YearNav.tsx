import { useState } from 'react'
import { ScrollTrigger, gsap, useGSAP } from '../lib/motion'
import { useMediaQuery } from '../hooks/useMediaQuery'
import type { YearCount } from '../api/types'

interface Props {
  years: YearCount[]
  selected: number | null
  onSelect: (year: number | null) => void
}

/**
 * Moving through time.
 *
 * Two presentations of one control: a column down the right edge where there
 * is room for it, and a scrollable strip pinned to the top where there is not.
 * The strip is not a shrunken rail — it is the shape that works with a thumb.
 */
export function YearNav({ years, selected, onSelect }: Props) {
  /*
   | Only one of the two is rendered. Hiding the other with CSS would leave it
   | in the accessibility tree and in the tab order, so a keyboard would walk
   | through every year twice.
   */
  const hasRoomForRail = useMediaQuery('(min-width: 1140px)')
  const inView = useYearInView()

  if (years.length === 0) return null

  /*
   | "All" is built into the list rather than standing beside it. As a lone
   | sibling of a mapped array it was a child with no key, which React warns
   | about and which makes it the one item it cannot match up across a render.
   */
  const stops: Array<{ key: string; label: string; year: number | null }> = [
    { key: 'all', label: 'All', year: null },
    ...years.map((entry) => ({
      key: String(entry.year),
      label: String(entry.year),
      year: entry.year,
    })),
  ]

  if (hasRoomForRail) {
    return (
      <nav className="rail" aria-label="Jump to a year">
        {stops.map((stop) => (
          <RailItem
            key={stop.key}
            label={stop.label}
            active={selected === stop.year}
            inView={stop.year !== null && stop.year === inView}
            onSelect={() => onSelect(stop.year)}
          />
        ))}
      </nav>
    )
  }

  return (
    <nav className="yearstrip" aria-label="Jump to a year">
      {stops.map((stop) => (
        <button
          key={stop.key}
          type="button"
          className="yearstrip__item"
          aria-current={selected === stop.year}
          data-inview={stop.year !== null && stop.year === inView}
          onClick={() => onSelect(stop.year)}
        >
          {stop.label}
        </button>
      ))}
    </nav>
  )
}

function RailItem({
  label,
  active,
  inView,
  onSelect,
}: {
  label: string
  active: boolean
  /** The year currently passing the middle of the screen. */
  inView: boolean
  onSelect: () => void
}) {
  return (
    <button
      type="button"
      className="rail__item"
      aria-current={active}
      data-inview={inView}
      onClick={onSelect}
    >
      {label}
      <span className="rail__mark" aria-hidden="true" />
    </button>
  )
}

/**
 * Which year is passing the middle of the screen.
 *
 * Deliberately not aria-current, which already means something else here: a
 * year that has been *chosen*, filtering the timeline to it. Where you happen
 * to have scrolled to is a different fact, it changes constantly, and
 * announcing it as the current item would talk over everything else.
 *
 * The sections it watches are added as more of the archive loads, so the
 * triggers are rebuilt whenever their number changes rather than created once
 * over whatever happened to exist on the first frame.
 */
function useYearInView(): number | null {
  const [inView, setInView] = useState<number | null>(null)

  useGSAP(() => {
    if (typeof document === 'undefined' || typeof MutationObserver === 'undefined') return

    let triggers: ScrollTrigger[] = []

    const build = () => {
      triggers.forEach((trigger) => trigger.kill())

      triggers = gsap.utils.toArray<HTMLElement>('[data-year]').map((section) =>
        ScrollTrigger.create({
          trigger: section,
          // The middle of the screen: what someone would say they are looking at.
          start: 'top 45%',
          end: 'bottom 45%',
          onToggle: (self) => {
            if (self.isActive) setInView(Number(section.dataset.year))
          },
        }),
      )
    }

    build()

    /*
     | Rebuilt on a frame rather than on the mutation itself: loading a page of
     | memories is hundreds of small changes, and rebuilding on each one would
     | be the most expensive thing on the page.
     */
    let queued = 0

    const observer = new MutationObserver(() => {
      if (queued) return

      queued = window.requestAnimationFrame(() => {
        queued = 0

        if (document.querySelectorAll('[data-year]').length !== triggers.length) build()
      })
    })

    observer.observe(document.body, { childList: true, subtree: true })

    return () => {
      observer.disconnect()

      if (queued) window.cancelAnimationFrame(queued)

      triggers.forEach((trigger) => trigger.kill())
    }
  })

  return inView
}
