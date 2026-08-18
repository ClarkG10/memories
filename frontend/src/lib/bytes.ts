/**
 * Sizes, written the way a person would say them.
 *
 * Deliberately coarse: "3.4 GB" is the useful fact when a file is too large,
 * and "3,650,722,304 bytes" is not. A trailing zero is dropped, because
 * nobody says "eight point oh gigabytes".
 */
export function formatBytes(bytes: number): string {
  if (!Number.isFinite(bytes) || bytes <= 0) return '0 KB'

  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  const power = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1)
  const value = bytes / 1024 ** power

  // One decimal only where it says something: 3.4 GB, but 340 MB and 8 GB.
  const rounded = value >= 10 || power < 2 ? Math.round(value) : Math.round(value * 10) / 10

  return `${rounded} ${units[power]}`
}
