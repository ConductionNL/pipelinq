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

// --- Admin managers (lib gap: no pipeline-designer / settings rich-section type). ---
import PipelineManagerView from './views/settings/PipelineManager.vue'
import SyncSettingsView from './views/sync/SyncSettings.vue'

// --- POS transactions (lib gap: list needs custom row navigation to the cart
//     editor; detail needs lifecycle action buttons + tax breakdown; form is a
//     bespoke cart editor with real-time totals). ---
import PosTransactionListView from './views/pos/PosTransactionList.vue'
import PosTransactionDetailView from './views/pos/PosTransactionDetail.vue'
import PosTransactionFormView from './views/pos/PosTransactionForm.vue'
import PosRefundListView from './views/pos/PosRefundList.vue'
import PosRefundDetailView from './views/pos/PosRefundDetail.vue'
import PosRefundFormView from './views/pos/PosRefundForm.vue'

// --- POS staff + role admin (lib gap: list needs custom row-to-dialog editing
//     and the PIN/permission write paths route through dedicated server
//     endpoints that hash the PIN and strip the hash). ---
import PosStaffListView from './views/pos/StaffList.vue'
import PosRoleListView from './views/pos/RoleList.vue'

// --- Product barcode lookup (lib gap: index pages have no server-authoritative
//     scan-to-navigate barcode search; this view calls the scoped lookup API). ---
import ProductBarcodeSearchView from './views/products/ProductBarcodeSearch.vue'

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

	// --- Product barcode lookup. ---
	ProductBarcodeSearchView: {
		kind: 'page',
		component: ProductBarcodeSearchView,
		_note: 'Scan-to-navigate barcode search; resolves via the server-authoritative scoped barcode-lookup API and routes to the matching product (highlighting a matched variant).',
	},

	// --- POS staff + role admin. ---
	PosStaffListView: {
		kind: 'page',
		component: PosStaffListView,
		_note: 'POS staff list; rows open a form dialog that posts to the admin-gated /api/pos/staff endpoint (PIN hashed server-side, hash never returned).',
	},
	PosRoleListView: {
		kind: 'page',
		component: PosRoleListView,
		_note: 'POS role list with a permission-matrix form dialog; writes route through the admin-gated /api/pos/roles endpoint (discount bound + delete-while-assigned guard server-side).',
	},
}

export default registry
