export interface Estimate { weight: string; metal: string; making: string; total: string; fine: string }

export interface PortalDesign {
  id: number
  code: string
  name: string
  category: string | null
  approx_weight_min: string | null
  approx_weight_max: string | null
  sizes: string[]
  description: string | null
  karats: { id: number; label: string; estimate: Estimate | null }[]
  images: { id: number; url: string; thumb_url: string | null }[]
  collections: string[]
  stone_details: string | null
  specifications: string | null
  min_order_qty: number
  production_days: number | null
  availability: 'in_stock' | 'made_to_order' | 'unavailable'
  making: { charge: string | null; type: string | null } | null
  wishlisted?: boolean
}

export const AVAILABILITY: Record<string, string> = { in_stock: 'Ready stock', made_to_order: 'Made to order', unavailable: 'Not available' }

export interface CartLine {
  id: number
  qty: number
  size: string | null
  remarks: string | null
  target_wt_min: string | null
  target_wt_max: string | null
  design: { id: number; code: string; name: string; sizes: string[]; photo: string | null; approx_weight_min: string | null; approx_weight_max: string | null; min_order_qty: number; production_days: number | null }
  karat: { id: number; label: string }
  est_fine: string | null
  est_making: string | null
  est_value: string | null
}

export interface CartSummary {
  data: CartLine[]
  totals: { pieces: number; est_fine: string; est_making: string; est_value: string }
}

export type Stage = 'received' | 'approved' | 'in_production' | 'quality_check' | 'ready' | 'dispatched' | 'delivered' | 'on_hold' | 'cancelled'

export const STAGE_LABEL: Record<string, string> = {
  received: 'Order received', approved: 'Approved', in_production: 'In production', quality_check: 'Quality check', ready: 'Ready for dispatch',
  dispatched: 'Dispatched', delivered: 'Delivered', on_hold: 'On hold', cancelled: 'Cancelled',
}

export const SHIPMENT_LABEL: Record<string, string> = {
  packing: 'Packing', dispatched: 'Dispatched', in_transit: 'In transit', out_for_delivery: 'Out for delivery', delivered: 'Delivered', failed: 'Delivery failed',
}

export const inr = (v: string | number | null | undefined) => `₹${Number(v ?? 0).toLocaleString('en-IN', { maximumFractionDigits: 0 })}`
