export interface Loc {
  id: number
  type: string
  name: string
}

export interface TxLine {
  from: Loc | null
  to: Loc | null
  karat: string | null
  kind: 'metal' | 'work' | 'raw' | 'semi' | 'rejected' | 'scrap' | 'dust' | 'handoff' | 'loss' | 'alloy' | 'deposit'
  gross_wt: string
  stone_wt: string
  findings_wt: string
  net_wt: string
  purity_pct: string
  fine_wt: string
  pieces: number | null
}

export interface Tx {
  id: number
  voucher_no: string
  type: 'opening' | 'purchase' | 'deposit' | 'issue' | 'handoff' | 'receive' | 'melt' | 'refine' | 'transfer' | 'loss_writeoff' | 'reversal' | 'finish' | 'dispatch'
  date: string
  kaarigar: { id: number; name: string } | null
  retailer: { id: number; shop_name: string } | null
  job_card: { id: number; card_no: string } | null
  /** Handoffs: the card the gold left. */
  from_job_card?: { id: number; card_no: string } | null
  party_name: string | null
  reference: string | null
  remarks: string | null
  over_limit: boolean
  reversed_by_id: number | null
  reverses_id: number | null
  kaarigar_ack: 'accepted' | 'disputed' | null
  created_by: string | null
  created_at: string
  fine_total: string
  lines: TxLine[]
}

export interface JobCard {
  id: number
  card_no: string
  status: 'open' | 'closed'
  kaarigar_status: 'started' | 'ready' | null
  description: string | null
  pieces: number | null
  due_date: string | null
  wo_step_id: number | null
  parent_job_card_id: number | null
  parent: { id: number; card_no: string; kaarigar: { id: number; name: string } | null } | null
  issued_fine: string
  handed_in_fine: string
  returned_fine: string
  scrap_fine: string
  handed_out_fine: string
  loss_fine: string
  allowed_loss_fine: string
  excess_loss_fine: string
  allowed_wastage_pct: string
  excess_resolution: 'written_off' | 'recovered' | null
  /** Difference on close waits for an approval with a reason (blueprint §3). */
  loss_status: 'pending' | 'approved' | null
  loss_reason_code: string | null
  loss_note: string | null
  started_at: string | null
  ready_at: string | null
  closed_at: string | null
  created_at: string
  kaarigar: { id: number; name: string }
  process: { id: number; name: string }
  karat: { id: number; label: string; purity_pct: string }
  design: { id: number; code: string; name: string } | null
  live: CardFigures | null
}

export interface CardFigures {
  issued: string
  handed_in: string
  returned: string
  scrap: string
  handed_out: string
  outstanding: string
  by_kind: Partial<Record<'work' | 'raw' | 'semi' | 'rejected' | 'scrap' | 'dust', string>>
}

export const LOSS_REASONS: Record<string, string> = {
  process_loss: 'Normal process loss',
  dust_not_recovered: 'Dust / filings not yet recovered',
  stone_setting_loss: 'Stone setting loss',
  polishing_loss: 'Polishing loss',
  weighing_difference: 'Weighing / purity difference',
  other: 'Other (explain in the note)',
}

export interface Summary {
  gold: Record<'vault' | 'kaarigar' | 'wo' | 'scrap' | 'finished' | 'refiner' | 'melt' | 'loss', string>
  today: Record<'issue' | 'receive', { count: number; fine: string }>
  overdue_job_cards: number
  open_job_cards: number
  wastage_alerts: number
  unconfirmed_issues: number
  disputed_issues: number
  pending_requests: number
  pending_loss_fine?: string
}

export interface StatementRow {
  date: string
  voucher_no: string
  type: string
  kind: 'issued' | 'handoff' | 'returned' | 'scrap' | 'loss'
  line_kind?: string
  job_card: string | null
  process: string | null
  karat: string
  gross_wt: string
  purity_pct: string
  in: string | null
  out: string | null
  balance: string
  remarks: string | null
}

export interface Statement {
  opening: string
  closing: string
  rows: StatementRow[]
  totals: { issued: string; handoff: string; returned: string; scrap: string; loss: string }
}

export const TX_LABEL: Record<Tx['type'], string> = {
  opening: 'Opening',
  purchase: 'Purchase',
  deposit: 'Retailer deposit',
  issue: 'Issue',
  handoff: 'Handoff (kaarigar → kaarigar)',
  receive: 'Return',
  melt: 'Melting',
  refine: 'Refining',
  transfer: 'Transfer',
  loss_writeoff: 'Approved loss',
  reversal: 'Reversal',
  finish: 'Finished goods',
  dispatch: 'Dispatch',
}
