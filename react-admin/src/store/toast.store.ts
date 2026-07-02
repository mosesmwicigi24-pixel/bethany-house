import { create } from 'zustand'
import type { Toast, ToastVariant } from '@/types'

interface ToastStore {
  toasts: Toast[]
  push: (message: string, variant?: ToastVariant, duration?: number) => void
  dismiss: (id: string) => void
  success: (message: string) => void
  error: (message: string) => void
  warning: (message: string) => void
  info: (message: string) => void
}

export const useToastStore = create<ToastStore>((set, get) => ({
  toasts: [],

  push: (message, variant = 'info', duration = 4000) => {
    const id = Math.random().toString(36).slice(2)
    set((s) => ({ toasts: [...s.toasts, { id, message, variant, duration }] }))
    if (duration > 0) {
      setTimeout(() => get().dismiss(id), duration)
    }
  },

  dismiss: (id) => set((s) => ({ toasts: s.toasts.filter((t) => t.id !== id) })),

  success: (message) => get().push(message, 'success'),
  error:   (message) => get().push(message, 'error', 6000),
  warning: (message) => get().push(message, 'warning'),
  info:    (message) => get().push(message, 'info'),
}))
