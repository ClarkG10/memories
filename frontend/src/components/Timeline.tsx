import { Fragment, useEffect, useRef } from 'react'
import { DURATION, EASE, ScrollTrigger, canAnimate, gsap, useGSAP } from '../lib/motion'
import { MemoryPlate } from './MemoryPlate'
import { EmptyState } from './EmptyState'
import { TimelineSkeleton } from './TimelineSkeleton'
import { Notice } from './Notice'
import { referenceOf } from '../api/client'
import { useTimeline } from '../api/queries'
import { monthName } from '../lib/dates'
import type { TimelineMemory } from '../api/types'

interface Props {
  year: number | null
  /** A phrase being searched for, or an empty string for the whole archive. */
  search?: string
  canManage: boolean
  onOpen: (memoryId: string, mediaIndex?: number) => void
  onEdit: (memory: TimelineMemory) => void
  onRemove: (memory: TimelineMemory) => void
  onAdd: () => void
}

/**
 * The archive itself: memories newest first, grouped under the year and month
 * they belong to, loading more as someone reaches the end.
 */
export function Timeline({ year, search = '', canManage, onOpen, onEdit, onRemove, onAdd }: Props) {
  const query = useTimeline(year, search)
  const sentinel = useRef<HTMLDivElement | null>(null)
  const loadedCount = query.data?.pages.reduce((total, page) => total + page.data.length, 0) ?? 0

  /*
   | Every trigger on the page measures itself against a document that just got
   | longer, so they all have to be told once when more memories arrive.
   |
   | Once, here — not once per memory as it mounts. ScrollTrigger.refresh()
   | recalculates every trigger in the document, so calling it from each plate
   | made loading a page of twenty memories twenty full recalculations, which
   | is the most expensive thing the archive did.
   */
  useGSAP(
    () => {
      if (loadedCount === 0) return

      ScrollTrigger.refresh()
    },
    { dependencies: [loadedCount] },
  )

  const { hasNextPage, isFetchingNextPage, fetchNextPage } = query

  useEffect(() => {
    const element = sentinel.current

    if (!element || !hasNextPage || typeof IntersectionObserver === 'undefined') return

    const observer = new IntersectionObserver(
      (entries) => {
        // Guarded so a fast scroll cannot queue several requests for the same
        // page while the first is still in flight.
        if (entries[0]?.isIntersecting && !isFetchingNextPage) void fetchNextPage()
      },
      // Start fetching before the end is actually reached, so the next
      // memories are usually there by the time they are needed.
      { rootMargin: '900px 0px' },
    )

    observer.observe(element)

    return () => observer.disconnect()
  }, [hasNextPage, isFetchingNextPage, fetchNextPage])

  if (query.isPending) return <TimelineSkeleton />

  /*
   | Only when there is nothing to show. Once memories are on screen, a failed
   | request for the *next* page must not take them away — someone twenty
   | years deep in their archive should not be thrown back to an error card by
   | one dropped connection.
   */
  if (query.isError && query.data === undefined) {
    return (
      <Notice
        message="We couldn't load your memories just now."
        actionLabel="Try again"
        onAction={() => void query.refetch()}
        reference={referenceOf(query.error)}
      />
    )
  }

  const memories = query.data?.pages.flatMap((page) => page.data) ?? []

  if (memories.length === 0) {
    /*
     | A search that finds nothing is not an empty archive, and offering "add
     | your first memory" to someone who has sixteen of them and mistyped one
     | word is the interface not listening.
     */
    if (search !== '') {
      return (
        <div className="empty">
          <p className="empty__line">Nothing matches “{search}”.</p>
          <p className="empty__hint">Try fewer words, or a place, or a year.</p>
        </div>
      )
    }

    return <EmptyState filtered={year !== null} canManage={canManage} onAdd={onAdd} />
  }

  // The memories on the first screen skip the lazy loader so the archive opens
  // with a photograph already there.
  const eagerIds = new Set(memories.slice(0, 2).map((memory) => memory.id))

  return (
    <div className="timeline">
      {search !== '' && (
        <p className="timeline__found" aria-live="polite">
          {memories.length}
          {query.hasNextPage ? '+' : ''} matching “{search}”
        </p>
      )}

      {groupByYearAndMonth(memories).map((yearGroup) => (
        <section
          key={yearGroup.year}
          className="year"
          aria-label={`${yearGroup.year}`}
          /* Read by the year rail, which follows whichever year is on screen. */
          data-year={yearGroup.year}
        >
          <YearHeading year={yearGroup.year} count={yearGroup.count} />

          {yearGroup.months.map((monthGroup) => (
            <Fragment key={`${yearGroup.year}-${monthGroup.month}`}>
              <h3 className="label month">{monthName(monthGroup.month)}</h3>

              {monthGroup.memories.map((memory, index) => (
                <MemoryPlate
                  key={memory.id}
                  memory={memory}
                  flip={index % 2 === 1}
                  eager={eagerIds.has(memory.id)}
                  canManage={canManage}
                  onOpen={onOpen}
                  onEdit={onEdit}
                  onRemove={onRemove}
                />
              ))}
            </Fragment>
          ))}
        </section>
      ))}

      <div ref={sentinel} className="timeline__sentinel" aria-hidden="true" />

      {isFetchingNextPage && <p className="timeline__more">Gathering more memories…</p>}

      {/* The memories already on screen stay exactly where they are. */}
      {query.isFetchNextPageError && (
        <div className="timeline__more">
          <p>We couldn't load any more just now.</p>

          <button
            type="button"
            className="button button--quiet"
            onClick={() => void fetchNextPage()}
          >
            Try again
          </button>
        </div>
      )}

      {!hasNextPage && (
        <div className="timeline__end" aria-hidden="true">
          <span />
        </div>
      )}
    </div>
  )
}

/**
 * A year, announcing itself as you reach it.
 *
 * The number comes up out of the line, and the rule draws itself across the
 * page to meet the count on the right — a page turning to a new chapter
 * rather than another heading scrolling past.
 */
function YearHeading({ year, count }: { year: number; count: number }) {
  const ref = useRef<HTMLElement | null>(null)

  useGSAP(
    () => {
      const heading = ref.current

      if (!heading || !canAnimate()) return

      const number = heading.querySelector('.year__number')
      const rule = heading.querySelector('.year__rule')
      const label = heading.querySelector('.year__count')

      if (!number || !rule || !label) return

      const timeline = gsap.timeline({
        scrollTrigger: {
          trigger: heading,
          // Once it is properly on screen rather than the instant it peeks in.
          start: 'top 88%',
          once: true,
        },
      })

      timeline
        .from(number, {
          yPercent: 60,
          opacity: 0,
          duration: DURATION.slow,
          ease: EASE.soft,
          clearProps: 'opacity,transform',
        })
        .from(
          rule,
          {
            scaleX: 0,
            transformOrigin: 'left center',
            duration: DURATION.slow,
            ease: EASE.soft,
            clearProps: 'transform',
          },
          '-=0.55',
        )
        .from(
          label,
          {
            opacity: 0,
            duration: DURATION.base,
            ease: EASE.soft,
            clearProps: 'opacity',
          },
          '-=0.35',
        )

    },
    { scope: ref },
  )

  return (
    <header className="year__heading" ref={ref}>
      <h2 className="year__number">{year}</h2>
      <span className="year__rule" aria-hidden="true" />
      <span className="label year__count">
        {count} {count === 1 ? 'memory' : 'memories'}
      </span>
    </header>
  )
}

interface MonthGroup {
  month: number
  memories: TimelineMemory[]
}

interface YearGroup {
  year: number
  count: number
  months: MonthGroup[]
}

/**
 * The list arrives already sorted, so grouping is a single pass that starts a
 * new heading whenever the year or month changes.
 */
function groupByYearAndMonth(memories: TimelineMemory[]): YearGroup[] {
  const years: YearGroup[] = []

  for (const memory of memories) {
    let year = years.at(-1)

    if (!year || year.year !== memory.year) {
      year = { year: memory.year, count: 0, months: [] }
      years.push(year)
    }

    let month = year.months.at(-1)

    if (!month || month.month !== memory.month) {
      month = { month: memory.month, memories: [] }
      year.months.push(month)
    }

    month.memories.push(memory)
    year.count += 1
  }

  return years
}
