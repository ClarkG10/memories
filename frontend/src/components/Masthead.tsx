import { useRef } from 'react'
import {
  DURATION,
  EASE,
  SplitText,
  canAnimate,
  gsap,
  useGSAP,
  whenFontsReady,
} from '../lib/motion'

interface Props {
  title: string
  quote: string | null | undefined
}

/**
 * The first thing anyone sees.
 *
 * The archive's name arrives a word at a time, each one climbing out from
 * behind the line below it — the way a title is written rather than the way a
 * page loads. The epigraph and the rule follow it, and then it is over: the
 * point of the page is the photographs underneath.
 *
 * SplitText puts the whole name into an aria-label on the heading and hides
 * the pieces it makes, so what is announced is still one title rather than a
 * list of words. It is reverted on the way out, leaving the markup as written.
 */
export function Masthead({ title, quote }: Props) {
  const ref = useRef<HTMLDivElement>(null)
  const titleRef = useRef<HTMLHeadingElement>(null)
  const quoteRef = useRef<HTMLParagraphElement>(null)
  const ruleRef = useRef<HTMLHRElement>(null)

  useGSAP(
    (context) => {
      const heading = titleRef.current

      // Nothing to do: the stylesheet already has all of this at rest.
      if (!heading || !canAnimate() || !context) return

      let split: SplitText | null = null

      /*
       | Splitting before the display font has arrived measures the fallback
       | and breaks the line in the wrong places. The fonts are bundled, so
       | this is usually the very next frame.
       */
      const cancel = whenFontsReady(() => {
        /*
         | Added to the hook's context by hand: this runs a frame or two after
         | the hook did, and anything created outside it would not be cleaned
         | up when the archive navigates away.
         */
        context.add(() => {
          split = SplitText.create(heading, { type: 'words', mask: 'words' })

          gsap.from(split.words, {
            yPercent: 120,
            opacity: 0,
            duration: DURATION.slow,
            ease: EASE.soft,
            stagger: 0.06,
          })

          const after = [quoteRef.current, ruleRef.current].filter(Boolean)

          if (after.length > 0) {
            gsap.from(after, {
              opacity: 0,
              y: 12,
              duration: DURATION.base,
              ease: EASE.soft,
              stagger: 0.1,
              delay: 0.3,
              clearProps: 'opacity,transform',
            })
          }
        })
      })

      return () => {
        cancel()
        split?.revert()
      }
    },
    { scope: ref },
  )

  return (
    <div className="masthead" ref={ref}>
      <h1 className="display masthead__title" ref={titleRef}>
        {title}
      </h1>

      {quote && (
        <p className="masthead__quote" ref={quoteRef}>
          {quote}
        </p>
      )}

      <hr className="masthead__rule" ref={ruleRef} />
    </div>
  )
}
