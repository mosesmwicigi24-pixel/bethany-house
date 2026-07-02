// src/api/transactions.ts
import { get, post } from '@/api/client'

const BASE = '/v1/admin/payment-transactions'

export interface PaymentTransaction {
  id:                      number
  payment_number:          string
  payment_method:          string
  amount:                  string
  currency_code:           string
  status:                  'paid' | 'pending' | 'failed' | 'refunded' | 'partially_refunded' | 'voided'
  provider:                string | null
  provider_transaction_id: string | null
  provider_reference:      string | null
  requires_approval:       boolean
  approval_status:         string | null
  refund_amount:           string | null
  refunded_at:             string | null
  void_reason:             string | null
  voided_at:               string | null
  voided_by:               number | null
  paid_at:                 string | null
  created_at:              string
  order: {
    id:                  number
    order_number:        string
    customer_first_name: string
    customer_last_name:  string
    customer_phone:      string | null
    currency_code:       string
  } | null
}

export interface TransactionAnalytics {
  total_volume:       number
  total_count:        number
  paid_volume:        number
  paid_count:         number
  failed_count:       number
  pending_count:      number
  refunded_volume:    number
  refunded_count:     number
  avg_transaction:    number
  success_rate:       number
  by_method: {
    payment_method:    string
    count:             number
    volume:            number
  }[]
  daily: {
    date:    string
    volume:  number
    count:   number
    failed:  number
  }[]
}

export interface TransactionListParams {
  page?:           number
  per_page?:       number
  search?:         string
  status?:         string
  payment_method?: string
  start_date?:     string
  end_date?:       string
  currency_code?:  string
  min_amount?:     number
  max_amount?:     number
  requires_approval?: boolean
}

export const transactionsApi = {
  list: (params: TransactionListParams = {}) =>
    get<{ data: PaymentTransaction[]; total: number; last_page: number }>(
      BASE, { params }
    ),

  analytics: (params: { start_date?: string; end_date?: string; currency_code?: string }) =>
    get<TransactionAnalytics>(`${BASE}/analytics`, { params }),

  show: (id: number) =>
    get<{ payment: PaymentTransaction & { order: any; approvedBy: any } }>(
      `${BASE}/${id}`
    ),

  refund: (id: number, data: { amount: number; reason: string }) =>
    post<{ message: string; payment: PaymentTransaction }>(`${BASE}/${id}/refund`, data),

  void: (id: number, data: { reason: string }) =>
    post<{ message: string; payment: PaymentTransaction }>(`${BASE}/${id}/void`, data),

  reassign: (id: number, data: { order_id: number; reason: string }) =>
    post<{ message: string; payment: PaymentTransaction }>(`${BASE}/${id}/reassign`, data),

  export: (params: TransactionListParams = {}) =>
    get<{ data: PaymentTransaction[] }>(`${BASE}/export`, { params }),
}