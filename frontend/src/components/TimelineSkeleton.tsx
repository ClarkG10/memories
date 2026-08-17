/**
 * The shape of what is coming, held open while it arrives.
 *
 * A spinner over the whole page would say "wait"; this says "memories, very
 * shortly" — and because it occupies the real dimensions, nothing jumps when
 * the photographs land.
 */
export function TimelineSkeleton() {
  return (
    <div className="timeline__loading" aria-hidden="true">
      <div>
        <div className="skeleton" style={{ width: '8rem', height: '3.5rem' }} />
      </div>

      <div>
        <div className="skeleton" style={{ aspectRatio: '3 / 2', borderRadius: 'var(--radius)' }} />
        <div
          className="skeleton"
          style={{ width: '6rem', height: '0.75rem', marginTop: 'var(--space-4)' }}
        />
        <div
          className="skeleton"
          style={{ width: '14rem', height: '1.5rem', marginTop: 'var(--space-3)' }}
        />
      </div>

      <div>
        <div className="skeleton" style={{ aspectRatio: '4 / 3', borderRadius: 'var(--radius)' }} />
        <div
          className="skeleton"
          style={{ width: '6rem', height: '0.75rem', marginTop: 'var(--space-4)' }}
        />
        <div
          className="skeleton"
          style={{ width: '11rem', height: '1.5rem', marginTop: 'var(--space-3)' }}
        />
      </div>
    </div>
  )
}
