// src/api/expenses.ts
import { get, post, put, del } from '@/api/client'
import { fetchSignedFile } from '@/api/signedFiles'

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
  status: 'draft' | 'pending_approval' | 'changes_requested' | 'approved' | 'rejected' | 'paid' | 'cancelled'
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
  // Paid from the imprest: the cash left the box when it was recorded. A
  // rejected/cancelled one waits on the super admin: returned or written off.
  imprest_account_id?: number | null
  imprest_resolution?: 'pending' | 'returned' | 'written_off' | null
  imprest_resolved_at?: string | null
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
  action: 'approved' | 'rejected' | 'changes_requested' | 'requested_info'
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
  imprest?: 'yes' | 'no' | 'unresolved'
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

export type ExpenseBulkAction = 'approve' | 'reject' | 'request_changes' | 'mark_paid'

export interface ExpenseBulkResult {
  id: number
  /** null when the expense was not found or is not one the caller can see. */
  reference: string | null
  ok: boolean
  status_after: Expense['status'] | null
  /** e.g. APPROVED, SIGNED_AWAITING_NEXT_BAND, SELF_APPROVAL, NOT_YOUR_BAND, NOT_FOUND */
  code: string
  /** Plain words, ready to show. */
  message: string
}

export interface ExpenseBulkResponse {
  action: ExpenseBulkAction
  results: ExpenseBulkResult[]
  summary: { requested: number; succeeded: number; failed: number; by_code: Record<string, number> }
}

/** The bulk cap the server enforces (ids: 1..100). */
export const EXPENSE_BULK_MAX = 100

// ── API Calls ─────────────────────────────────────────────────────────────────

const BASE = '/v1/admin/expenses'

export const expensesApi = {
  // ── List / Show ──────────────────────────────────────────────────────────
  // Filters go in `params`. Until 2026-09-22 these three passed the filter
  // object as the axios config itself, which axios ignores — so search,
  // status, dates and page never reached the server.
  list: (params: ExpenseListParams = {}) =>
    get<{ expenses: any; stats: any }>(`${BASE}`, { params }),

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

  // Back to its maker with a note; the maker edits it and submits it again
  // (a new approval version). Whoever may reject it now may do this.
  requestChanges: (id: number, reason: string) =>
    post<{ message: string; expense: Expense }>(`${BASE}/${id}/request-changes`, { reason }),

  // Up to 100 at once. Each item runs its single action's own path and rules
  // on the server and is reported on its own — never all-or-nothing.
  bulk: (ids: number[], action: ExpenseBulkAction, reason?: string) =>
    post<ExpenseBulkResponse>(`${BASE}/bulk`, { ids, action, reason: reason || undefined }),

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

  // The receipt endpoint issues a fresh signed link (≤5 min, 4D) after the
  // outlet-scope check; returns a blob URL safe for <img src> / <iframe src>.
  fetchReceiptBlob: async (id: number): Promise<{ url: string; mimeType: string }> => {
    const file = await fetchSignedFile(`/api${BASE}/${id}/receipt`)
    return {
      url:      URL.createObjectURL(file.blob),
      mimeType: file.contentType,
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
    get<{ budgets: ExpenseBudget[] }>(`${BASE}/budgets`, { params }),

  createBudget: (data: Partial<ExpenseBudget>) =>
    post<{ message: string; budget: ExpenseBudget }>(`${BASE}/budgets`, data),

  updateBudget: (id: number, data: { budgeted_amount: number; notes?: string }) =>
    put<{ message: string; budget: ExpenseBudget }>(`${BASE}/budgets/${id}`, data),

  // ── Summary ──────────────────────────────────────────────────────────────
  summary: (params?: { start_date?: string; end_date?: string; outlet_id?: number }) =>
    get<any>(`${BASE}/summary`, { params }),
}

// ── Helpers ───────────────────────────────────────────────────────────────────

export const EXPENSE_STATUS_CONFIG = {
  draft:            { label: 'Draft',            bg: 'bg-surface-100',   text: 'text-surface-500',   dot: 'bg-surface-400'   },
  pending_approval: { label: 'Pending Approval', bg: 'bg-warning-light', text: 'text-warning',       dot: 'bg-warning'       },
  changes_requested:{ label: 'Changes Requested', bg: 'bg-brand-50',     text: 'text-brand-700',     dot: 'bg-brand-500'     },
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
  // Whole shillings without ".00" — on a report every figure carried two zeros
  // that said nothing and pushed large numbers onto two lines. Cents, when
  // there are any, still show both digits.
  const n = Number(amount ?? 0)
  const cents = Math.round(n * 100) % 100 !== 0
  // A non-breaking space: "KES" never wraps away from its amount (a phone card
  // showed "KES" alone on one line and the number on the next).
  return 'KES\u00A0' + n.toLocaleString('en-KE', { minimumFractionDigits: cents ? 2 : 0, maximumFractionDigits: 2 })
}