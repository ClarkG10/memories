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
  onSelect,
}: {
  label: string
  active: boolean
  onSelect: () => void
}) {
  return (
    <button type="button" className="rail__item" aria-current={active} onClick={onSelect}>
      {label}
      <span className="rail__mark" aria-hidden="true" />
    </button>
  )
}
