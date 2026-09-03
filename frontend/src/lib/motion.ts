import gsap from 'gsap'
import { useGSAP } from '@gsap/react'

/**
 * The archive's motion, in one place — the JavaScript half of tokens.css.
 *
 * GSAP owns the movement that has an order to it — one element after another,
 * a photograph a beat before its caption — and the movement that has to finish
 * before something can be taken off the page, which a stylesheet cannot wait
 * for. Hover, focus and the small state changes stay in CSS, where they are
 * cheaper and closer to the rule they belong to.
 *
 * Two rules, and every animation in the archive obeys both:
 *
 *   1. The resting state is the CSS state. GSAP moves an element FROM an
 *      offset TO where the stylesheet already put it, and clears what it set
 *      the moment it finishes. If an animation never runs, the page still
 *      looks finished.
 *   2. prefers-reduced-motion means no movement. The helpers below simply do
 *      nothing, and the element is where it always was.
 */

gsap.registerPlugin(useGSAP)

/*
 | The curves in tokens.css, named rather than redrawn. GSAP can reproduce a
 | cubic-bezier exactly with CustomEase, but that plugin is 3.2 kB gzipped and
 | these two built-ins were measured across their whole range against the
 | tokens: never further than 0.016 from --ease, and 0.012 from
 | --ease-out-soft. That is well under a pixel of a 760 ms travel, so the
 | bytes buy nothing anybody could see.
 |
 | If a token curve is ever changed, measure again rather than assuming these
 | still stand in for it.
 */
export const EASE = {
  /** --ease, cubic-bezier(0.22, 0.61, 0.36, 1): a plain, unhurried settle. */
  settle: 'power2.out',
  /** --ease-out-soft, cubic-bezier(0.16, 1, 0.3, 1): quick, then patient. */
  soft: 'expo.out',
} as const

/** Seconds. --duration-quick, --duration and --duration-slow. */
export const DURATION = {
  quick: 0.22,
  base: 0.42,
  slow: 0.76,
} as const

/** How far something travels on its way in, in pixels. Small on purpose. */
export const DISTANCE = 18

export function prefersReducedMotion(): boolean {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return false

  return window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

/**
 * Whether it is worth moving anything at all.
 *
 * Reduced motion is the obvious half. The other half is a page nobody is
 * looking at: a tab opened in the background, or restored behind another one,
 * is given no animation frames, so a tween started there paints its opening
 * state — transparent — and then sits on it until someone happens to look.
 *
 * Every animation here begins by hiding the thing it is about to bring in, so
 * that would not be a missed animation but a blank masthead. When there is no
 * frame coming, nothing is hidden and the page is simply already at rest.
 */
export function canAnimate(): boolean {
  if (typeof document !== 'undefined' && document.visibilityState === 'hidden') return false

  return !prefersReducedMotion()
}

type Targets = Element | ArrayLike<Element> | null | undefined

function toElements(targets: Targets): Element[] {
  if (!targets) return []
  if (targets instanceof Element) return [targets]

  return Array.from(targets)
}

export interface SettleOptions {
  /** Seconds between one element starting and the next. */
  stagger?: number
  /** Pixels travelled upward on the way in. */
  distance?: number
  /** Seconds. */
  duration?: number
  /** Seconds before anything moves. */
  delay?: number
}

/**
 * Brings elements to rest: from a little below and transparent, to exactly
 * where the stylesheet has them. Returns the tween, or null when motion is
 * turned off or there is nothing to move.
 */
export function settleIn(targets: Targets, options: SettleOptions = {}): gsap.core.Tween | null {
  const elements = toElements(targets)

  if (elements.length === 0 || !canAnimate()) return null

  return gsap.from(elements, {
    opacity: 0,
    y: options.distance ?? DISTANCE,
    duration: options.duration ?? DURATION.slow,
    delay: options.delay ?? 0,
    stagger: options.stagger ?? 0,
    ease: EASE.soft,
    // Once at rest, the element is the stylesheet's again.
    clearProps: 'opacity,transform',
    overwrite: 'auto',
  })
}

/**
 * The reverse: from rest to a little below and transparent. Calls `onDone`
 * once it has gone — at once when motion is off — so the caller knows when to
 * take the element out of the document.
 */
export function settleOut(
  target: Element | null | undefined,
  onDone: () => void,
  options: Pick<SettleOptions, 'distance' | 'duration'> = {},
): gsap.core.Tween | null {
  if (!target || !canAnimate()) {
    onDone()

    return null
  }

  /*
   | Whatever was bringing this in is stopped outright rather than left to be
   | overwritten. An arrival tween allowed to run to its end would apply its
   | clearProps afterwards and hand back, at full opacity, an element that had
   | just finished fading away.
   */
  gsap.killTweensOf(target)

  return gsap.to(target, {
    opacity: 0,
    y: options.distance ?? 10,
    duration: options.duration ?? DURATION.quick,
    ease: EASE.settle,
    overwrite: 'auto',
    onComplete: onDone,
  })
}

export { gsap, useGSAP }
