import { useEffect, useRef, useId, type ReactNode } from 'react'
import { clsx } from 'clsx'

interface ModalProps {
  open: boolean
  onClose: () => void
  title?: ReactNode
  children: ReactNode
  footer?: ReactNode
  size?: 'sm' | 'md' | 'lg' | 'xl' | 'full'
  closeOnBackdrop?: boolean
}

const sizeMap = {
  sm:   'max-w-sm',
  md:   'max-w-md',
  lg:   'max-w-lg',
  xl:   'max-w-2xl',
  full: 'max-w-5xl',
}

// ─── Focus trap ───────────────────────────────────────────────────────────────
// Keeps Tab / Shift+Tab cycling inside the dialog while it is open.
// Also focuses the first focusable element when the modal opens, and
// restores focus to the element that triggered the modal on close.

function useFocusTrap(
  dialogRef: React.RefObject<HTMLDivElement | null>,
  open: boolean,
) {
  // Remember which element had focus before the modal opened
  const previousFocusRef = useRef<HTMLElement | null>(null)

  useEffect(() => {
    if (!open) return

    // Save the currently focused element so we can restore it on close
    previousFocusRef.current = document.activeElement as HTMLElement

    const FOCUSABLE_SELECTORS = [
      'a[href]',
      'button:not([disabled])',
      'textarea:not([disabled])',
      'input:not([disabled]):not([type="hidden"])',
      'select:not([disabled])',
      '[tabindex]:not([tabindex="-1"])',
    ].join(', ')

    const dialog = dialogRef.current
    if (!dialog) return

    // Focus the first focusable element inside the dialog
    const focusableEls = Array.from(
      dialog.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTORS),
    )
    focusableEls[0]?.focus()

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key !== 'Tab') return

      // Re-query each time in case children mount/unmount
      const els = Array.from(
        dialog.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTORS),
      )
      if (els.length === 0) { e.preventDefault(); return }

      const first = els[0]
      const last  = els[els.length - 1]

      if (e.shiftKey) {
        // Shift+Tab: if focus is on first element, wrap to last
        if (document.activeElement === first) {
          e.preventDefault()
          last.focus()
        }
      } else {
        // Tab: if focus is on last element, wrap to first
        if (document.activeElement === last) {
          e.preventDefault()
          first.focus()
        }
      }
    }

    document.addEventListener('keydown', handleKeyDown)

    return () => {
      document.removeEventListener('keydown', handleKeyDown)
      // Restore focus to the triggering element when modal closes
      previousFocusRef.current?.focus()
    }
  }, [open, dialogRef])
}

// ─── Modal ────────────────────────────────────────────────────────────────────

export function Modal({
  open,
  onClose,
  title,
  children,
  footer,
  size = 'md',
  closeOnBackdrop = true,
}: ModalProps) {
  const dialogRef = useRef<HTMLDivElement>(null)
  // Unique id for aria-labelledby - stable across renders
  const titleId = useId()

  // Focus trap (also restores focus on close)
  useFocusTrap(dialogRef, open)

  // Lock body scroll
  useEffect(() => {
    if (open) document.body.style.overflow = 'hidden'
    else document.body.style.overflow = ''
    return () => { document.body.style.overflow = '' }
  }, [open])

  // Escape key
  useEffect(() => {
    const handler = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose() }
    if (open) window.addEventListener('keydown', handler)
    return () => window.removeEventListener('keydown', handler)
  }, [open, onClose])

  if (!open) return null

  return (
    // The outer div is presentational (positioning only) - role/aria live on the panel
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      {/* Backdrop - aria-hidden so screen readers skip it */}
      <div
        className="absolute inset-0 bg-surface-950/50 backdrop-blur-sm animate-fade-in"
        aria-hidden="true"
        onClick={closeOnBackdrop ? onClose : undefined}
      />

      {/* Panel - this is the actual dialog */}
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={title ? titleId : undefined}
        className={clsx(
          'relative w-full bg-white rounded-2xl shadow-card-lg',
          'animate-slide-up flex flex-col max-h-[90vh]',
          sizeMap[size],
        )}
      >
        {/* Header */}
        {title && (
          <div className="flex items-center justify-between px-6 py-4 border-b border-surface-100 shrink-0">
            {/* id matches aria-labelledby on the panel above */}
            <h2
              id={titleId}
              className="font-display text-base font-semibold text-surface-900"
            >
              {title}
            </h2>
            <button
              onClick={onClose}
              className="btn-icon btn-ghost text-surface-400 hover:text-surface-700 -mr-1"
              aria-label="Close modal"
            >
              <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        )}

        {/* Body */}
        <div className="flex-1 overflow-y-auto px-6 py-5">{children}</div>

        {/* Footer */}
        {footer && (
          <div className="px-6 py-4 border-t border-surface-100 flex items-center justify-end gap-3 shrink-0 bg-surface-50/50 rounded-b-2xl">
            {footer}
          </div>
        )}
      </div>
    </div>
  )
}
