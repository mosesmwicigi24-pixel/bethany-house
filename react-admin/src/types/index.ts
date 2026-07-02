// ─── Auth & User ──────────────────────────────────────────────────────────────

export type UserType = 'system' | 'staff' | 'customer'

export interface User {
  id: number
  uuid: string
  first_name: string
  last_name: string
  name: string
  email: string
  phone: string | null
  user_type: UserType
  status: 'active' | 'inactive' | 'suspended'
  outlet_id: number | null
  two_factor_enabled: boolean
  must_setup_2fa: boolean
  email_verified_at: string | null
  created_at: string
  updated_at: string
  outlet?: Outlet
  roles?: Role[]
  permissions?: string[]
}

export interface AuthState {
  user: User | null
  token: string | null
  isAuthenticated: boolean
  isLoading: boolean
}

export interface LoginCredentials {
  email: string
  password: string
  remember_me?: boolean
}

export interface LoginResponse {
  message: string
  user: User
  token: string
  requires_2fa?: boolean
  user_id?: number
}

// ─── RBAC ─────────────────────────────────────────────────────────────────────

export interface Role {
  id: number
  name: string
  display_name?: string
  description?: string
  guard_name: string
  user_type: UserType
  is_active: boolean
  users_count?: number
  permissions?: Permission[]
}

export interface Permission {
  id: number
  name: string
  display_name?: string
  description?: string
  group?: string
}

// ─── Outlet ───────────────────────────────────────────────────────────────────

export interface Outlet {
  id: number
  name: string
  code: string
  type: 'warehouse' | 'store' | 'outlet'
  address?: string
  city?: string
  phone?: string
  email?: string
  is_active: boolean
  is_default: boolean
  created_at: string
}

// ─── API Response shapes ──────────────────────────────────────────────────────

export interface ApiError {
  message: string
  errors?: Record<string, string[]>
}

export interface PaginatedResponse<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number
    to: number
  }
  links: {
    first: string | null
    last: string | null
    prev: string | null
    next: string | null
  }
}

// ─── Dashboard ────────────────────────────────────────────────────────────────

export interface DashboardStats {
  total_users: number
  active_users: number
  system_users: number
  staff_users: number
  customers: number
  total_orders?: number
  pending_orders?: number
  today_orders?: number
  total_products?: number
  low_stock_products?: number
  today_sales?: number
}

export interface ActivityItem {
  type: string
  description: string
  user: string
  time: string
}

// ─── Navigation ───────────────────────────────────────────────────────────────

export interface NavItem {
  label: string
  href: string
  icon: string
  permission?: string
  anyOfPermissions?: string[]
  badge?: string | number
  children?: NavItem[]
}

export interface NavGroup {
  label: string
  items: NavItem[]
}

// ─── Shared UI ────────────────────────────────────────────────────────────────

export type ToastVariant = 'success' | 'error' | 'warning' | 'info'

export interface Toast {
  id: string
  message: string
  variant: ToastVariant
  duration?: number
}

export interface TableColumn<T> {
  key: keyof T | string
  label: string
  sortable?: boolean
  width?: string
  render?: (row: T) => React.ReactNode
}

export interface TableState {
  page: number
  perPage: number
  sortBy: string
  sortDir: 'asc' | 'desc'
  search: string
}