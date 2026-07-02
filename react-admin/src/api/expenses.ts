// src/api/expenses.ts
import { get, post, put, del, api } from '@/api/client'

// ── Types ─────────────────────────────────────────────────────────────────────

export interface ExpenseCategory {
  id: number
  name: string
  code: string
  description: string | null
  parent_id: number | null
  color: string | null
  icon: string | null
  requires_approval_above: number | null
  budget_monthly: number | null
  budget_annual: number | null
  is_active: boolean
  is_tax_deductible: boolean
  gl_code: string | null
  current_month_spend: number
  budget_utilization_percent: number | null
  children: ExpenseCategory[]
}

export interface Expense {
  id: number
  reference_number: string
  title: string
  description: string | null
  category_id: number
  category: ExpenseCategory | null
  amount: number
  currency_code: string
  exchange_rate: number
  amount_kes: number
  expense_date: string
  payment_method: string
  payment_reference: string | null
  vendor_name: string | null
  vendor_contact: string | null
  outlet_id: number | null
  outlet: { id: number; name: string } | null
  department: string | null
  is_recurring: boolean
  recurrence_frequency: string | null
  recurrence_end_date: string | null
  status: 'draft' | 'pending_approval' | 'approved' | 'rejected' | 'paid' | 'cancelled'
  submitted_by: number | null
  submitted_at: string | null
  approved_by: number | null
  approved_at: string | null
  rejected_by: number | null
  rejection_reason: string | null
  paid_at: string | null
  receipt_path: string | null
  notes: string | null
  tags: string[] | null
  line_items_count: number
  created_by: number
  created_at: string
  // Expanded relations (show endpoint)
  submittedBy?: { id: number; first_name: string; last_name: string }
  approvedBy?: { id: number; first_name: string; last_name: string }
  approvals?: ExpenseApproval[]
  lineItems?: ExpenseLineItem[]
}

export interface ExpenseApproval {
  id: number
  expense_id: number
  approver_id: number
  action: 'approved' | 'rejected' | 'requested_info'
  comments: string | null
  acted_at: string
  step: number
  approver?: { id: number; first_name: string; last_name: string }
}

export interface ExpenseLineItem {
  id: number
  expense_id: number
  description: string
  category_id: number | null
  quantity: number
  unit_price: number
  amount: number
  tax_amount: number
}

export interface ExpenseBudget {
  id: number
  category_id: number
  outlet_id: number | null
  period_type: 'monthly' | 'quarterly' | 'annual'
  period_year: number
  period_number: number
  budgeted_amount: number
  actual_spend: number
  variance: number
  utilization_percent: number
  category?: ExpenseCategory
  outlet?: { id: number; name: string }
}

export interface ExpenseListParams {
  status?: string
  category_id?: number
  outlet_id?: number
  start_date?: string
  end_date?: string
  search?: string
  min_amount?: number
  max_amount?: number
  per_page?: number
  sort?: string
  direction?: 'asc' | 'desc'
  page?: number
}

export interface CreateExpensePayload {
  title: string
  description?: string
  category_id: number
  expense_date: string
  amount: number
  currency_code: string
  payment_method: string
  payment_reference?: string
  vendor_name?: string
  vendor_contact?: string
  outlet_id?: number
  department?: string
  is_recurring?: boolean
  recurrence_frequency?: string
  notes?: string
  tags?: string[]
  purchase_order_id?: number
  line_items?: Array<{
    description: string
    category_id?: number
    quantity: number
    unit_price: number
    tax_amount?: number
  }>
}

// ── API Calls ─────────────────────────────────────────────────────────────────

const BASE = '/v1/admin/expenses'

export const expensesApi = {
  // ── List / Show ──────────────────────────────────────────────────────────
  list: (params: ExpenseListParams = {}) =>
    get<{ expenses: any; stats: any }>(`${BASE}`, params as Record<string, string | number>),

  show: (id: number) =>
    get<{ expense: Expense }>(`${BASE}/${id}`),

  // ── CRUD ─────────────────────────────────────────────────────────────────
  create: (data: CreateExpensePayload) =>
    post<{ message: string; expense: Expense }>(`${BASE}`, data),

  update: (id: number, data: Partial<CreateExpensePayload>) =>
    put<{ message: string; expense: Expense }>(`${BASE}/${id}`, data),

  delete: (id: number) =>
    del<{ message: string }>(`${BASE}/${id}`),

  // ── Workflow ─────────────────────────────────────────────────────────────
  submit: (id: number) =>
    post<{ message: string; expense: Expense }>(`${BASE}/${id}/submit`, {}),

  approve: (id: number, comments?: string) =>
    post<{ message: string; expense: Expense }>(`${BASE}/${id}/approve`, { comments }),

  reject: (id: number, reason: string) =>
    post<{ message: string; expense: Expense }>(`${BASE}/${id}/reject`, { reason }),

  markPaid: (id: number, data?: { payment_reference?: string; payment_method?: string }) =>
    post<{ message: string; expense: Expense }>(`${BASE}/${id}/mark-paid`, data ?? {}),

  cancel: (id: number) =>
    post<{ message: string; expense: Expense }>(`${BASE}/${id}/cancel`, {}),

  // ── Receipt ──────────────────────────────────────────────────────────────
  uploadReceipt: (id: number, file: File) => {
    const form = new FormData()
    form.append('receipt', file)
    return post<{ message: string; receipt_path: string }>(`${BASE}/${id}/receipt`, form)
  },

  // Fetches the receipt through the authenticated Axios client and returns
  // a blob URL safe to use in <img src> or <iframe src>.
  fetchReceiptBlob: async (id: number): Promise<{ url: string; mimeType: string }> => {
    const response = await api.get(`${BASE}/${id}/receipt`, { responseType: 'blob' })
    const blob = response.data as Blob
    return {
      url:      URL.createObjectURL(blob),
      mimeType: blob.type,
    }
  },

  // ── Categories ──────────────────────────────────────────────────────────
  categories: () =>
    get<{ categories: ExpenseCategory[] }>(`${BASE}/categories`),

  createCategory: (data: Partial<ExpenseCategory>) =>
    post<{ message: string; category: ExpenseCategory }>(`${BASE}/categories`, data),

  updateCategory: (id: number, data: Partial<ExpenseCategory>) =>
    put<{ message: string; category: ExpenseCategory }>(`${BASE}/categories/${id}`, data),

  // ── Budgets ──────────────────────────────────────────────────────────────
  budgets: (params?: Partial<ExpenseBudget>) =>
    get<{ budgets: ExpenseBudget[] }>(`${BASE}/budgets`, params as Record<string, string | number>),

  createBudget: (data: Partial<ExpenseBudget>) =>
    post<{ message: string; budget: ExpenseBudget }>(`${BASE}/budgets`, data),

  updateBudget: (id: number, data: { budgeted_amount: number; notes?: string }) =>
    put<{ message: string; budget: ExpenseBudget }>(`${BASE}/budgets/${id}`, data),

  // ── Summary ──────────────────────────────────────────────────────────────
  summary: (params?: { start_date?: string; end_date?: string; outlet_id?: number }) =>
    get<any>(`${BASE}/summary`, params as Record<string, string | number>),
}

// ── Helpers ───────────────────────────────────────────────────────────────────

export const EXPENSE_STATUS_CONFIG = {
  draft:            { label: 'Draft',            bg: 'bg-surface-100',   text: 'text-surface-500',   dot: 'bg-surface-400'   },
  pending_approval: { label: 'Pending Approval', bg: 'bg-warning-light', text: 'text-warning',       dot: 'bg-warning'       },
  approved:         { label: 'Approved',          bg: 'bg-info-light',    text: 'text-info',          dot: 'bg-info'          },
  paid:             { label: 'Paid',              bg: 'bg-success-light', text: 'text-success',       dot: 'bg-success'       },
  rejected:         { label: 'Rejected',          bg: 'bg-danger-light',  text: 'text-danger',        dot: 'bg-danger'        },
  cancelled:        { label: 'Cancelled',         bg: 'bg-surface-100',   text: 'text-surface-400',   dot: 'bg-surface-300'   },
} as const

export const PAYMENT_METHODS = [
  { value: 'cash',          label: 'Cash' },
  { value: 'bank_transfer', label: 'Bank Transfer' },
  { value: 'mpesa',         label: 'M-PESA' },
  { value: 'card',          label: 'Card' },
  { value: 'cheque',        label: 'Cheque' },
  { value: 'other',         label: 'Other' },
]

export function fmtKes(amount: number | null | undefined): string {
  if (amount == null) return 'KES 0.00'
  return 'KES ' + Number(amount).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}