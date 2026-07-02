import { get, put } from './client'

export interface LowStockAlert {
  type:          'product' | 'material'
  id:            number
  severity:      'out_of_stock' | 'low_stock'
  quantity:      number
  quantity_available?: number
  reorder_point: number
  unit?:         string
  product?: { id: number; sku: string; name: string; image_url: string | null }
  variant?: { id: number; sku: string; variant_name: string }
  outlet?:  { id: number; name: string }
  material?: { id: number; code: string; name: string; category: string | null }
  by_outlet?: { outlet_name: string; quantity_on_hand: number }[]
}

export interface LowStockSummary {
  products_out_of_stock:  number
  products_low_stock:     number
  materials_out_of_stock: number
  materials_low_stock:    number
  total:                  number
}

export const lowStockApi = {
  list: (type?: 'all' | 'products' | 'materials') =>
    get<{ data: LowStockAlert[]; summary: LowStockSummary }>(
      '/v1/admin/inventory/low-stock-alerts',
      { params: type && type !== 'all' ? { type } : undefined }
    ),

  updateThreshold: (inventoryItemId: number, data: { reorder_point: number; reorder_quantity?: number }) =>
    put<{ message: string }>(`/v1/admin/inventory/low-stock-alerts/${inventoryItemId}/threshold`, data),
}