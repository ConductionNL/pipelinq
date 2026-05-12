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
import PublicSurveyFormView from './views/surveys/PublicSurveyForm.vue'

// --- Bespoke create wizards (lib gap: multi-step actions). ---
import ContactmomentForm from './views/contactmomenten/ContactmomentForm.vue'
import TaskForm from './views/tasks/TaskForm.vue'

// --- Queues — bespoke routing-rule editor (lib gap: routing-rules widget). ---
import QueueListView from './views/queues/QueueList.vue'
import QueueDetailView from './views/queues/QueueDetail.vue'

// --- Kennisbank wiki (lib gap: no `wiki` page type). ---
import KennisbankHomeView from './views/kennisbank/KennisbankHome.vue'
import ArticleDetailView from './views/kennisbank/ArticleDetail.vue'
import ArticleEditorView from './views/kennisbank/ArticleEditor.vue'
import CategoryManagerView from './views/kennisbank/CategoryManager.vue'

// --- Surveys builder/analytics (lib gap: no `form-builder` page type). ---
import SurveyFormView from './views/surveys/SurveyForm.vue'
import SurveyAnalyticsView from './views/surveys/SurveyAnalytics.vue'

// --- Forms (lib gap: no `form-builder` page type). ---
import FormManagerView from './views/forms/FormManager.vue'
import FormBuilderView from './views/forms/FormBuilder.vue'
import FormSubmissionsView from './views/forms/FormSubmissions.vue'

// --- Automations (lib gap: no `automation-graph` page type). ---
import AutomationListView from './views/automations/AutomationList.vue'
import AutomationBuilderView from './views/automations/AutomationBuilder.vue'
import AutomationHistoryView from './views/automations/AutomationHistory.vue'

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
import FeaturesRoadmapView from './views/FeaturesRoadmap.vue'

export default {
	// Genuine exceptions
	DashboardView,
	PipelineBoardView,
	MyWorkView,
	PublicSurveyFormView,

	// Bespoke create wizards
	ContactmomentForm,
	TaskForm,

	// Queues
	QueueListView,
	QueueDetailView,

	// Kennisbank
	KennisbankHomeView,
	ArticleDetailView,
	ArticleEditorView,
	CategoryManagerView,

	// Surveys
	SurveyFormView,
	SurveyAnalyticsView,

	// Forms
	FormManagerView,
	FormBuilderView,
	FormSubmissionsView,

	// Automations
	AutomationListView,
	AutomationBuilderView,
	AutomationHistoryView,

	// Reporting
	RapportageDashboardView,
	ChannelAnalyticsView,
	AgentPerformanceView,

	// Admin managers
	PipelineManagerView,
	SyncSettingsView,

	// Features & Roadmap page (lib's CnFeaturesAndRoadmapView)
	FeaturesRoadmap: FeaturesRoadmapView,
}
