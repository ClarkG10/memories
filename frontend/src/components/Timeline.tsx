import { Fragment, useEffect, useRef } from 'react'
import { MemoryPlate } from './MemoryPlate'
import { EmptyState } from './EmptyState'
import { TimelineSkeleton } from './TimelineSkeleton'
import { Notice } from './Notice'
import { useTimeline } from '../api/queries'
import { monthName } from '../lib/dates'
import type { TimelineMemory } from '../api/types'

interface Props {
  year: number | null
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
export function Timeline({ year, canManage, onOpen, onEdit, onRemove, onAdd }: Props) {
  const query = useTimeline(year)
  const sentinel = useRef<HTMLDivElement | null>(null)

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
      />
    )
  }

  const memories = query.data?.pages.flatMap((page) => page.data) ?? []

  if (memories.length === 0) {
    return <EmptyState filtered={year !== null} canManage={canManage} onAdd={onAdd} />
  }

  // The memories on the first screen skip the lazy loader so the archive opens
  // with a photograph already there.
  const eagerIds = new Set(memories.slice(0, 2).map((memory) => memory.id))

  return (
    <div className="timeline">
      {groupByYearAndMonth(memories).map((yearGroup) => (
        <section key={yearGroup.year} className="year" aria-label={`${yearGroup.year}`}>
          <header className="year__heading">
            <h2 className="year__number">{yearGroup.year}</h2>
            <span className="year__rule" aria-hidden="true" />
            <span className="label year__count">
              {yearGroup.count} {yearGroup.count === 1 ? 'memory' : 'memories'}
            </span>
          </header>

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
