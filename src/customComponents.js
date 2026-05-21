// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Conduction B.V.
//
// Custom-component registry for pipelinq's manifest-driven app shell.
//
// Every entry here is the "escape hatch" — pages that don't fit one
// of the manifest's built-in types/widgets. Keep this file focused.
// Adding entries requires explicit justification in the design doc;
// removing them (by migrating to a built-in type) is the right
// direction.
//
// Resolution order at runtime:
//   1. Built-in page types          (CnIndexPage, CnDetailPage, …)
//   2. Built-in widget types        (version-info, register-mapping, …)
//   3. customComponents (this file) ← consumer-injected components
//
// See:
//   - openspec/changes/pipelinq-manifest-v1/design.md
//   - hydra/openspec/architecture/adr-024-app-manifest.md

// --- Genuine exceptions (no abstract analogue). ---
import DashboardView from './views/Dashboard.vue'
import PipelineBoardView from './views/pipeline/PipelineBoard.vue'
import MyWorkView from './views/MyWork.vue'

// --- Queues — bespoke routing-rule editor (lib gap: routing-rules widget). ---
import QueueListView from './views/queues/QueueList.vue'
import QueueDetailView from './views/queues/QueueDetail.vue'

// --- Kennisbank wiki article detail (lib gap: no `wiki` page type). ---
import ArticleDetailView from './views/kennisbank/ArticleDetail.vue'

// --- Surveys analytics (lib gap: no chart-widget page type). ---
import SurveyAnalyticsView from './views/surveys/SurveyAnalytics.vue'

// --- Forms (lib gap: no `form-builder` page type for the visual builder;
//     the `Forms` list page is a declarative `type:"index"` on intakeForm,
//     and `Forms › Submissions` is a declarative `type:"index"` on
//     intakeSubmission with `config.filter: { form: "@route.id" }`). ---
import FormBuilderView from './views/forms/FormBuilder.vue'

// --- Automations (lib gap: no `automation-graph` page type for the visual
//     builder; the `Automations` list page is a declarative `type:"index"`,
//     and `Automations › History` is a declarative `type:"index"` on
//     automationLog with `config.filter: { automation: "@route.id" }`). ---
import AutomationBuilderView from './views/automations/AutomationBuilder.vue'

// --- Reporting dashboards (lib gap: chart widgets not yet registered). ---
import RapportageDashboardView from './views/rapportage/RapportageDashboard.vue'
import ChannelAnalyticsView from './views/rapportage/ChannelAnalytics.vue'
import AgentPerformanceView from './views/rapportage/AgentPerformance.vue'

// --- Admin managers (lib gap: type=settings rich sections need extra widgets). ---
import PipelineManagerView from './views/settings/PipelineManager.vue'
import SyncSettingsView from './views/sync/SyncSettings.vue'

// --- Features & Roadmap page — thin wrapper around the lib's
//     CnFeaturesAndRoadmapView (in-product roadmap surface powered by
//     OpenRegister's github-issue-proxy). See ConductionNL/hydra#251. ---

export default {
	// Genuine exceptions
	DashboardView,
	PipelineBoardView,
	MyWorkView,

	// Queues
	QueueListView,
	QueueDetailView,

	// Kennisbank
	ArticleDetailView,

	// Surveys
	SurveyAnalyticsView,

	// Forms (list + submissions are declarative type:index; visual builder stays custom)
	FormBuilderView,

	// Automations (list + history are declarative type:index; visual builder stays custom)
	AutomationBuilderView,

	// Reporting
	RapportageDashboardView,
	ChannelAnalyticsView,
	AgentPerformanceView,

	// Admin managers
	PipelineManagerView,
	SyncSettingsView,

	// Features & Roadmap page (lib's CnFeaturesAndRoadmapView)
}
