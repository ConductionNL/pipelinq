// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// V2 component registry for pipelinq.
//
// Every entry here corresponds to a manifest `type: "custom"` page, a
// dashboard widget rendered via a `slots` mapping, or a
// `headerComponent` / `actionsComponent` override on a typed page.
//
// Recognised kinds: page, modal, widget, form-field, cell-renderer
//
// Resolution order (v2 renderer):
//   1. Built-in page types   (CnIndexPage, CnDetailPage, CnDashboardPage, …)
//   2. Built-in widget types (version-info, register-mapping, …)
//   3. registry (this file)  ← consumer-injected components
//   4. customComponents      ← v1 fallback, kept during transition
//
// See:
//   - openspec/changes/pipelinq-manifest-v1/design.md
//   - hydra/openspec/architecture/adr-036-manifest-v2.md

// --- MyWork — bespoke per-user surface mixing tasks + leads + requests. ---
import MyWorkView from './views/MyWork.vue'

// --- Dashboard (manifest-driven type:"dashboard") — header actions and
//     per-widget slot components. The page itself is rendered by
//     CnDashboardPage from `config.widgets[]` + `config.layout[]`. ---
import DashboardHeaderActions from './views/dashboard/DashboardHeaderActions.vue'
import OpenLeadsKpiWidget from './views/dashboard/widgets/OpenLeadsKpiWidget.vue'
import OpenRequestsKpiWidget from './views/dashboard/widgets/OpenRequestsKpiWidget.vue'
import PipelineValueKpiWidget from './views/dashboard/widgets/PipelineValueKpiWidget.vue'
import OverdueKpiWidget from './views/dashboard/widgets/OverdueKpiWidget.vue'
import RequestsByStatusWidget from './views/dashboard/widgets/RequestsByStatusWidget.vue'
import ComplaintsWidget from './views/dashboard/widgets/ComplaintsWidget.vue'
import MyWorkWidget from './views/dashboard/widgets/MyWorkWidget.vue'
import ClientOverviewWidget from './views/dashboard/widgets/ClientOverviewWidget.vue'

// Bespoke kanban board with in-memory search (REQ-PIPE-022).
// See openspec/changes/2026-03-20-pipeline/design.md.
import PipelineBoardView from './views/pipeline/PipelineBoard.vue'

// --- Queues / routing rules (lib gap: no routing-rules widget). ---
import QueueListView from './views/queues/QueueList.vue'
import QueueDetailView from './views/queues/QueueDetail.vue'

// --- Surveys analytics (lib gap: no chart-widget page type). ---
import SurveyAnalyticsView from './views/surveys/SurveyAnalytics.vue'

// --- Forms visual builder (lib gap: no form-builder page type;
//     Forms list + FormSubmissions are declarative type:"index"). ---
import FormBuilderView from './views/forms/FormBuilder.vue'

// --- Reporting dashboards (lib gap: no chart-widget page type). ---
import RapportageDashboardView from './views/rapportage/RapportageDashboard.vue'
import ChannelAnalyticsView from './views/rapportage/ChannelAnalytics.vue'
import AgentPerformanceView from './views/rapportage/AgentPerformance.vue'

// --- Forecast roll-up (lib gap: no forecast/quota/override page type). ---
import ForecastDashboardView from './views/forecast/ForecastDashboard.vue'
import ForecastTrendView from './views/forecast/ForecastTrend.vue'
import LeadForecastTab from './views/leads/LeadForecastTab.vue'

// --- Leads list (lead-management spec REQ-LM-002 / REQ-LM-004 / REQ-LM-005).
//     Wraps CnIndexPage to add the stale filter, overdue row highlighting
//     and CSV import/export via the platform mass dialogs. ---
import LeadListView from './views/leads/LeadList.vue'

// --- Lead-management analytics dashboard (lead-management REQ-LM-006..008).
//     RapportageView uses CnDashboardPage with four widget slots backed by
//     the new RapportageController pipeline-stats endpoint. ---
import RapportageView from './views/rapportage/RapportageView.vue'
import PipelineFunnelWidget from './views/rapportage/PipelineFunnelWidget.vue'
import SourcePerformanceWidget from './views/rapportage/SourcePerformanceWidget.vue'
import LeadAgingWidget from './views/rapportage/LeadAgingWidget.vue'
import WinLossWidget from './views/rapportage/WinLossWidget.vue'
// --- Loyalty program (loyalty-program). ---
import LoyaltyReportingView from './views/loyalty/LoyaltyReporting.vue'
import LoyaltyAccountCreationView from './views/loyalty/LoyaltyAccountCreation.vue'

// --- Admin managers (lib gap: no pipeline-designer / settings rich-section type). ---
import PipelineManagerView from './views/settings/PipelineManager.vue'
import SyncSettingsView from './views/sync/SyncSettings.vue'

// --- BI export + data-warehouse sink (lib gap: declarative index/detail pages
//     cannot express the bespoke test-connection / test-run / enable / retry
//     actions on the export controllers, nor the run-detail manifest +
//     schema-snapshot drill-down). Object CRUD still flows through the shared
//     object store; these views add the action surface. ---
import ExportJobsView from './views/export/ExportJobs.vue'
import ExportJobFormView from './views/export/ExportJobForm.vue'
import ExportDestinationsView from './views/export/ExportDestinations.vue'
import ExportDestinationFormView from './views/export/ExportDestinationForm.vue'
import ExportRunsView from './views/export/ExportRuns.vue'
import ExportRunDetailView from './views/export/ExportRunDetail.vue'

// --- POS transactions (lib gap: list needs custom row navigation to the cart
//     editor; detail needs lifecycle action buttons + tax breakdown; form is a
//     bespoke cart editor with real-time totals). ---
import PosTransactionListView from './views/pos/PosTransactionList.vue'
import PosTransactionDetailView from './views/pos/PosTransactionDetail.vue'
import PosTransactionFormView from './views/pos/PosTransactionForm.vue'
import PosRefundListView from './views/pos/PosRefundList.vue'
import PosRefundDetailView from './views/pos/PosRefundDetail.vue'
import PosRefundFormView from './views/pos/PosRefundForm.vue'

// --- POS cash drawer (lib gap: index/detail pages cannot express the cash-shift
//     lifecycle — declare float, record drops, blind count, variance reconcile). ---
import CashShiftListView from './views/pos/CashShiftList.vue'
import CashShiftDetailView from './views/pos/CashShiftDetail.vue'

// --- POS staff PIN + role permissions (pos-staff-pin-permissions). ---
import PosRoleListView from './views/pos/PosRoleList.vue'
import PosRoleFormView from './views/pos/PosRoleForm.vue'
import PosStaffListView from './views/pos/PosStaffList.vue'
import PosStaffFormView from './views/pos/PosStaffForm.vue'

// --- POS split-tender admin (pos-split-tender REQ-PST-001).
//     Tender-type registry: list + create/edit dialog. The dialog handles
//     CRUD inline (no separate detail route). ---
import PosTenderTypeListView from './views/pos/PosTenderTypeList.vue'

// --- POS end-of-day bookkeeping (lib gap: index/detail pages cannot express the
//     server-authoritative Z-report aggregation + Shillinq submission timeline
//     + manager-gated retry). ---
import ZReportListView from './views/pos/ZReportList.vue'
import ZReportDetailView from './views/pos/ZReportDetail.vue'
import PosBookkeepingSettingsView from './views/admin/PosBookkeepingSettings.vue'

// --- BRP Monitor (bsn-validatie-en-brp-lookup): admin tile + detailed report
//     view aggregating the BrpMonitorJob output (lookups / cache-hits / errors /
//     avg response time) and the mTLS client-certificate expiry countdown. ---
import BrpMonitorView from './views/admin/BrpMonitor.vue'

// --- POS Kassakoppeling-compliant audit log (pos-kassakoppeling-audit):
//     append-only HMAC-SHA256 signed register actions chained per-register
//     with an admin-gated Belastingdienst export pack. Lib gap: declarative
//     type:"index" cannot express the bespoke /api/kassakoppeling/audit
//     endpoint, the verify-button + verification badge ramp on the detail
//     view, or the date-range + format export modal. ---
import KassakoppelingAuditListView from './views/kassakoppeling/KassakoppelingAuditList.vue'
import KassakoppelingAuditDetailView from './views/kassakoppeling/KassakoppelingAuditDetail.vue'

// --- Product barcode lookup (lib gap: index pages have no server-authoritative
//     scan-to-navigate barcode search; this view calls the scoped lookup API). ---
import ProductBarcodeSearchView from './views/products/ProductBarcodeSearch.vue'

// --- CTI screen-pop and click-to-dial (lib gap: no telephony settings page
//     type; admin needs platform + credentials + delay knobs and a webhook
//     event log; cti-screenpop-adapter). ---
import CtiSettingsView from './views/settings/CtiSettings.vue'
import CtiEventLogView from './views/settings/CtiEventLog.vue'

// --- POS pluggable payment provider adapter (pos-payment-provider-adapter):
//     admin-only credential form for Mollie / CCV / Adyen / Stripe with
//     encrypted-at-rest secrets and a per-provider "Verbinding testen" button.
//     Lib gap: no payment-provider-settings page type. ---
import PaymentSettingsForm from './views/settings/PaymentSettingsForm.vue'
// --- StUF-ZKN/BG adapter (stuf-zkn-bg-adapter): admin endpoint list with
//     per-endpoint circuit-breaker health badge and per-call audit log
//     (REQ-STUF-008, REQ-STUF-011). Lib gap: no envelope-style audit-log
//     page type with CSV export and inline XML inspection. ---
import StufEndpointsView from './views/settings/StufEndpoints.vue'
import StufAuditLogView from './views/settings/StufAuditLog.vue'

// --- Expense → Shillinq AP (pipelinq-expense-to-shillinq-ap): list with
//     apSyncStatus badge column, detail with embedded Shillinq AP card
//     (REQ-AP-005 / REQ-AP-006).
import ExpenseListView from './views/expenses/ExpenseList.vue'
import ExpenseDetailView from './views/expenses/ExpenseDetail.vue'

// --- Billing categories (billable-categories-and-tags): list view with a
//     bespoke color-swatch + DBA / active badge column layout the
//     declarative type:"index" page cannot express. Donut widget for the
//     dashboard (hours per billing category) registered as a slot. ---
import BillingCategoryListView from './views/billingCategories/BillingCategoryList.vue'
import BillingCategoryWidget from './components/dashboard/BillingCategoryWidget.vue'

// --- Klantbeeld 360 (lib gap: no cross-module KPI dashboard with a
//     trailing-period filter wired to a domain-specific aggregation
//     endpoint, and no pipeline KPI / stage-funnel page driving four
//     bespoke ratio KPIs off lead-collection client-side aggregation;
//     ClientDetail + ContactDetail aggregate 5 cross-schema sections
//     with per-section loading and a contact->client linking dialog,
//     beyond what a declarative type:"detail" page can express). ---
import AnalyticsDashboard from './views/analytics/AnalyticsDashboard.vue'
import PipelineAnalyticsView from './views/pipeline/PipelineAnalyticsView.vue'
import ClientDetail from './views/clients/ClientDetail.vue'
import ContactDetail from './views/contacts/ContactDetail.vue'

// --- Project / WBS hierarchy (project-task-hierarchy):
//     four schemas (project / projectPhase / projectTask / projectActivity)
//     surface as ProjectList → ProjectDetail (WBS tree with inline phase /
//     task / time-entry CnFormDialogs) → ProjectActivityList. Custom views
//     because the declarative type:"detail" cannot drive the cross-schema
//     parallel relation fetch, the resolved-billable inheritance chain or
//     the inline-add CnFormDialogs feeding three different schemas. ---
import ProjectList from './views/projects/ProjectList.vue'
import ProjectDetail from './views/projects/ProjectDetail.vue'
import ProjectActivityList from './views/projects/ProjectActivityList.vue'

// --- Marketing segmentation + blast (marketing-segmentation-and-blast 07):
//     three-route Vue surface — list, multi-step create wizard, live monitor.
//     The wizard embeds the missing-consent modal (own file under modals/);
//     the monitor polls /api/blasts/:id every 2s and stops on terminal status.
//     SegmentBuilder + SegmentRuleNode live under components/ for reuse by
//     forthcoming SegmentEditor / dashboard surfaces (slice 08). ---
import BlastListView from './views/blasts/BlastList.vue'
import BlastFormView from './views/blasts/BlastForm.vue'
import BlastMonitorView from './views/blasts/BlastMonitor.vue'
import BlastPerformanceDashboardView from './views/blasts/PerformanceDashboard.vue'

// --- Appointment booking — admin surface (appointment-booking 11 of 12).
//     Service / Resource / Booking list + detail views; resolved by the v2
//     renderer from the manifest.d fragment at render time. ---
import ServiceListView from './views/bookings/ServiceList.vue'
import ServiceDetailView from './views/bookings/ServiceDetail.vue'
import ResourceListView from './views/bookings/ResourceList.vue'
import ResourceDetailView from './views/bookings/ResourceDetail.vue'
import BookingListView from './views/bookings/BookingList.vue'
import BookingDetailView from './views/bookings/BookingDetail.vue'

// --- Features & Roadmap page (lib's CnFeaturesAndRoadmapView wrapper). ---

/**
 * V2 component registry.
 *
 * Keys must match the `component` strings used in the manifest.
 * All full-page custom routes are kind: "page" — the v2 renderer resolves
 * any `component` key from this registry at render time.
 *
 * @type {Record<string, { kind: string, component: object, _note?: string }>}
 */
const registry = {
	// --- MyWork — multi-entity user dashboard. ---
	MyWorkView: {
		kind: 'page',
		component: MyWorkView,
		_note: 'Personalised work surface mixing tasks + leads + requests for the current user; no single-entity typed page captures multi-entity user dashboard.',
	},

	// --- Dashboard widgets (rendered as #widget-{id} slots inside
	//     CnDashboardPage via the manifest Dashboard page's `slots` map).
	//     KPI widgets compute their counts from multiple cross-schema
	//     fetches (e.g. overdue = leads filtered against pipeline
	//     isClosed stages) that the declarative `stats-block` +
	//     `dataSource` shorthand can't express, so they ship as small
	//     custom widget components instead. ---
	DashboardHeaderActions: {
		kind: 'widget',
		component: DashboardHeaderActions,
		_note: 'Dashboard header buttons (New Lead / Request / Client + Refresh) wired as the Dashboard page actionsComponent.',
	},
	OpenLeadsKpiWidget: {
		kind: 'widget',
		component: OpenLeadsKpiWidget,
		_note: 'KPI card for open leads (leads minus those in pipeline stages flagged isClosed). Renders <CnStatsBlock>.',
	},
	OpenRequestsKpiWidget: {
		kind: 'widget',
		component: OpenRequestsKpiWidget,
		_note: 'KPI card for open requests (status new or in_progress). Renders <CnStatsBlock>.',
	},
	PipelineValueKpiWidget: {
		kind: 'widget',
		component: PipelineValueKpiWidget,
		_note: 'KPI card for total open-lead value in EUR. Renders <CnStatsBlock>.',
	},
	OverdueKpiWidget: {
		kind: 'widget',
		component: OverdueKpiWidget,
		_note: 'KPI card for overdue leads + stale requests. Renders <CnStatsBlock>.',
	},
	RequestsByStatusWidget: {
		kind: 'widget',
		component: RequestsByStatusWidget,
		_note: 'Horizontal bar chart of requests grouped by status. Standalone widget — fetches its own data.',
	},
	ComplaintsWidget: {
		kind: 'widget',
		component: ComplaintsWidget,
		_note: 'Open / overdue / status breakdown of complaints. Wraps the existing ComplaintsOverviewWidget with a self-contained fetch.',
	},
	MyWorkWidget: {
		kind: 'widget',
		component: MyWorkWidget,
		_note: 'Top-5 list of leads + requests assigned to the current user, sorted by overdue → priority → due date.',
	},
	ClientOverviewWidget: {
		kind: 'widget',
		component: ClientOverviewWidget,
		_note: 'Top-5 recent clients with a view-all link to ClientList.',
	},

	// --- Queues / routing rules. ---
	QueueListView: {
		kind: 'page',
		component: QueueListView,
		_note: 'Bespoke routing-rule editor list with priority ordering; lib gap: no routing-rules list widget.',
	},
	QueueDetailView: {
		kind: 'page',
		component: QueueDetailView,
		_note: 'Bespoke routing-rule condition + action builder; lib gap: no routing-rules detail widget.',
	},

	// --- Surveys. ---
	SurveyAnalyticsView: {
		kind: 'page',
		component: SurveyAnalyticsView,
		_note: 'Chart-driven survey response analytics with apexcharts; lib gap: no chart-widget page type.',
	},

	// --- Forms visual builder. ---
	FormBuilderView: {
		kind: 'page',
		component: FormBuilderView,
		_note: 'Visual form builder with drag-and-drop field palette; lib gap: no form-builder page type. Forms list + FormSubmissions use declarative type:"index".',
	},

	// --- Reporting dashboards. ---
	RapportageDashboardView: {
		kind: 'page',
		component: RapportageDashboardView,
		_note: 'KPI reporting dashboard with apexcharts; lib gap: no chart-widget page type.',
	},
	ChannelAnalyticsView: {
		kind: 'page',
		component: ChannelAnalyticsView,
		_note: 'Channel breakdown analytics with apexcharts; lib gap: no chart-widget page type.',
	},
	AgentPerformanceView: {
		kind: 'page',
		component: AgentPerformanceView,
		_note: 'Per-agent performance charts with apexcharts; lib gap: no chart-widget page type.',
	},

	// --- Pipeline board (kanban + list with in-memory search). ---
	PipelineBoardView: {
		kind: 'page',
		component: PipelineBoardView,
		_note: 'Pipeline kanban/list board with in-memory title search (REQ-PIPE-022). Restored after migrate-pipeline-to-deck-leaf; coexists with Deck integration.',
	},

	// --- Forecast roll-up dashboards. ---
	ForecastDashboardView: {
		kind: 'page',
		component: ForecastDashboardView,
		_note: 'Manager forecast view: hierarchy/level selector, quota progress bar, at-risk banner, per-owner table with manager-override badges; lib gap: no forecast/quota page type. All amounts are server-computed.',
	},
	ForecastTrendView: {
		kind: 'page',
		component: ForecastTrendView,
		_note: 'Forecast trend (inline SVG sparkline of commit/best-case/pipeline), week-over-week delta panel and accuracy table with colour bands; lib gap: no chart-widget page type.',
	},
	LeadForecastTab: {
		kind: 'tab',
		component: LeadForecastTab,
		_note: 'Lead-detail sidebar tab: forecast-category selector with closed-deal lock indicator, large-commit justification modal and category history. Server-side DealUpdatedListener is the authoritative enforcer; this tab is the UX. Wiring into LeadDetail.config.sidebar.tabs is a monolith manifest.json edit deferred under ADR-037 (do not edit the monolith from a feature build).',
	},

	// --- Leads list with lead-management enhancements. ---
	LeadListView: {
		kind: 'page',
		component: LeadListView,
		_note: 'Wraps CnIndexPage with the lead-management stale filter, overdue row highlighting and platform mass import/export dialogs (REQ-LM-002/004/005).',
	},

	// --- Lead-management analytics. ---
	RapportageView: {
		kind: 'page',
		component: RapportageView,
		_note: 'Lead analytics dashboard using CnDashboardPage with four widgets backed by the rapportage pipeline-stats endpoint (REQ-LM-006/007/008).',
	},
	PipelineFunnelWidget: {
		kind: 'widget',
		component: PipelineFunnelWidget,
		_note: 'Pipeline value per stage (count, total, weighted) — bar chart with pipeline filter (REQ-LM-006).',
	},
	SourcePerformanceWidget: {
		kind: 'widget',
		component: SourcePerformanceWidget,
		_note: 'Source conversion table (total, won, rate, avg) sortable per column (REQ-LM-007).',
	},
	LeadAgingWidget: {
		kind: 'widget',
		component: LeadAgingWidget,
		_note: 'Aging-bucket donut chart (≤7d / 8-14d / 15-30d / >30d) (REQ-LM-006).',
	},
	WinLossWidget: {
		kind: 'widget',
		component: WinLossWidget,
		_note: 'Win/loss pie chart + KPI stats block with a date-range selector (REQ-LM-008).',
	},
	// --- Loyalty program (loyalty-program). ---
	LoyaltyReportingView: {
		kind: 'page',
		component: LoyaltyReportingView,
		_note: 'Programme reporting dashboard: active accounts, points issued/redeemed/expired, breakage %, redemption rate, outstanding-points liability (IFRS 15 / RJ 270), tier distribution, period selector, CSV export. Server-side LoyaltyReportingService is the source of truth (REQ-LOY-008, REQ-LOY-009).',
	},
	LoyaltyAccountCreationView: {
		kind: 'page',
		component: LoyaltyAccountCreationView,
		_note: 'GDPR-compliant loyalty account enrollment form: mandatory opt-in checkbox, terms version capture, klantId+programmeId input (REQ-LOY-010-01).',
	},

	// --- Admin managers. ---
	PipelineManagerView: {
		kind: 'page',
		component: PipelineManagerView,
		_note: 'Pipeline stage + transition manager with drag-and-drop reordering; lib gap: no pipeline-designer page type.',
	},
	SyncSettingsView: {
		kind: 'page',
		component: SyncSettingsView,
		_note: 'External integration sync configuration panel; lib gap: no settings rich-section type for complex integration config.',
	},

	// --- POS transactions. ---
	PosTransactionListView: {
		kind: 'page',
		component: PosTransactionListView,
		_note: 'POS receipt list; custom so rows navigate to the cart editor / detail and the empty state offers "Nieuwe transactie".',
	},
	PosTransactionDetailView: {
		kind: 'page',
		component: PosTransactionDetailView,
		_note: 'POS receipt detail with context-sensitive lifecycle buttons (confirm/settle/refund/park/resume), per-rate tax breakdown and totals; lib detail page cannot express POS lifecycle actions.',
	},
	PosTransactionFormView: {
		kind: 'page',
		component: PosTransactionFormView,
		_note: 'Bespoke cart editor: inline line-item rows with product picker + real-time totals; lib has no cart/line-editor page type.',
	},

	// --- POS refunds / returns. ---
	PosRefundListView: {
		kind: 'page',
		component: PosRefundListView,
		_note: 'Refund list; custom so rows navigate to the refund detail and the empty state offers "Nieuwe retour".',
	},
	PosRefundDetailView: {
		kind: 'page',
		component: PosRefundDetailView,
		_note: 'Refund detail with manager-gated confirm/reject lifecycle buttons, returned-line table, server-computed totals and the original-transaction context; lib detail page cannot express POS refund lifecycle actions.',
	},
	PosRefundFormView: {
		kind: 'page',
		component: PosRefundFormView,
		_note: 'Bespoke return editor: select original lines with partial quantities, per-line reason + restock toggle and real-time refund totals; lib has no line-selection/refund page type.',
	},

	// --- POS cash drawer. ---
	CashShiftListView: {
		kind: 'page',
		component: CashShiftListView,
		_note: 'Cash-shift list; custom so rows navigate to the drawer-reconciliation detail and the empty state offers "Shift openen".',
	},
	CashShiftDetailView: {
		kind: 'page',
		component: CashShiftDetailView,
		_note: 'Cash-shift detail: float declaration, drops panel, blind-count entry and the server-authoritative variance panel with manager-gated approve/reject; lib detail page cannot express the cash-drawer lifecycle.',
	},

	// --- POS staff PIN + role permissions (pos-staff-pin-permissions). ---
	PosRoleListView: {
		kind: 'page',
		component: PosRoleListView,
		_note: 'POS role permission-matrix list (canVoid / maxDiscountPercent / canRefund / canNoSale).',
	},
	PosRoleFormView: {
		kind: 'page',
		component: PosRoleFormView,
		_note: 'POS role create/edit form; client-side validation on maxDiscountPercent in [0,100].',
	},
	PosStaffListView: {
		kind: 'page',
		component: PosStaffListView,
		_note: 'POS staff list (display name, linked NC user, role badge, active toggle); admin-only.',
	},
	PosStaffFormView: {
		kind: 'page',
		component: PosStaffFormView,
		_note: 'POS staff create/edit form with masked PIN field; on edit, blank PIN keeps the existing hash.',
	},

	// --- POS split-tender admin (pos-split-tender). ---
	PosTenderTypeListView: {
		kind: 'page',
		component: PosTenderTypeListView,
		_note: 'POS tender-type list (Contant / Betaalpas / Cadeaubon / ...) with inline create / edit / delete via PosTenderTypeFormDialog; admin-only configuration of available payment methods and their GL accounts.',
	},

	// --- POS end-of-day bookkeeping. ---
	ZReportListView: {
		kind: 'page',
		component: ZReportListView,
		_note: 'Daily Z-report list with status / date / terminal filters; rows navigate to the per-report detail with GL mapping, submission timeline and manager-gated retry (pos-end-of-day-bookkeeping-post).',
	},
	ZReportDetailView: {
		kind: 'page',
		component: ZReportDetailView,
		_note: 'Z-report detail: server-authoritative summary, tax breakdown, payment-method breakdown, GL ledger line items (read-only), submission timeline and the manager-gated retry-submission action (pos-end-of-day-bookkeeping-post).',
	},
	PosBookkeepingSettingsView: {
		kind: 'page',
		component: PosBookkeepingSettingsView,
		_note: 'Admin settings panel for the POS bookkeeping pipeline: daily Z-report time, Shillinq endpoint + bearer token (isSensitive), alert email and max retry attempts (pos-end-of-day-bookkeeping-post).',
	},
	BrpMonitorView: {
		kind: 'page',
		component: BrpMonitorView,
		_note: 'Admin BRP Monitor — lookups / cache-hit ratio / error rate / avg response time over the last 24h, plus mTLS client-certificate expiry countdown (bsn-validatie-en-brp-lookup REQ-BSN-010).',
	},

	// --- POS Kassakoppeling-compliant audit log (pos-kassakoppeling-audit). ---
	KassakoppelingAuditListView: {
		kind: 'page',
		component: KassakoppelingAuditListView,
		_note: 'Append-only Kassakoppeling audit log list: streams from the bespoke /api/kassakoppeling/audit endpoint (NOT the OR object store, which only stores entries), drives the date / register / operator / action filter bar and the admin-only Belastingdienst export modal (pos-kassakoppeling-audit REQ-AUDIT-003 / REQ-AUDIT-005).',
	},
	KassakoppelingAuditDetailView: {
		kind: 'page',
		component: KassakoppelingAuditDetailView,
		_note: 'Read-only Kassakoppeling audit entry detail: verification status badge ramp (green ok / red tampered / grey pending), summary + entry + crypto cards with truncated hex digests + copy buttons, an optional transaction-link card linking to pos-transaction-core and the manual server-side verify action (pos-kassakoppeling-audit REQ-AUDIT-002 / REQ-AUDIT-004 / REQ-AUDIT-006).',
	},

	// --- Product barcode lookup. ---
	ProductBarcodeSearchView: {
		kind: 'page',
		component: ProductBarcodeSearchView,
		_note: 'Scan-to-navigate barcode search; resolves via the server-authoritative scoped barcode-lookup API and routes to the matching product (highlighting a matched variant).',
	},

	// --- Expense → Shillinq AP (pipelinq-expense-to-shillinq-ap). ---
	ExpenseListView: {
		kind: 'page',
		component: ExpenseListView,
		_note: 'Expenses list with apSyncStatus badge column (REQ-AP-005).',
	},
	ExpenseDetailView: {
		kind: 'page',
		component: ExpenseDetailView,
		_note: 'Expense detail with embedded Shillinq AP card + retry button on failed dispatches (REQ-AP-006).',
	},

	// --- Billing categories (billable-categories-and-tags). ---
	BillingCategoryListView: {
		kind: 'page',
		component: BillingCategoryListView,
		_note: 'Billing-category management list (REQ-BCT-001) with color-swatch + DBA / active / default badges and client-side billable→non-billable→internal sort.',
	},
	BillingCategoryWidget: {
		kind: 'widget',
		component: BillingCategoryWidget,
		_note: 'Donut chart of hours per billing category for the Dashboard (REQ-BCT-004). Clicking a segment navigates to the time entry list filtered by that category.',
	},

	// --- Klantbeeld 360 — cross-module analytics dashboard. ---
	AnalyticsDashboard: {
		kind: 'page',
		component: AnalyticsDashboard,
		_note: 'Cross-module KPI dashboard (Open Pipeline Value / Open Requests / Contactmomenten / Active Leads) with a trailing-period filter; driven by a server-side aggregation endpoint so large installations are not forced to fetch full collections client-side.',
	},

	// --- Klantbeeld 360 — per-pipeline sales analytics. ---
	PipelineAnalyticsView: {
		kind: 'page',
		component: PipelineAnalyticsView,
		_note: 'Per-pipeline KPI cards (Total Pipeline Value / Win Rate / Avg Deal Size / Active Opportunities) and a horizontal stage-funnel CnChartWidget; client-side aggregation is appropriate (< 500 leads per pipeline) and gives instant updates on pipeline switch.',
	},

	// --- Klantbeeld 360 — Client 360 view. ---
	ClientDetail: {
		kind: 'page',
		component: ClientDetail,
		_note: 'Aggregates 5 cross-schema relation sections (leads / contactmomenten / requests / contacts / complaints) with per-section loading + per-section error state, summary statistics card and delete-with-link-warning dialog; declarative type:"detail" cannot express the parallel cross-schema fetches with section-isolation.',
	},

	// --- Klantbeeld 360 — Contact detail with parent-organisation card. ---
	ContactDetail: {
		kind: 'page',
		component: ContactDetail,
		_note: 'Parent Organisation card with quick-link CnFormDialog for setting contact.client; declarative type:"detail" has no way to drive a searchable client-select dialog tied to the contact save flow.',
	},

	// --- BI export + data-warehouse sink. ---
	ExportJobsView: {
		kind: 'page',
		component: ExportJobsView,
		_note: 'Export-job list with per-row Test-run + Enable/Disable actions calling the export controller; declarative index cannot trigger the test/enable endpoints.',
	},
	ExportJobFormView: {
		kind: 'page',
		component: ExportJobFormView,
		_note: 'Export-job create/edit form (schemas multi-select, destination, format/mode, watermark, cron, row filter, PII column allowlist) with an inline Test-run action.',
	},
	ExportDestinationsView: {
		kind: 'page',
		component: ExportDestinationsView,
		_note: 'Export-destination list with a per-row Test-connection action calling the export controller.',
	},
	ExportDestinationFormView: {
		kind: 'page',
		component: ExportDestinationFormView,
		_note: 'Export-destination create/edit form (type, OpenConnector source, path template, compression, encryption) with an inline Test-connection action.',
	},
	ExportRunsView: {
		kind: 'page',
		component: ExportRunsView,
		_note: 'Export-run history list with a per-row Retry action for failed/partial runs.',
	},
	ExportRunDetailView: {
		kind: 'page',
		component: ExportRunDetailView,
		_note: 'Export-run detail: file manifest, schema snapshots with detected drift, error log and a Retry action; fetched via the export run-detail endpoint.',
	},

	// --- CTI screen-pop and click-to-dial adapter (cti-screenpop-adapter). ---
	CtiSettingsView: {
		kind: 'page',
		component: CtiSettingsView,
		_note: 'CTI admin settings: platform, API base URL, auth method, OpenConnector credentials ref, screen-pop / click-to-dial toggles and connection test. Lib gap: no telephony-config page type.',
	},
	CtiEventLogView: {
		kind: 'page',
		component: CtiEventLogView,
		_note: 'CTI admin event-log inspector (last 30 days): platform + event-type filters, payload modal; lib gap: no audit/event-log page type that filters by platform.',
	},

	// --- POS pluggable payment provider adapter (pos-payment-provider-adapter). ---
	PaymentSettingsForm: {
		kind: 'page',
		component: PaymentSettingsForm,
		_note: 'Admin-only credential form for Mollie / CCV / Adyen / Stripe with encrypted-at-rest secrets via ICrypto and per-provider connection test. Lib gap: no payment-provider-settings page type. Renders ***SET*** for already-stored secrets so the form never leaks credentials.',
	},

	// --- Project / WBS hierarchy (project-task-hierarchy). ---
	ProjectList: {
		kind: 'page',
		component: ProjectList,
		_note: 'Project list view wrapping CnIndexPage with per-cell slot overrides for status pill, billable indicator, budget/logged progress and overdue-end-date treatment (REQ-PTH-006).',
	},
	ProjectDetail: {
		kind: 'page',
		component: ProjectDetail,
		_note: 'Project detail with parallel cross-schema relation fetch (phases / tasks / activities), budget KPI cards, embedded WBS tree (ProjectWbsTree.vue), inline CnFormDialogs for phase/task/activity create and CnObjectSidebar; declarative type:"detail" cannot orchestrate three nested schemas through one screen (REQ-PTH-001 / REQ-PTH-007).',
	},
	ProjectActivityList: {
		kind: 'page',
		component: ProjectActivityList,
		_note: 'Time-entry list for one project with date/user/task/billable filters and a totals row that applies the billable inheritance chain (REQ-PTH-004 / REQ-PTH-005 / REQ-PTH-008).',
	// --- StUF-ZKN/BG adapter (stuf-zkn-bg-adapter). ---
	StufEndpointsView: {
		kind: 'page',
		component: StufEndpointsView,
		_note: 'StUF endpoint configuration list with per-endpoint circuit-breaker health badge (REQ-STUF-011); lib gap: no admin page type that shows the running circuit-breaker state alongside the endpoint row.',
	},
	StufAuditLogView: {
		kind: 'page',
		component: StufAuditLogView,
		_note: 'StUF per-call audit log inspector (REQ-STUF-008): direction + bericht + status filters, inline envelope XML inspection, retries[] history and fout payload; CSV export. Lib gap: no envelope-style audit-log page type.',
	},

	// --- Marketing blasts (marketing-segmentation-and-blast slice 07). ---
	BlastListView: {
		kind: 'page',
		component: BlastListView,
		_note: 'Blasts list (marketing-segmentation-and-blast 07): CnIndexPage with bespoke columns (name, channel, status, scheduledFor, sentAt) and a "New blast" header action routing to the multi-step wizard.',
	},
	BlastFormView: {
		kind: 'page',
		component: BlastFormView,
		_note: 'Multi-step new-blast wizard (marketing-segmentation-and-blast 07): name → segment → template → channel → schedule → A/B split, with pre-send compliance preflight, missing-consent modal (skip / request / cancel) and email template validation. Declarative type:"form" cannot express the cross-endpoint preflight or the gated send flow.',
	},
	BlastMonitorView: {
		kind: 'page',
		component: BlastMonitorView,
		_note: 'Live blast monitor (marketing-segmentation-and-blast 07): progress bar + ETA, totals grid (queued/sent/delivered/bounced/opened/clicked/unsubscribed/complained), reverse-chronological event timeline (last 50), cancel action while sending; polls /api/blasts/:id every 2 seconds and stops on sent/failed/cancelled.',
	},
	BlastPerformanceDashboardView: {
		kind: 'page',
		component: BlastPerformanceDashboardView,
		_note: 'Post-send performance dashboard (marketing-segmentation-and-blast 08): three tabs — Overview (sortable blast table with sent/delivered/open-rate/click-rate/unsubscribed), A/B Testing (side-by-side variant comparison + chi-square p-value once each arm has >=500 delivered and 24h elapsed since send), Attribution (per-blast attributed deal count + summed EUR value from GET /api/blasts/:id/attribution).',
	},

	// --- Appointment booking — admin views (appointment-booking 11 of 12). ---
	ServiceListView: {
		kind: 'page',
		component: ServiceListView,
		_note: 'Service catalogue list with formatted duration / currency cells and a status badge; lib gap: declarative index page cannot express the duration / currency cell renderers.',
	},
	ServiceDetailView: {
		kind: 'page',
		component: ServiceDetailView,
		_note: 'Service detail + edit page with the multiStep sub-table editor, deposit / cancellation policy cards and a best-effort availabilityCache invalidation hook on save (REQ-APT-015).',
	},
	ResourceListView: {
		kind: 'page',
		component: ResourceListView,
		_note: 'Resource list (staff / room / equipment) with type + bookable + status badges.',
	},
	ResourceDetailView: {
		kind: 'page',
		component: ResourceDetailView,
		_note: 'Resource detail + edit page with the 7-weekday workingHours grid, vacation list, validation (open<close, startDate<=endDate) and per-resource cache invalidation on save (REQ-APT-002).',
	},
	BookingListView: {
		kind: 'page',
		component: BookingListView,
		_note: 'Booking list with formatted start-time and status badge; admins create bookings through the public portal on a customer\'s behalf, no inline create.',
	},
	BookingDetailView: {
		kind: 'page',
		component: BookingDetailView,
		_note: 'Booking detail with context-sensitive lifecycle buttons (Reschedule / Cancel / Mark Completed / Mark No-show / Send Reminder / Confirm Deposit) wired to the BookingAdminController endpoints, inline notes editor, audit-trail card and a chronological timeline (REQ-APT-015).',
	},
}

export default registry
