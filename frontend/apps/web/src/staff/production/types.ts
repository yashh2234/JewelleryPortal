import type { JobCard } from '../metal/types'

export type OrderStatus = 'placed' | 'confirmed' | 'in_production' | 'ready' | 'partially_dispatched' | 'dispatched' | 'closed' | 'on_hold' | 'cancelled'

export const ORDER_STATUS: Record<OrderStatus, { label: string; tone: 'neutral' | 'brand' | 'success' | 'warning' | 'danger' }> = {
  placed: { label: 'Placed', tone: 'warning' },
  confirmed: { label: 'Confirmed', tone: 'brand' },
  in_production: { label: 'In production', tone: 'brand' },
  ready: { label: 'Ready', tone: 'success' },
  partially_dispatched: { label: 'Part dispatched', tone: 'success' },
  dispatched: { label: 'Dispatched', tone: 'success' },
  closed: { label: 'Closed', tone: 'neutral' },
  on_hold: { label: 'On hold', tone: 'danger' },
  cancelled: { label: 'Cancelled', tone: 'neutral' },
}

export interface OrderRow {
  id: number
  order_no: string
  status: OrderStatus
  source: 'staff' | 'portal'
  due_date: string | null
  created_at: string
  retailer: { id: number; shop_name: string; city: string | null }
  lines_count: number
  qty_total: number
  qty_dispatched_total: number
}

export interface OrderLine {
  id: number
  design_id: number
  karat_id: number
  size: string | null
  qty: number
  qty_dispatched: number
  target_wt_min: string | null
  target_wt_max: string | null
  stone_details: string | null
  remarks: string | null
  design: { id: number; code: string; name: string; images?: { thumb_url: string | null; url: string }[] }
  karat: { id: number; label: string }
  work_orders: { id: number; wo_no: string; status: string; qty: number; due_date: string | null }[]
}

export interface OrderDetail extends Omit<OrderRow, 'lines_count' | 'qty_total' | 'qty_dispatched_total'> {
  remarks: string | null
  cancel_reason: string | null
  lines: OrderLine[]
  dispatches: { id: number; challan_no: string; date: string; pieces: number; fine_wt: string }[]
}

export interface WorkOrder {
  id: number
  wo_no: string
  qty: number
  sizes: Record<string, number> | null
  status: 'open' | 'in_progress' | 'completed' | 'cancelled'
  priority: 'low' | 'normal' | 'high' | 'urgent'
  due_date: string | null
  target_wt_min: string | null
  target_wt_max: string | null
  rework_of_id: number | null
  remarks: string | null
  design: { id: number; code: string; name: string }
  karat: { id: number; label: string }
  current_process: string | null
  stages: { name: string; status: string }[]
  gold_in_wo: string
  overdue: boolean
}

export interface WoStep {
  id: number
  sequence: number
  status: 'pending' | 'in_progress' | 'done' | 'skipped'
  process: { id: number; name: string }
  job_cards: JobCard[]
}

export interface FinishedItem {
  id: number
  tag_no: string
  size: string | null
  gross_wt: string
  stone_wt: string
  net_wt: string
  purity_pct: string
  fine_wt: string
  huid: string | null
  qc_status: 'pending' | 'passed' | 'failed'
  qc_remarks: string | null
  status: 'in_stock' | 'at_ahc' | 'rework' | 'dispatched'
  karat_id: number
  order_line_id: number | null
  design?: { id: number; code: string; name: string }
  karat?: { id: number; label: string }
  work_order?: { id: number; wo_no: string } | null
  order_line?: { order: { id: number; order_no: string; retailer_id: number } } | null
}

export interface WorkOrderDetail extends WorkOrder {
  steps: WoStep[]
  finished_items: FinishedItem[]
  order_lines: { id: number; size: string | null; qty: number; order: { order_no: string; retailer: { shop_name: string } } }[]
}

export interface BoardCard extends WorkOrder {
  step: { id: number; status: string; sequence: number; open_cards: number; cards: number } | null
  kaarigars: string[]
  with_kaarigar_fine: string
  alert: boolean
}

export interface Ahc { id: number; name: string; bis_recognition_no: string | null; city: string | null; charge_per_piece: string; is_active: boolean }

export interface HallmarkLot {
  id: number
  lot_no: string
  status: 'draft' | 'sent' | 'returned'
  pieces: number
  gross_wt: string
  charges: string
  manak_request_no: string | null
  sent_at: string | null
  returned_at: string | null
  ahc: Ahc
  karat: { id: number; label: string; purity_pct?: string }
  items?: { id: number; finished_item_id: number; sent_wt: string; returned_wt: string | null; result: 'pass' | 'fail' | null; huid: string | null; remarks: string | null; finished_item: FinishedItem }[]
}
