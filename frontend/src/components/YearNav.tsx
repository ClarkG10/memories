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

  if (hasRoomForRail) {
    return (
      <nav className="rail" aria-label="Jump to a year">
        <RailItem label="All" active={selected === null} onSelect={() => onSelect(null)} />

        {years.map((entry) => (
          <RailItem
            key={entry.year}
            label={String(entry.year)}
            active={selected === entry.year}
            onSelect={() => onSelect(entry.year)}
          />
        ))}
      </nav>
    )
  }

  return (
    <nav className="yearstrip" aria-label="Jump to a year">
      <button
        type="button"
        className="yearstrip__item"
        aria-current={selected === null}
        onClick={() => onSelect(null)}
      >
        All
      </button>

      {years.map((entry) => (
        <button
          key={entry.year}
          type="button"
          className="yearstrip__item"
          aria-current={selected === entry.year}
          onClick={() => onSelect(entry.year)}
        >
          {entry.year}
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
