import type { JobCard } from '../metal/types'

export type OrderStatus =
  | 'new' | 'pending_approval' | 'approved' | 'work_order_created' | 'material_issued' | 'in_production'
  | 'under_qc' | 'rework_required' | 'production_completed' | 'ready_for_dispatch' | 'partially_dispatched'
  | 'dispatched' | 'delivered' | 'closed' | 'on_hold' | 'cancelled'

type Tone = 'neutral' | 'brand' | 'success' | 'warning' | 'danger'

/** Spec §4 order statuses, in lifecycle order. */
export const ORDER_STATUS: Record<OrderStatus, { label: string; tone: Tone }> = {
  new: { label: 'New order', tone: 'warning' },
  pending_approval: { label: 'Pending approval', tone: 'warning' },
  approved: { label: 'Approved', tone: 'brand' },
  work_order_created: { label: 'Work order created', tone: 'brand' },
  material_issued: { label: 'Material issued', tone: 'brand' },
  in_production: { label: 'In production', tone: 'brand' },
  under_qc: { label: 'Under quality check', tone: 'brand' },
  rework_required: { label: 'Rework required', tone: 'danger' },
  production_completed: { label: 'Production completed', tone: 'success' },
  ready_for_dispatch: { label: 'Ready for dispatch', tone: 'success' },
  partially_dispatched: { label: 'Part dispatched', tone: 'success' },
  dispatched: { label: 'Dispatched', tone: 'success' },
  delivered: { label: 'Delivered', tone: 'success' },
  closed: { label: 'Closed', tone: 'neutral' },
  on_hold: { label: 'On hold', tone: 'danger' },
  cancelled: { label: 'Cancelled', tone: 'neutral' },
}

export const PAYMENT_STATUS: Record<string, { label: string; tone: Tone }> = {
  unpaid: { label: 'Unpaid', tone: 'neutral' },
  advance: { label: 'Advance paid', tone: 'brand' },
  partly_paid: { label: 'Part paid', tone: 'warning' },
  paid: { label: 'Paid', tone: 'success' },
}

export const SHIPMENT_STATUS: Record<string, { label: string; tone: Tone }> = {
  packing: { label: 'Packing', tone: 'neutral' },
  dispatched: { label: 'Dispatched', tone: 'brand' },
  in_transit: { label: 'In transit', tone: 'brand' },
  out_for_delivery: { label: 'Out for delivery', tone: 'warning' },
  delivered: { label: 'Delivered', tone: 'success' },
  failed: { label: 'Delivery failed', tone: 'danger' },
}

/** Dashboard tile groups (the API understands these as ?status=). */
export const ORDER_GROUPS: Record<string, string> = {
  awaiting: 'Awaiting approval', production: 'In production', pending: 'All pending', completed: 'Completed',
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
  estimated_value: string | null
  advance_amount: string
  payment_status: string
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
  customization: string | null
  reference_image: string | null
  design: { id: number; code: string; name: string; images?: { thumb_url: string | null; url: string }[] }
  karat: { id: number; label: string }
  work_orders: { id: number; wo_no: string; status: string; qty: number; due_date: string | null }[]
}

export interface Shipment {
  id: number; challan_no: string; date: string; pieces: number; gross_wt?: string; net_wt?: string; fine_wt: string
  shipment_status: string; courier: string | null; tracking_no: string | null; tracking_url: string | null
  delivered_at?: string | null; invoice_voucher_id?: number | null
}

export interface OrderDetail extends Omit<OrderRow, 'lines_count' | 'qty_total' | 'qty_dispatched_total'> {
  remarks: string | null
  cancel_reason: string | null
  approved_at: string | null
  approver: { id: number; name: string } | null
  retailer: OrderRow['retailer'] & { salesperson?: { id: number; name: string } | null }
  lines: OrderLine[]
  dispatches: Shipment[]
}

export interface OrderPayments {
  value: string | null; value_is_estimate: boolean; invoiced: string; paid: string; balance: string | null; status: string
  receipts: { id: number; voucher_no: string; date: string; amount: string; status: string }[]
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
  assignee: { id: number; name: string } | null
  remarks: string | null
  started_at: string | null
  done_at: string | null
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
  qc_defects?: string | null
  qc_checklist?: Record<string, boolean> | null
  storage_location?: string | null
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
