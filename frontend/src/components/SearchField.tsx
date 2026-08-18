import { useEffect, useRef, useState } from 'react'

interface Props {
  /** The phrase currently being searched for, from the address bar. */
  value: string
  onSearch: (phrase: string) => void
}

/** Long enough not to fire on every keystroke, short enough to feel live. */
const SETTLE_MS = 250

/**
 * Finding one memory in an archive that has outgrown scrolling.
 *
 * A rule and a word, not a box: this sits under the epigraph and should read
 * as part of the page rather than as a piece of software bolted to the top of
 * it. It only becomes conspicuous once something has been typed into it.
 *
 * The address bar is the truth. Typing settles into it after a moment so the
 * back button walks back through searches and a result is a link someone can
 * send — but the field itself never waits on that round trip.
 */
export function SearchField({ value, onSearch }: Props) {
  const [draft, setDraft] = useState(value)
  const inputRef = useRef<HTMLInputElement | null>(null)

  /*
   | Held in a ref so the settling effect below depends on the draft alone.
   | Depending on the callback as well would restart the timer on every render
   | of the page, and the search would never settle while anything else moved.
   */
  const searchRef = useRef(onSearch)
  searchRef.current = onSearch

  // Arriving on a link, or stepping back to an earlier search.
  useEffect(() => {
    setDraft((current) => (current === value ? current : value))
  }, [value])

  useEffect(() => {
    if (draft === value) return

    const timer = window.setTimeout(() => searchRef.current(draft), SETTLE_MS)

    return () => window.clearTimeout(timer)
  }, [draft, value])

  return (
    <form
      className="search"
      role="search"
      onSubmit={(event) => {
        event.preventDefault()
        // Enter means now, rather than in a quarter of a second.
        searchRef.current(draft)
      }}
    >
      <label className="visually-hidden" htmlFor="search-memories">
        Search these memories
      </label>

      <span className="search__glass" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none">
          <circle cx="11" cy="11" r="6.5" stroke="currentColor" strokeWidth="1.5" />
          <path d="M16 16l4.5 4.5" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
        </svg>
      </span>

      <input
        id="search-memories"
        ref={inputRef}
        className="search__input"
        type="search"
        value={draft}
        onChange={(event) => setDraft(event.target.value)}
        placeholder="Search a word, a place, a year"
        autoComplete="off"
        /* Safari draws its own clear button on a search input; ours is below. */
        style={{ appearance: 'none' }}
      />

      {draft !== '' && (
        <button
          type="button"
          className="search__clear"
          onClick={() => {
            setDraft('')
            searchRef.current('')
            inputRef.current?.focus()
          }}
          aria-label="Clear the search"
        >
          <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true">
            <path
              d="M6 6l12 12M18 6L6 18"
              stroke="currentColor"
              strokeWidth="1.5"
              strokeLinecap="round"
            />
          </svg>
        </button>
      )}
    </form>
  )
}
