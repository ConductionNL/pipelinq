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
import ProspectsView from './views/prospects/ProspectsView.vue'

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

// --- Dashboard analytics widgets (openspec/changes/dashboard +
//     openspec/changes/decompose-unified-analytics). Navi AI
//     conversational analytics, the cross-module analytics KPI cards +
//     trend charts, and the funder report export panel. ---
import NaviAnalyticsWidget from './views/dashboard/widgets/NaviAnalyticsWidget.vue'
import LeadConversionKpiWidget from './views/dashboard/widgets/LeadConversionKpiWidget.vue'
import AvgResolutionKpiWidget from './views/dashboard/widgets/AvgResolutionKpiWidget.vue'
import ContactVolumeKpiWidget from './views/dashboard/widgets/ContactVolumeKpiWidget.vue'
import SatisfactionKpiWidget from './views/dashboard/widgets/SatisfactionKpiWidget.vue'
import LeadsOverTimeChartWidget from './views/dashboard/widgets/LeadsOverTimeChartWidget.vue'
import RequestsByCategoryChartWidget from './views/dashboard/widgets/RequestsByCategoryChartWidget.vue'
import ReportExportPanel from './views/dashboard/widgets/ReportExportPanel.vue'

// Commercial dashboard widgets (openspec/changes/commercial-dashboard).
// Six KPI cards from one cached GET /api/analytics/commercial per period,
// four charts from GET /api/analytics/trends (revenue / pipeline-by-stage /
// revenue-by-product-category / top-customers), and two deal tables built
// client-side from the cached lead dataset. All share the dashboard
// date-range + Refresh action via the analytics mixins.
import RevenueKpiWidget from './views/dashboard/widgets/RevenueKpiWidget.vue'
import WonValueKpiWidget from './views/dashboard/widgets/WonValueKpiWidget.vue'
import WinRateKpiWidget from './views/dashboard/widgets/WinRateKpiWidget.vue'
import AvgDealSizeKpiWidget from './views/dashboard/widgets/AvgDealSizeKpiWidget.vue'
import WeightedForecastKpiWidget from './views/dashboard/widgets/WeightedForecastKpiWidget.vue'
import OpenPipelineKpiWidget from './views/dashboard/widgets/OpenPipelineKpiWidget.vue'
import RevenueOverTimeChartWidget from './views/dashboard/widgets/RevenueOverTimeChartWidget.vue'
import PipelineByStageChartWidget from './views/dashboard/widgets/PipelineByStageChartWidget.vue'
import RevenueByCategoryChartWidget from './views/dashboard/widgets/RevenueByCategoryChartWidget.vue'
import TopCustomersChartWidget from './views/dashboard/widgets/TopCustomersChartWidget.vue'
import ClosingSoonWidget from './views/dashboard/widgets/ClosingSoonWidget.vue'
import RecentlyWonLostWidget from './views/dashboard/widgets/RecentlyWonLostWidget.vue'

// Bespoke kanban board with in-memory search (REQ-PIPE-022).
// See openspec/changes/2026-03-20-pipeline/design.md.
import PipelineBoardView from './views/pipeline/PipelineBoard.vue'

// --- Queues / routing rules (lib gap: no routing-rules widget). ---
import QueueListView from './views/queues/QueueList.vue'
import QueueDetailView from './views/queues/QueueDetail.vue'

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
import PosTransactionDetailView from './views/pos/PosTransactionDetail.vue'
import PosTransactionFormView from './views/pos/PosTransactionForm.vue'
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

// --- POS end-of-day Z-report (lib gap: index/detail pages cannot express the
//     server-authoritative Z-report aggregation + shillinq journal-raise status
//     + manager-gated re-raise). The GL-mapping admin surface is delegated to
//     shillinq (pipelinq-bookkeeping-to-shillinq) and removed here. ---
import ZReportDetailView from './views/pos/ZReportDetail.vue'
import PosCustomerSettingsView from './views/admin/PosCustomerSettings.vue'

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
import CtiPageView from './views/settings/CtiPage.vue'

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
import BillingCategoryWidget from './components/dashboard/BillingCategoryWidget.vue'

// --- Klantbeeld 360 (lib gap: no cross-module KPI dashboard with a
//     trailing-period filter wired to a domain-specific aggregation
//     endpoint, and no pipeline KPI / stage-funnel page driving four
//     bespoke ratio KPIs off lead-collection client-side aggregation;
//     ClientDetail + ContactDetail aggregate 5 cross-schema sections
//     with per-section loading and a contact->client linking dialog,
//     beyond what a declarative type:"detail" page can express). ---
import SlaAttainmentDashboard from './views/sla/SlaAttainmentDashboard.vue'
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
import ProjectDetail from './views/projects/ProjectDetail.vue'
import ProjectActivityList from './views/projects/ProjectActivityList.vue'

// --- Marketing segmentation + blast (marketing-segmentation-and-blast 07):
//     three-route Vue surface — list, multi-step create wizard, live monitor.
//     The wizard embeds the missing-consent modal (own file under modals/);
//     the monitor polls /api/blasts/:id every 2s and stops on terminal status.
//     SegmentBuilder + SegmentRuleNode live under components/ for reuse by
//     forthcoming SegmentEditor / dashboard surfaces (slice 08). ---
import BlastFormView from './views/blasts/BlastForm.vue'
import BlastMonitorView from './views/blasts/BlastMonitor.vue'
import BlastPerformanceDashboardView from './views/blasts/PerformanceDashboard.vue'

// --- Appointment booking — admin surface (appointment-booking 11 of 12).
//     Service / Resource / Booking list + detail views; resolved by the v2
//     renderer from the manifest.d fragment at render time. ---
import ServiceDetailView from './views/bookings/ServiceDetail.vue'
import ResourceDetailView from './views/bookings/ResourceDetail.vue'
import BookingDetailView from './views/bookings/BookingDetail.vue'

// --- KCC Werkplek (pipelinq-werkplek-declarative): unified KCC agent workspace
//     rendered as a declarative type:"dashboard" page. Requests, Tasks, the
//     queue filter, the active-interaction form, the summary-driven knowledge
//     base and the client overview are all widgets on the standard dashboard
//     grid (header + actions + single scroll region). Only two small host
//     widgets remain: the queue filter (pipelinq-specific /state endpoint) and
//     the header agent-availability toggle. ---
import WerkplekQueueFilter from './views/werkplek/widgets/WerkplekQueueFilter.vue'
import WerkplekHeaderActions from './views/werkplek/widgets/WerkplekHeaderActions.vue'

// --- xWiki integration (xwiki-integration): dashboard widget wrapper +
//     reusable widget / sidebar / viewer / list components. ---
import XWikiDashboardWidget from './views/dashboard/widgets/XWikiDashboardWidget.vue'
import XWikiWidgetComponent from './components/xwiki/XWikiWidget.vue'
import XWikiSidebarTabComponent from './components/xwiki/XWikiSidebarTab.vue'
import XWikiArticleViewer from './components/xwiki/XWikiArticleViewer.vue'
// --- AVG (GDPR data-subject request) workflow (lib gap: list needs deadline
//     colour-coding + masked names; detail needs the tabbed evidence/redaction/
//     bundle/denial lifecycle; intake needs article classification). ---
import AvgDashboardView from './views/avg/AvgDashboard.vue'
import AvgRequestDetailView from './views/avg/AvgRequestDetail.vue'
import AvgIntakeView from './views/avg/AvgIntakeView.vue'
// --- Master Data Management (MDM) bespoke steward views. ---
import MdmMasterEntityListView from './views/mdm/MdmMasterEntityListView.vue'
import MdmMasterEntityDetailView from './views/mdm/MdmMasterEntityDetailView.vue'
import MdmDataQualityDashboard from './views/mdm/MdmDataQualityDashboard.vue'
import MdmDuplicateCandidatesDashboard from './views/mdm/MdmDuplicateCandidatesDashboard.vue'
import MdmSyncQueueAdmin from './views/mdm/MdmSyncQueueAdmin.vue'

// --- Contact-aware create overrides (kind:"create-override"). The
//     client/contact schemas mark `contactsUid` REQUIRED, so a plain
//     objectStore.saveObject() 400s. These handlers post the create-form to
//     POST /api/contacts-sync/create (provisions the NC addressbook contact +
//     fills the FK) and return the created object — the same path the bespoke
//     ClientCreateDialog uses. CnPageRenderer resolves a manifest
//     `config.createOverride` string to one of these and forwards it to
//     CnIndexPage's createOverride prop, so the GENERIC Add button on the
//     declarative Clients/Contacts index pages is contact-aware too. ---
import { createWithContact } from './services/contactSyncApi.js'

// --- Features & Roadmap page (lib's CnFeaturesAndRoadmapView wrapper). ---

/*
 * Grid metadata required for every kind:"widget" entry by the ADR-036
 * registry validator in CnAppRoot. pipelinq's dashboard positions widgets
 * via the manifest `config.layout` (GridStack), so these sizes are not
 * consumed at runtime — they exist to satisfy the validator. Sizes mirror
 * the manifest layout for coherence. `allowedSlots` uses the v2 slot
 * literals (body, sidebar, header-actions, footer, modal).
 */
const KPI_WIDGET_META = {
	defaultSize: { w: 3, h: 2 },
	minSize: { w: 2, h: 2 },
	maxSize: { w: 6, h: 4 },
	allowedSlots: ['body'],
	propsSchema: null,
}
const PANEL_WIDGET_META = {
	defaultSize: { w: 6, h: 4 },
	minSize: { w: 3, h: 2 },
	maxSize: { w: 12, h: 6 },
	allowedSlots: ['body'],
	propsSchema: null,
}
const HEADER_ACTIONS_META = {
	defaultSize: { w: 12, h: 1 },
	minSize: { w: 1, h: 1 },
	maxSize: { w: 12, h: 1 },
	allowedSlots: ['header-actions'],
	propsSchema: null,
}
const SIDEBAR_TAB_META = {
	defaultSize: { w: 1, h: 2 },
	minSize: { w: 1, h: 1 },
	maxSize: { w: 1, h: 6 },
	allowedSlots: ['sidebar'],
	propsSchema: null,
}

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
	ProspectsView: {
		kind: 'page',
		component: ProspectsView,
		_note: 'Full-page expansion of ProspectWidget (refactor-pipelinq-ia-alignment): scored-prospect list with sortable columns + convert-to-lead action over the prospect Pinia store; lib has no declarative type for scored external-source enrichment.',
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
		...HEADER_ACTIONS_META,
		_note: 'Dashboard header buttons (New Lead / Request / Client + Refresh) wired as the Dashboard page actionsComponent.',
	},
	OpenLeadsKpiWidget: {
		kind: 'widget',
		component: OpenLeadsKpiWidget,
		...KPI_WIDGET_META,
		_note: 'KPI card for open leads (leads minus those in pipeline stages flagged isClosed). Renders <CnStatsBlock>.',
	},
	OpenRequestsKpiWidget: {
		kind: 'widget',
		component: OpenRequestsKpiWidget,
		...KPI_WIDGET_META,
		_note: 'KPI card for open requests (status new or in_progress). Renders <CnStatsBlock>.',
	},
	PipelineValueKpiWidget: {
		kind: 'widget',
		component: PipelineValueKpiWidget,
		...KPI_WIDGET_META,
		_note: 'KPI card for total open-lead value in EUR. Renders <CnStatsBlock>.',
	},
	OverdueKpiWidget: {
		kind: 'widget',
		component: OverdueKpiWidget,
		...KPI_WIDGET_META,
		_note: 'KPI card for overdue leads + stale requests. Renders <CnStatsBlock>.',
	},
	RequestsByStatusWidget: {
		kind: 'widget',
		component: RequestsByStatusWidget,
		...PANEL_WIDGET_META,
		_note: 'Horizontal bar chart of requests grouped by status. Standalone widget — fetches its own data.',
	},
	ComplaintsWidget: {
		kind: 'widget',
		component: ComplaintsWidget,
		...PANEL_WIDGET_META,
		_note: 'Open / overdue / status breakdown of complaints. Wraps the existing ComplaintsOverviewWidget with a self-contained fetch.',
	},
	MyWorkWidget: {
		kind: 'widget',
		component: MyWorkWidget,
		...PANEL_WIDGET_META,
		_note: 'Top-5 list of leads + requests assigned to the current user, sorted by overdue → priority → due date.',
	},
	ClientOverviewWidget: {
		kind: 'widget',
		component: ClientOverviewWidget,
		...PANEL_WIDGET_META,
		_note: 'Top-5 recent clients with a view-all link to the Clients index page.',
	},
	NaviAnalyticsWidget: {
		kind: 'widget',
		component: NaviAnalyticsWidget,
		...PANEL_WIDGET_META,
		_note: 'Conversational analytics chat panel powered by NaviService — natural-language queries return CnChartWidget / CnDataTable / plain text inline, with up to 3 suggested follow-up chips. openspec/changes/dashboard REQ-DASH-001 / REQ-DASH-003.',
	},
	LeadConversionKpiWidget: {
		kind: 'widget',
		component: LeadConversionKpiWidget,
		...KPI_WIDGET_META,
		_note: 'KPI card: % of leads won in the dashboard date range. Shares one cached GET /api/analytics/overview per period. openspec/changes/decompose-unified-analytics REQ-DASH-010.',
	},
	AvgResolutionKpiWidget: {
		kind: 'widget',
		component: AvgResolutionKpiWidget,
		...KPI_WIDGET_META,
		_note: 'KPI card: mean request resolution time (hours) in the dashboard date range. openspec/changes/decompose-unified-analytics REQ-DASH-010.',
	},
	ContactVolumeKpiWidget: {
		kind: 'widget',
		component: ContactVolumeKpiWidget,
		...KPI_WIDGET_META,
		_note: 'KPI card: contactmoment count in the dashboard date range. openspec/changes/decompose-unified-analytics REQ-DASH-010.',
	},
	SatisfactionKpiWidget: {
		kind: 'widget',
		component: SatisfactionKpiWidget,
		...KPI_WIDGET_META,
		_note: 'KPI card: mean survey score (1–5) in the dashboard date range. openspec/changes/decompose-unified-analytics REQ-DASH-010.',
	},
	LeadsOverTimeChartWidget: {
		kind: 'widget',
		component: LeadsOverTimeChartWidget,
		...PANEL_WIDGET_META,
		_note: 'Line chart: leads over time from GET /api/analytics/trends?metric=leads. Title comes from the widget chrome. openspec/changes/decompose-unified-analytics REQ-DASH-010.',
	},
	RequestsByCategoryChartWidget: {
		kind: 'widget',
		component: RequestsByCategoryChartWidget,
		...PANEL_WIDGET_META,
		_note: 'Bar chart: requests by category from GET /api/analytics/trends?metric=requests-by-category. Title comes from the widget chrome. openspec/changes/decompose-unified-analytics REQ-DASH-010.',
	},
	ReportExportPanel: {
		kind: 'widget',
		component: ReportExportPanel,
		...PANEL_WIDGET_META,
		_note: 'Collapsible funder-reporting export panel; delegates the format picker + download to CnMassExportDialog / ExportService — no custom export controller. openspec/changes/dashboard REQ-DASH-020 / REQ-DASH-021.',
	},

	// --- Commercial dashboard widgets (openspec/changes/commercial-dashboard). ---
	RevenueKpiWidget: {
		kind: 'widget',
		component: RevenueKpiWidget,
		...KPI_WIDGET_META,
		_note: 'KPI card: settled POS turnover + won-deal value in the dashboard date range. Shares one cached GET /api/analytics/commercial per period.',
	},
	WonValueKpiWidget: {
		kind: 'widget',
		component: WonValueKpiWidget,
		...KPI_WIDGET_META,
		_note: 'KPI card: value of deals won in the dashboard date range.',
	},
	WinRateKpiWidget: {
		kind: 'widget',
		component: WinRateKpiWidget,
		...KPI_WIDGET_META,
		_note: 'KPI card: won / (won + lost) deals closed in the dashboard date range.',
	},
	AvgDealSizeKpiWidget: {
		kind: 'widget',
		component: AvgDealSizeKpiWidget,
		...KPI_WIDGET_META,
		_note: 'KPI card: mean value of won deals in the dashboard date range.',
	},
	WeightedForecastKpiWidget: {
		kind: 'widget',
		component: WeightedForecastKpiWidget,
		...KPI_WIDGET_META,
		_note: 'KPI card: open pipeline value weighted by win probability (forward-looking).',
	},
	OpenPipelineKpiWidget: {
		kind: 'widget',
		component: OpenPipelineKpiWidget,
		...KPI_WIDGET_META,
		_note: 'KPI card: total value of open leads (forward-looking).',
	},
	RevenueOverTimeChartWidget: {
		kind: 'widget',
		component: RevenueOverTimeChartWidget,
		...PANEL_WIDGET_META,
		_note: 'Line chart: revenue over time from GET /api/analytics/trends?metric=revenue. Title comes from the widget chrome.',
	},
	PipelineByStageChartWidget: {
		kind: 'widget',
		component: PipelineByStageChartWidget,
		...PANEL_WIDGET_META,
		_note: 'Horizontal bar funnel: open-lead value per stage from GET /api/analytics/trends?metric=pipeline-by-stage.',
	},
	RevenueByCategoryChartWidget: {
		kind: 'widget',
		component: RevenueByCategoryChartWidget,
		...PANEL_WIDGET_META,
		_note: 'Donut: POS revenue by product category from GET /api/analytics/trends?metric=revenue-by-product-category.',
	},
	TopCustomersChartWidget: {
		kind: 'widget',
		component: TopCustomersChartWidget,
		...PANEL_WIDGET_META,
		_note: 'Horizontal bar: top customers by revenue from GET /api/analytics/trends?metric=top-customers.',
	},
	ClosingSoonWidget: {
		kind: 'widget',
		component: ClosingSoonWidget,
		...PANEL_WIDGET_META,
		_note: 'Table: open deals ordered by expected close date, built client-side from the cached lead dataset.',
	},
	RecentlyWonLostWidget: {
		kind: 'widget',
		component: RecentlyWonLostWidget,
		...PANEL_WIDGET_META,
		_note: 'Table: recently won/lost deals, built client-side from the cached lead dataset.',
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

	// --- Surveys + Forms migrated to the OpenRegister forms leaf (NC Forms). ---
	// See openspec/changes/migrate-forms-to-forms-leaf.

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
		kind: 'widget',
		component: LeadForecastTab,
		...SIDEBAR_TAB_META,
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
		...PANEL_WIDGET_META,
		_note: 'Pipeline value per stage (count, total, weighted) — bar chart with pipeline filter (REQ-LM-006).',
	},
	SourcePerformanceWidget: {
		kind: 'widget',
		component: SourcePerformanceWidget,
		...PANEL_WIDGET_META,
		_note: 'Source conversion table (total, won, rate, avg) sortable per column (REQ-LM-007).',
	},
	LeadAgingWidget: {
		kind: 'widget',
		component: LeadAgingWidget,
		...PANEL_WIDGET_META,
		_note: 'Aging-bucket donut chart (≤7d / 8-14d / 15-30d / >30d) (REQ-LM-006).',
	},
	WinLossWidget: {
		kind: 'widget',
		component: WinLossWidget,
		...PANEL_WIDGET_META,
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

	// --- POS transactions. The PosTransactions list is now a declarative
	//     type:"index" page (pipelinq-declarative-pages-round1); only the
	//     bespoke cart-editor detail/form views stay registered. ---
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

	// --- POS refunds / returns. The PosRefunds list is now a declarative
	//     type:"index" page (pipelinq-declarative-pages-round1). ---
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

	// --- POS end-of-day bookkeeping. The Z-report list is now a declarative
	//     type:"index" page (pipelinq-views-to-declarative-r1); only the
	//     bespoke aggregation detail view stays registered. ---
	ZReportDetailView: {
		kind: 'page',
		component: ZReportDetailView,
		_note: 'Z-report detail: server-authoritative summary, tax breakdown, payment-method breakdown, shillinq bookkeeping-status projection and the manager-gated re-raise action. The GL journal itself is owned by shillinq (pipelinq-bookkeeping-to-shillinq).',
	},
	PosCustomerSettingsView: {
		kind: 'page',
		component: PosCustomerSettingsView,
		_note: 'Admin settings panel for the POS customer-link lookup: search fields, purchase-history depth, marketing-consent sync toggle and the on-account-requires-customer invariant (pos-customer-link, REQ-PCL-006).',
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
	BillingCategoryWidget: {
		kind: 'widget',
		component: BillingCategoryWidget,
		...PANEL_WIDGET_META,
		_note: 'Donut chart of hours per billing category for the Dashboard (REQ-BCT-004). Clicking a segment navigates to the time entry list filtered by that category.',
	},

	// --- SLA engine — attainment dashboard (sla-engine-and-escalation Feature 12). ---
	SlaAttainmentDashboard: {
		kind: 'page',
		component: SlaAttainmentDashboard,
		_note: 'SLA attainment dashboard: overall attainment KPI + per-target and per-policy/tier/team breakdown table. Bucketed by day/week/month/quarter and fetched from GET /api/sla/attainment.',
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
		_note: 'CTI admin event-log inspector (last 30 days): platform + event-type filters, payload modal; lib gap: no audit/event-log page type that filters by platform. Kept registered so the legacy /settings/cti/event-log deep link stays reachable; the navigation now uses the merged CtiPageView.',
	},
	CtiPageView: {
		kind: 'page',
		component: CtiPageView,
		_note: 'Merged CTI (telephony) settings page (pipelinq-cti-and-catalog-ia): composes the CtiSettings integration config and the CtiEventLog webhook log into one settings-section page so the former two Administration menu entries become one entry under Settings.',
	},

	// --- POS pluggable payment provider adapter (pos-payment-provider-adapter). ---
	PaymentSettingsForm: {
		kind: 'page',
		component: PaymentSettingsForm,
		_note: 'Admin-only credential form for Mollie / CCV / Adyen / Stripe with encrypted-at-rest secrets via ICrypto and per-provider connection test. Lib gap: no payment-provider-settings page type. Renders ***SET*** for already-stored secrets so the form never leaks credentials.',
	},

	// --- Project / WBS hierarchy (project-task-hierarchy). ---
	ProjectDetail: {
		kind: 'page',
		component: ProjectDetail,
		_note: 'Project detail with parallel cross-schema relation fetch (phases / tasks / activities), budget KPI cards, embedded WBS tree (ProjectWbsTree.vue), inline CnFormDialogs for phase/task/activity create and CnObjectSidebar; declarative type:"detail" cannot orchestrate three nested schemas through one screen (REQ-PTH-001 / REQ-PTH-007).',
	},
	ProjectActivityList: {
		kind: 'page',
		component: ProjectActivityList,
		_note: 'Time-entry list for one project with date/user/task/billable filters and a totals row that applies the billable inheritance chain (REQ-PTH-004 / REQ-PTH-005 / REQ-PTH-008).',
	},

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

	// --- Marketing blasts (marketing-segmentation-and-blast slice 07). The
	//     Blasts list is now a declarative type:"index" page
	//     (pipelinq-declarative-pages-round1). ---
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
	ServiceDetailView: {
		kind: 'page',
		component: ServiceDetailView,
		_note: 'Service detail + edit page with the multiStep sub-table editor, deposit / cancellation policy cards and a best-effort availabilityCache invalidation hook on save (REQ-APT-015).',
	},
	ResourceDetailView: {
		kind: 'page',
		component: ResourceDetailView,
		_note: 'Resource detail + edit page with the 7-weekday workingHours grid, vacation list, validation (open<close, startDate<=endDate) and per-resource cache invalidation on save (REQ-APT-002).',
	},
	// --- The booking list is now a declarative type:"index" page
	//     (pipelinq-views-to-declarative-r1); only the bespoke lifecycle
	//     detail view stays registered. ---
	BookingDetailView: {
		kind: 'page',
		component: BookingDetailView,
		_note: 'Booking detail with context-sensitive lifecycle buttons (Reschedule / Cancel / Mark Completed / Mark No-show / Send Reminder / Confirm Deposit) wired to the BookingAdminController endpoints, inline notes editor, audit-trail card and a chronological timeline (REQ-APT-015).',
	},

	// --- KCC Werkplek — declarative agent workspace (pipelinq-werkplek-declarative).
	//     The page is a type:"dashboard"; these two host widgets cover the
	//     pieces that aren't pure OpenRegister data: the queue filter (reads the
	//     aggregated /api/kcc-werkplek/state counts and writes selectedQueue into
	//     the page workspace context) and the header agent-availability toggle. ---
	WerkplekQueueFilter: {
		kind: 'widget',
		component: WerkplekQueueFilter,
		...PANEL_WIDGET_META,
		_note: 'Queue filter widget: lists queues + open-request counts from /api/kcc-werkplek/state and writes selectedQueue into the workspace context so the Requests/Tasks object-list widgets filter on @workspace.selectedQueue.',
	},
	WerkplekHeaderActions: {
		kind: 'widget',
		component: WerkplekHeaderActions,
		...HEADER_ACTIONS_META,
		_note: 'Workspace header actionsComponent: agent availability toggle, hydrated from /api/kcc-werkplek/state.',
	},

	// --- xWiki integration (xwiki-integration). ---
	XWikiDashboardWidget: {
		kind: 'widget',
		component: XWikiDashboardWidget,
		...PANEL_WIDGET_META,
		_note: 'Dashboard wrapper for the reusable XWikiWidget — pre-binds admin-configured xwiki_default_space + showSearch=true. Rendered as widget-xwiki on the Dashboard page.',
	},
	XWikiWidget: {
		kind: 'widget',
		component: XWikiWidgetComponent,
		...PANEL_WIDGET_META,
		_note: 'Reusable compact xWiki article-list card. Consumed by the dashboard widget wrapper, detail-page widgets, and ad-hoc embeds. Filters by space / tags / query.',
	},
	XWikiSidebarTab: {
		kind: 'widget',
		component: XWikiSidebarTabComponent,
		...SIDEBAR_TAB_META,
		_note: 'xWiki detail-sidebar panel with search / space-browser / article-viewer modes. Mounted on client / lead / request detail pages.',
	},
	XWikiArticleViewer: {
		kind: 'widget',
		component: XWikiArticleViewer,
		...PANEL_WIDGET_META,
		_note: 'Inline xWiki HTML viewer used by the sidebar tab; consumes the xwiki Pinia store directly.',
	},
	// --- AVG (GDPR data-subject request) workflow. ---
	AvgDashboardView: {
		kind: 'page',
		component: AvgDashboardView,
		_note: 'AVG request dashboard; custom so rows carry deadline colour-coding, masked subject names and a DPIA badge, and the empty state offers "New AVG request".',
	},
	AvgRequestDetailView: {
		kind: 'page',
		component: AvgRequestDetailView,
		_note: 'AVG request detail with the tabbed lifecycle (intake/evidence/redaction/bundle/denial), a live deadline counter and the 60-day extension action; lib detail page cannot express the GDPR request lifecycle.',
	},
	AvgIntakeView: {
		kind: 'page',
		component: AvgIntakeView,
		_note: 'AVG intake form with article classification + BSN elfproef validation; lib has no classification/intake page type.',
	},
	// --- Master Data Management (MDM) steward surfaces. The list/detail,
	//     duplicate-candidates and data-quality dashboards aggregate across
	//     master-entity + source-record + sync-queue reads that the declarative
	//     typed pages cannot express, so they ship as bespoke views. ---
	MdmMasterEntityListView: {
		kind: 'page',
		component: MdmMasterEntityListView,
		_note: 'Master entity list with entityType + low-quality filters and a data-quality badge per row.',
	},
	MdmMasterEntityDetailView: {
		kind: 'page',
		component: MdmMasterEntityDetailView,
		_note: 'Golden record + source-record lineage + per-attribute provenance, with the conflict-resolution modal.',
	},
	MdmDataQualityDashboard: {
		kind: 'page',
		component: MdmDataQualityDashboard,
		_note: 'Aggregate quality buckets, worst-entity table and sync-queue health with dead-letter retry.',
	},
	MdmDuplicateCandidatesDashboard: {
		kind: 'page',
		component: MdmDuplicateCandidatesDashboard,
		_note: 'Deterministic + probabilistic duplicate candidates with the merge wizard modal.',
	},
	MdmSyncQueueAdmin: {
		kind: 'page',
		component: MdmSyncQueueAdmin,
		_note: 'Outbound sync queue with status filter and manual retry of failed / dead-letter items.',
	},

	// Contact-aware create for the generic Add button on the Clients index page.
	createClientContactAware: {
		kind: 'create-override',
		handler: (formData) => createWithContact('client', formData),
		_note: 'Routes a generic client create through POST /api/contacts-sync/create so the required contactsUid (FK to a NC addressbook contact) is provisioned + filled instead of 400ing on a straight OpenRegister save.',
	},

	// Contact-aware create for the generic Add button on the Contacts index page.
	createContactContactAware: {
		kind: 'create-override',
		handler: (formData) => createWithContact('contact', formData),
		_note: 'Same contact-FIRST path for the contact schema (also marks contactsUid REQUIRED).',
	},
}

export default registry
