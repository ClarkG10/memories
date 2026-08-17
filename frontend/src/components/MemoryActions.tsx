import { useEffect, useId, useRef, useState } from 'react'

interface Props {
  title: string
  onEdit: () => void
  onRemove: () => void
}

/**
 * The owner's controls for one memory.
 *
 * Deliberately a single quiet glyph rather than a row of buttons: removing a
 * memory should never be one stray tap away, and nothing in the caption should
 * draw the eye before the photograph does.
 */
export function MemoryActions({ title, onEdit, onRemove }: Props) {
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement | null>(null)
  const triggerRef = useRef<HTMLButtonElement | null>(null)
  const menuId = useId()

  useEffect(() => {
    if (!open) return

    const onPointerDown = (event: PointerEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false)
    }

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setOpen(false)
        triggerRef.current?.focus()
      }
    }

    document.addEventListener('pointerdown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)

    return () => {
      document.removeEventListener('pointerdown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open])

  return (
    <div className="actions" ref={containerRef}>
      <button
        ref={triggerRef}
        type="button"
        className="actions__trigger"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={open ? menuId : undefined}
        aria-label={`More for ${title}`}
        onClick={() => setOpen((value) => !value)}
      >
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
          <circle cx="5" cy="12" r="1.6" fill="currentColor" />
          <circle cx="12" cy="12" r="1.6" fill="currentColor" />
          <circle cx="19" cy="12" r="1.6" fill="currentColor" />
        </svg>
      </button>

      {open && (
        <div className="actions__menu" id={menuId} role="menu">
          <button
            type="button"
            role="menuitem"
            className="actions__item"
            onClick={() => {
              setOpen(false)
              // Focus goes back to the control that opened the menu before the
              // menu disappears; otherwise it lands on <body> and a keyboard
              // has to tab in from the top of the page again.
              triggerRef.current?.focus()
              onEdit()
            }}
          >
            Edit details
          </button>

          <button
            type="button"
            role="menuitem"
            className="actions__item actions__item--remove"
            onClick={() => {
              setOpen(false)
              triggerRef.current?.focus()
              onRemove()
            }}
          >
            Remove memory
          </button>
        </div>
      )}
    </div>
  )
}
