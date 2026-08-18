interface Props {
  value: string
  limit: number
}

/**
 * How much room is left in a field, once that starts to be a real question.
 *
 * Silent until four fifths of the way in. A counter sitting under every box
 * from the first keystroke reads as a target to stay under, which is the
 * opposite of what these limits are for — they exist because a column has to
 * be some width, not to keep anybody brief.
 */
export function FieldCount({ value, limit }: Props) {
  const used = [...value].length
  const remaining = limit - used

  if (used < limit * 0.8) return null

  return (
    <span
      className="field__count"
      data-full={remaining <= 0}
      // Announced when it matters, not on every character typed.
      aria-live={remaining <= limit * 0.05 ? 'polite' : 'off'}
    >
      {remaining >= 0
        ? `${remaining.toLocaleString()} left`
        : `${Math.abs(remaining).toLocaleString()} over`}
    </span>
  )
}
