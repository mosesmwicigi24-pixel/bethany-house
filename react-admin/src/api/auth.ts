import { get, post, put } from './client'
import type { LoginCredentials, LoginResponse, User } from '@/types'

export const authApi = {
  login: (credentials: LoginCredentials) =>
    post<LoginResponse>('/v1/admin/auth/login', credentials),

  logout: () =>
    post<{ message: string }>('/v1/admin/auth/logout'),

  me: () =>
    get<{ user: User }>('/v1/admin/auth/me'),

  // `challenge` is the one-time proof the password step returned; the server
  // refuses the code without it. A recovery code may stand in for the code.
  verify2fa: (userId: number, code: string, challenge: string, useRecoveryCode = false) =>
    post<LoginResponse>('/v1/admin/auth/2fa/verify', useRecoveryCode
      ? { user_id: userId, recovery_code: code, challenge }
      : { user_id: userId, code, challenge }),

  // Staged 2FA rollout: set up two-step sign-in during sign-in (Phase 4C).
  setup2fa: (userId: number, setupToken: string) =>
    post<{ secret_key: string; qr_code_url: string }>('/v1/admin/auth/2fa/setup', { user_id: userId, setup_token: setupToken }),

  confirm2faSetup: (userId: number, setupToken: string, code: string) =>
    post<LoginResponse>('/v1/admin/auth/2fa/setup/confirm', { user_id: userId, setup_token: setupToken, code }),

  // Re-confirm identity for a privileged action (password, or the 2FA code).
  stepUp: (payload: { password?: string; code?: string }) =>
    post<{ confirmed_until: string }>('/v1/admin/auth/step-up', payload),

  // Release a PIN-locked session.
  unlockWithPin: (pin: string) =>
    post<{ message: string; unlocked: boolean }>('/v1/admin/auth/pin/unlock', { pin }),

  regenerateRecoveryCodes: () =>
    post<{ recovery_codes: string[] }>('/v1/admin/auth/2fa/recovery-codes'),

  setTerminalPin: (payload: { pin: string; pin_confirmation: string; current_password: string }) =>
    put<{ message: string; terminal_pin_set: boolean }>('/v1/admin/profile/terminal-pin', payload),

  forgotPassword: (email: string) =>
    post<{ message: string }>('/v1/admin/auth/forgot-password', { email }),

  resetPassword: (payload: { token: string; email: string; password: string; password_confirmation: string }) =>
    post<{ message: string }>('/v1/admin/auth/reset-password', payload),
}
