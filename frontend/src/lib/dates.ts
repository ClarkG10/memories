const MONTHS = [
  'January',
  'February',
  'March',
  'April',
  'May',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
]

/**
 * Dates are parsed by hand rather than through `new Date('2026-08-10')`.
 *
 * That form is treated as UTC midnight, so anyone west of Greenwich sees the
 * day before — which in an archive organised entirely by date would put a
 * memory in the wrong place.
 */
export function parseMemoryDate(value: string): { year: number; month: number; day: number } {
  const [year, month, day] = value.split('-').map(Number)

  return { year, month, day }
}

/** "10 August 2026" */
export function formatLongDate(value: string): string {
  const { year, month, day } = parseMemoryDate(value)

  return `${day} ${MONTHS[month - 1]} ${year}`
}

/** "10 August" — the year is already the section heading. */
export function formatDayAndMonth(value: string): string {
  const { month, day } = parseMemoryDate(value)

  return `${day} ${MONTHS[month - 1]}`
}

export function monthName(month: number): string {
  return MONTHS[month - 1] ?? ''
}

/** "1:23" — how long a video runs. */
export function formatDuration(ms: number): string {
  const total = Math.round(ms / 1000)
  const minutes = Math.floor(total / 60)
  const seconds = total % 60

  return `${minutes}:${seconds.toString().padStart(2, '0')}`
}

export function todayAsInputValue(): string {
  const now = new Date()
  const month = `${now.getMonth() + 1}`.padStart(2, '0')
  const day = `${now.getDate()}`.padStart(2, '0')

  return `${now.getFullYear()}-${month}-${day}`
}
