# Tasks: Pipelinq views → declarative (round 1)

## 1. Dead orphan views

- [x] 1.1 Confirm the nine audit-listed orphan views are gone with zero
  importers (removed in prior `dee851b2`): ClientList, ComplaintCreateDialog,
  ProductCreateDialog, AdminSettingsExportPage, UserSettings, and the four
  bespoke detail orphans (Lead/Complaint/Product/Request) — the latter already
  covered by declarative `type:"detail"` pages.
- [x] 1.2 KEEP `avg/AvgIntakeView.vue` — it is live (registered + reachable via
  `AvgIntake` page, hosts the bespoke `AvgIntakeForm`), not a dead orphan.

## 2. Convert list pages to type:index

- [x] 2.1 PosTransactions → `type:"index"` (+ remove view + registry entry).
- [x] 2.2 PosRefunds → `type:"index"` (+ remove view + registry entry).
- [x] 2.3 Blasts → `type:"index"` (+ remove view + registry entry).
- [x] 2.4 ZReports → `type:"index"` (+ remove `ZReportList.vue` + registry
  entry); status badge colorMap + date formatting; view action → ZReportDetail.
- [x] 2.5 Bookings → `type:"index"` (+ remove `BookingList.vue` + registry
  entry); datetime + status badge; no Add; view action → BookingDetail.
- [x] 2.6 KEEP Resources / Services / Projects / BillingCategories custom with a
  recorded `_note` (create-to-detail gap, currency, colour swatch, custom sort).

## 3. Analytics dashboard

- [x] 3.1 KEEP Analytics custom with a recorded `_note` — its KPIs come from a
  custom cross-module summary endpoint with a page-level period filter that no
  declarative dashboard widget can express.

## 4. Verify

- [x] 4.1 `npm run build` green (bundles ../nextcloud-vue); manifest check + JSON
  parse + `npm run lint` clean on changed files; vitest at baseline (32 pass;
  the pre-existing recurringRevenue orphan-import failure is out of scope).
- [x] 4.2 Live-verify each converted page on :8080 renders its rows/columns/
  badges/dates and routes row → detail with no NEW console errors.
