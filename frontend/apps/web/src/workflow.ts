/**
 * Scope switch for the "Jewellery Manufacturing ERP – Workflow Blueprint" (Jewellery_Manufacturing_ERP_Workflow.pdf).
 *
 * The UI currently shows only the 7-stage flow from that document:
 *   01 Retailer B2B portal → 02 CRM & orders → 03 Work order → 04 Gold issue & karigar chain
 *   → 05 Reconciliation & QC → 06 Finished inventory → 07 Dispatch & tracking
 *
 * Screens outside that flow are hidden from menus/tiles only. Their code, routes and APIs are untouched,
 * so deep links (e.g. "Bill now" on a dispatch) still work. Set SHOW_EXTRAS = true to bring everything back.
 */
export const SHOW_EXTRAS = false
