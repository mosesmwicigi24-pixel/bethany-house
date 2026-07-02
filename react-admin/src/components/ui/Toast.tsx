import { useToastStore } from '@/store/toast.store'
import { clsx } from 'clsx'
import type { ToastVariant } from '@/types'

const variantStyles: Record<ToastVariant, string> = {
  success: 'bg-success text-white',
  error:   'bg-danger text-white',
  warning: 'bg-warning text-white',
  info:    'bg-info text-white',
}

const icons: Record<ToastVariant, string> = {
  success: '✓',
  error:   '✕',
  warning: '⚠',
  info:    'ℹ',
}

export function ToastContainer() {
  const { toasts, dismiss } = useToastStore()

  if (!toasts.length) return null

  return (
    <div
      aria-live="polite"
      aria-atomic="false"
      className="fixed bottom-4 right-4 z-50 flex flex-col gap-2 max-w-sm w-full pointer-events-none"
    >
      {toasts.map((toast) => (
        <div
          key={toast.id}
          className={clsx(
            'flex items-start gap-3 px-4 py-3 rounded-xl shadow-card-lg',
            'animate-slide-up pointer-events-auto',
            variantStyles[toast.variant],
          )}
        >
          <span className="text-sm font-bold mt-px shrink-0">{icons[toast.variant]}</span>
          <p className="text-sm flex-1 leading-snug">{toast.message}</p>
          <button
            onClick={() => dismiss(toast.id)}
            className="ml-2 text-white/70 hover:text-white text-lg leading-none shrink-0"
            aria-label="Dismiss"
          >
            ×
          </button>
        </div>
      ))}
    </div>
  )
}
