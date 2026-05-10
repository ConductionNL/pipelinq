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

// --- Submit-handler escape hatch (manifest-form-page-type: PublicSurvey). ---
// `type: "form"` in the manifest dispatches submits via either an HTTP
// `submitEndpoint` or a registered handler. PublicSurvey needs the
// handler path because the submit URL embeds the route's `:token` param
// AND the body shape (`{ answers: [...], entityType, entityId }`) is
// pipelinq-specific. The handler keeps that wiring out of the lib while
// the field-rendering surface (rating, comment) stays declarative in
// `src/manifest.json`.
import { generateUrl } from '@nextcloud/router'

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

/**
 * submitPublicSurvey — handler for the manifest-driven PublicSurvey
 * `type: "form"` route. Resolves the survey's `:token` from the
 * route, queries the URL for `entity` / `id` params (set by the
 * source app embedding the survey), and POSTs the formData to
 * `/apps/pipelinq/public/survey/{token}/respond`.
 *
 * Throws on non-2xx so CnFormPage's submit pipeline catches it,
 * surfaces the error in the form, and emits `@error`.
 *
 * @param {object} formData {rating, comment} from the manifest fields[]
 * @param {object} $route Vue Router route — exposes params.token
 * @return {Promise<void>}
 */
async function submitPublicSurvey(formData, $route /* , $router */) {
	const token = $route?.params?.token
	if (!token) {
		throw new Error('Missing survey token in route')
	}
	const params = new URLSearchParams(window.location.search)
	const body = {
		answers: Object.entries(formData).map(([key, value]) => ({
			questionId: key,
			value: value === null || value === undefined ? '' : String(value),
		})),
		entityType: params.get('entity'),
		entityId: params.get('id'),
	}
	const response = await fetch(generateUrl('/apps/pipelinq/public/survey/' + token + '/respond'), {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify(body),
	})
	if (!response.ok) {
		let message = 'Failed to submit'
		try {
			const data = await response.json()
			message = data.error || message
		} catch (_e) { /* ignore parse errors */ }
		throw new Error(message)
	}
}

export default {
	// Genuine exceptions
	DashboardView,
	PipelineBoardView,
	MyWorkView,

	// Submit-handler escape hatch (manifest-form-page-type)
	submitPublicSurvey,

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
}
