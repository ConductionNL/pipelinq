/**
 * Pipelinq v2 component registry (ADR-036).
 *
 * Kind-tagged map passed as the `registry` prop to CnAppRoot. CnPageRenderer
 * resolves each manifest page's `component` string against entries whose
 * `kind === "page"` (with precedence over the deprecated `customComponents`
 * prop, which Pipelinq no longer ships after this migration).
 *
 * Every full-page bespoke route is wrapped as `{ kind: 'page', component }`
 * via the `page()` helper. Dashboard widget components that are resolved
 * through a manifest `slots` map or `actionsComponent` field are wrapped
 * as `{ kind: 'widget', component }` via the `widget()` helper. Only
 * components that genuinely cannot be expressed as a declarative manifest
 * type belong here — the lib's built-in `index`/`detail`/`dashboard` types
 * cover the majority of pipelinq pages.
 *
 * @see hydra/openspec/architecture/adr-036-manifest-v2.md
 * @see openspec/changes/pipelinq-manifest-v1/design.md
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

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

// Bespoke kanban board removed — board mechanics now provided by the
// OpenRegister deck leaf (integration-deck). See openspec/changes/migrate-pipeline-to-deck-leaf/.

// --- Queues / routing rules (lib gap: no routing-rules widget). ---
import QueueListView from './views/queues/QueueList.vue'
import QueueDetailView from './views/queues/QueueDetail.vue'

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

/**
 * Wrap a Vue component into the v2 registry shape required by CnAppRoot's
 * `registry` prop (`kind: "page"` is the discriminator CnPageRenderer keys
 * page dispatch off).
 *
 * @param {object} component Vue component options.
 * @return {object} A `{ kind: "page", component }` registry entry.
 */
function page(component) {
	return { kind: 'page', component }
}

/**
 * Wrap a Vue component into the v2 registry shape for dashboard widgets
 * and header/actions overrides (`kind: "widget"`).
 *
 * @param {object} component Vue component options.
 * @return {object} A `{ kind: "widget", component }` registry entry.
 */
function widget(component) {
	return { kind: 'widget', component }
}

export default {
	// --- MyWork — multi-entity user dashboard. ---
	MyWorkView: page(MyWorkView),

	// --- Dashboard widget components (resolved via Dashboard page's `slots`
	//     map and `actionsComponent` field). ---
	DashboardHeaderActions: widget(DashboardHeaderActions),
	OpenLeadsKpiWidget: widget(OpenLeadsKpiWidget),
	OpenRequestsKpiWidget: widget(OpenRequestsKpiWidget),
	PipelineValueKpiWidget: widget(PipelineValueKpiWidget),
	OverdueKpiWidget: widget(OverdueKpiWidget),
	RequestsByStatusWidget: widget(RequestsByStatusWidget),
	ComplaintsWidget: widget(ComplaintsWidget),
	MyWorkWidget: widget(MyWorkWidget),
	ClientOverviewWidget: widget(ClientOverviewWidget),

	// --- Queues / routing rules. ---
	QueueListView: page(QueueListView),
	QueueDetailView: page(QueueDetailView),

	// --- Surveys. ---
	SurveyAnalyticsView: page(SurveyAnalyticsView),

	// --- Forms visual builder. ---
	FormBuilderView: page(FormBuilderView),

	// --- Automations visual builder. ---
	AutomationBuilderView: page(AutomationBuilderView),

	// --- Reporting dashboards. ---
	RapportageDashboardView: page(RapportageDashboardView),
	ChannelAnalyticsView: page(ChannelAnalyticsView),
	AgentPerformanceView: page(AgentPerformanceView),

	// --- Admin managers. ---
	PipelineManagerView: page(PipelineManagerView),
	SyncSettingsView: page(SyncSettingsView),
}
