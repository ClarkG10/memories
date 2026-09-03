import { useRef } from 'react'
import { settleIn, useGSAP } from '../lib/motion'

interface Props {
  title: string
  quote: string | null | undefined
}

/**
 * The first thing anyone sees, arriving in reading order: the name, then the
 * epigraph, then the rule beneath them, each a beat after the last.
 *
 * Once, on the way in. It is a page settling rather than a title sequence,
 * and the photographs below it are the point.
 */
export function Masthead({ title, quote }: Props) {
  const ref = useRef<HTMLDivElement>(null)

  useGSAP(
    () => {
      settleIn(ref.current?.children, { stagger: 0.12 })
    },
    { scope: ref },
  )

  return (
    <div className="masthead" ref={ref}>
      <h1 className="display masthead__title">{title}</h1>

      {quote && <p className="masthead__quote">{quote}</p>}

      <hr className="masthead__rule" />
    </div>
  )
}
