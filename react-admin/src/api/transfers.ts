import { get, post, put } from './client'

export interface TransferItem {
  id:                   number
  product_id:           number
  product_variant_id:   number | null
  quantity_requested:   number
  quantity_received: number
  source_stock:         number | null
  product: { id: number; sku: string; name: string; image_url: string | null } | null
  variant: { id: number; sku: string; variant_name: string } | null
}

export interface Transfer {
  id:              number
  transfer_number: string
  status:          'pending' | 'approved' | 'in_transit' | 'completed' | 'cancelled'
  notes:           string | null
  items_count:     number
  requested_at:    string | null
  approved_at:     string | null
  completed_at:    string | null
  created_at:      string
  from_outlet:     { id: number; name: string } | null
  to_outlet:       { id: number; name: string } | null
  requested_by:    { id: number; name: string } | null
  approved_by:     { id: number; name: string } | null
  completed_by:    { id: number; name: string } | null
  items:           TransferItem[]
}

export interface TransferStats {
  total:      number
  pending:    number
  approved:   number
  in_transit: number
  completed:  number
  cancelled:  number
}

export const transfersApi = {
  list: (params?: Record<string, string>) =>
    get<{ data: Transfer[]; meta: any; stats: TransferStats }>(
      '/v1/admin/inventory/transfers', { params }
    ),

  get: (id: number) =>
    get<{ transfer: Transfer }>(`/v1/admin/inventory/transfers/${id}`),

  create: (data: {
    from_outlet_id: number
    to_outlet_id:   number
    notes?:         string
    items: { product_id: number; product_variant_id?: number | null; quantity_requested: number }[]
  }) => post<{ message: string; transfer: Transfer }>('/v1/admin/inventory/transfers', data),

  approve:  (id: number) =>
    put<{ message: string }>(`/v1/admin/inventory/transfers/${id}/approve`),

  dispatch: (id: number, items: { id: number; quantity_received: number }[]) =>
    put<{ message: string }>(`/v1/admin/inventory/transfers/${id}/dispatch`, { items }),

  receive: (id: number) =>
    put<{ message: string }>(`/v1/admin/inventory/transfers/${id}/receive`),

  cancel: (id: number, reason?: string) =>
    put<{ message: string }>(`/v1/admin/inventory/transfers/${id}/cancel`, { reason }),
}