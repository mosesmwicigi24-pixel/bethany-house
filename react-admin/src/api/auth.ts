import { get, post } from './client'
import type { LoginCredentials, LoginResponse, User } from '@/types'

export const authApi = {
  login: (credentials: LoginCredentials) =>
    post<LoginResponse>('/v1/admin/auth/login', credentials),

  logout: () =>
    post<{ message: string }>('/v1/admin/auth/logout'),

  me: () =>
    get<{ user: User }>('/v1/admin/auth/me'),

  // `challenge` is the one-time proof the password step returned; the server
  // refuses the code without it.
  verify2fa: (userId: number, code: string, challenge: string) =>
    post<LoginResponse>('/v1/admin/auth/2fa/verify', { user_id: userId, code, challenge }),

  forgotPassword: (email: string) =>
    post<{ message: string }>('/v1/admin/auth/forgot-password', { email }),

  resetPassword: (payload: { token: string; email: string; password: string; password_confirmation: string }) =>
    post<{ message: string }>('/v1/admin/auth/reset-password', payload),
}
