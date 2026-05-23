// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// V2 component registry for pipelinq.
//
// Every entry here corresponds to a manifest `type: "custom"` page. The
// registry maps the string key used in the manifest to a `{ kind, component }`
// entry so CnAppRoot can resolve the component at render time.
//
// Recognised kinds: page, modal, widget, form-field, cell-renderer
//
// Resolution order (v2 renderer):
//   1. Built-in page types   (CnIndexPage, CnDetailPage, …)
//   2. Built-in widget types (version-info, register-mapping, …)
//   3. registry (this file)  ← consumer-injected components
//   4. customComponents      ← v1 fallback, kept during transition
//
// See:
//   - openspec/changes/pipelinq-manifest-v1/design.md
//   - hydra/openspec/architecture/adr-036-manifest-v2.md

// --- Genuine exceptions: no abstract manifest analogue. ---
import DashboardView from './views/Dashboard.vue'
import MyWorkView from './views/MyWork.vue'

// --- Kanban board (lib gap: no kanban/board page type). ---
import PipelineBoardView from './views/pipeline/PipelineBoard.vue'

// --- Queues / routing rules (lib gap: no routing-rules widget). ---
import QueueListView from './views/queues/QueueList.vue'
import QueueDetailView from './views/queues/QueueDetail.vue'

// --- Kennisbank wiki article detail (lib gap: no wiki article detail type). ---
import ArticleDetailView from './views/kennisbank/ArticleDetail.vue'

// --- Surveys analytics (lib gap: no chart-widget page type). ---
import SurveyAnalyticsView from './views/surveys/SurveyAnalytics.vue'

// --- Forms visual builder (lib gap: no form-builder page type;
//     Forms list + FormSubmissions are declarative type:"index"). ---
import FormBuilderView from './views/forms/FormBuilder.vue'

// --- Automations visual builder (lib gap: no automation-graph page type;
//     Automations list + AutomationHistory are declarative type:"index"). ---
import AutomationBuilderView from './views/automations/AutomationBuilder.vue'

// --- Reporting dashboards (lib gap: no chart-widget page type). ---
import RapportageDashboardView from './views/rapportage/RapportageDashboard.vue'
import ChannelAnalyticsView from './views/rapportage/ChannelAnalytics.vue'
import AgentPerformanceView from './views/rapportage/AgentPerformance.vue'

// --- Admin managers (lib gap: no pipeline-designer / settings rich-section type). ---
import PipelineManagerView from './views/settings/PipelineManager.vue'
import SyncSettingsView from './views/sync/SyncSettings.vue'

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
	// --- Genuine exceptions: no abstract manifest analogue. ---
	DashboardView: {
		kind: 'page',
		component: DashboardView,
		_note: 'Bespoke multi-widget dashboard with gridstack layout; lib gap: no declarative dashboard page type with grid-aware widget placement.',
	},
	MyWorkView: {
		kind: 'page',
		component: MyWorkView,
		_note: 'Personalised work surface mixing tasks + leads + requests for the current user; no single-entity typed page captures multi-entity user dashboard.',
	},

	// --- Kanban board. ---
	PipelineBoardView: {
		kind: 'page',
		component: PipelineBoardView,
		_note: 'Kanban board with drag-and-drop lane management; lib gap: no kanban/board page type.',
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

	// --- Kennisbank wiki. ---
	ArticleDetailView: {
		kind: 'page',
		component: ArticleDetailView,
		_note: 'Rendered markdown article view with related-articles panel; lib gap: no wiki article detail type.',
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

	// --- Automations visual builder. ---
	AutomationBuilderView: {
		kind: 'page',
		component: AutomationBuilderView,
		_note: 'Visual automation graph editor (trigger + actions); lib gap: no automation-graph page type. Automations list + AutomationHistory use declarative type:"index".',
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
}

export default registry
