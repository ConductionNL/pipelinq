import { useObjectStore } from './modules/object.js'
import { useSettingsStore } from './modules/settings.js'

// Memoised in-flight/resolved bootstrap. initializeStores() loads the app
// settings once and registers the object types; the config does not change
// during a page session, so every caller (main bootstrap, dashboard data
// fetchers, …) shares this single promise instead of re-fetching
// /api/settings per call. Reset to null on failure so a later call retries.
let initPromise = null

/**
 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-60
 */
export function initializeStores() {
	if (initPromise) {
		return initPromise
	}
	initPromise = doInitializeStores().then((result) => {
		// Settings failed to load (endpoint error → null config): don't cache
		// the un-registered state, so the next caller can retry.
		if (!result.settingsStore.getConfig) {
			initPromise = null
		}
		return result
	}).catch((error) => {
		initPromise = null
		throw error
	})
	return initPromise
}

async function doInitializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()

	const config = await settingsStore.fetchSettings()

	if (config) {
		if (config.register && config.client_schema) {
			objectStore.registerObjectType('client', config.client_schema, config.register)
		}
		if (config.register && config.request_schema) {
			objectStore.registerObjectType('request', config.request_schema, config.register)
		}
		if (config.register && config.contact_schema) {
			objectStore.registerObjectType('contact', config.contact_schema, config.register)
		}
		if (config.register && config.lead_schema) {
			objectStore.registerObjectType('lead', config.lead_schema, config.register)
		}
		if (config.register && config.pipeline_schema) {
			objectStore.registerObjectType('pipeline', config.pipeline_schema, config.register)
		}
		if (config.register && config.product_schema) {
			objectStore.registerObjectType('product', config.product_schema, config.register)
		}
		if (config.register && config.productCategory_schema) {
			objectStore.registerObjectType('productCategory', config.productCategory_schema, config.register)
		}
		if (config.register && config.leadProduct_schema) {
			objectStore.registerObjectType('leadProduct', config.leadProduct_schema, config.register)
		}
		if (config.register && config.intakeForm_schema) {
			objectStore.registerObjectType('intakeForm', config.intakeForm_schema, config.register)
		}
		if (config.register && config.intakeSubmission_schema) {
			objectStore.registerObjectType('intakeSubmission', config.intakeSubmission_schema, config.register)
		}
		if (config.register && config.relationship_schema) {
			objectStore.registerObjectType('relationship', config.relationship_schema, config.register)
		}
		if (config.register && config.queue_schema) {
			objectStore.registerObjectType('queue', config.queue_schema, config.register)
		}
		if (config.register && config.skill_schema) {
			objectStore.registerObjectType('skill', config.skill_schema, config.register)
		}
		if (config.register && config.agentProfile_schema) {
			objectStore.registerObjectType('agentProfile', config.agentProfile_schema, config.register)
		}
		if (config.register && config.contactmoment_schema) {
			objectStore.registerObjectType('contactmoment', config.contactmoment_schema, config.register)
		}
		if (config.register && config.survey_schema) {
			objectStore.registerObjectType('survey', config.survey_schema, config.register)
		}
		if (config.register && config.surveyResponse_schema) {
			objectStore.registerObjectType('surveyResponse', config.surveyResponse_schema, config.register)
		}
		if (config.register && config.complaint_schema) {
			objectStore.registerObjectType('complaint', config.complaint_schema, config.register)
		}
		if (config.register && config.posTransaction_schema) {
			objectStore.registerObjectType('posTransaction', config.posTransaction_schema, config.register)
		}
		if (config.register && config.posTransactionLine_schema) {
			objectStore.registerObjectType('posTransactionLine', config.posTransactionLine_schema, config.register)
		}
		if (config.register && config.receiptTemplate_schema) {
			objectStore.registerObjectType('receiptTemplate', config.receiptTemplate_schema, config.register)
		}
		if (config.register && config.receiptPrintLog_schema) {
			objectStore.registerObjectType('receiptPrintLog', config.receiptPrintLog_schema, config.register)
		}
		if (config.register && config.refundReason_schema) {
			objectStore.registerObjectType('refundReason', config.refundReason_schema, config.register)
		}
		// posRefund / billingCategory / exportJob / automation may have an empty
		// numeric schema id in a deployed app-config (the OpenRegister schema
		// exists under its canonical slug but the admin-settings link was never
		// populated). OpenRegister resolves both numeric ids and slugs in the
		// {register}/{schema} object path, so fall back to the canonical slug —
		// keeping the affected list pages (Returns, Billing categories, BI
		// export jobs, Automations) functional regardless of config linkage.
		if (config.register) {
			objectStore.registerObjectType('posRefund', config.posRefund_schema || 'posRefund', config.register)
		}
		if (config.register && config.posRefundLine_schema) {
			objectStore.registerObjectType('posRefundLine', config.posRefundLine_schema, config.register)
		}
		// POS cash-drawer (pos-cash-management) — REQ-CCM-001..007.
		if (config.register && config.cashShift_schema) {
			objectStore.registerObjectType('cashShift', config.cashShift_schema, config.register)
		}
		if (config.register && config.cashDrop_schema) {
			objectStore.registerObjectType('cashDrop', config.cashDrop_schema, config.register)
		}
		if (config.register && config.cashCount_schema) {
			objectStore.registerObjectType('cashCount', config.cashCount_schema, config.register)
		}
		if (config.register && config.cashDiff_schema) {
			objectStore.registerObjectType('cashDiff', config.cashDiff_schema, config.register)
		}
		// POS staff PIN + role permissions (pos-staff-pin-permissions REQ-PSP-001/002).
		if (config.register && config.posRole_schema) {
			objectStore.registerObjectType('posRole', config.posRole_schema, config.register)
		}
		if (config.register && config.posStaff_schema) {
			objectStore.registerObjectType('posStaff', config.posStaff_schema, config.register)
		}
		// Billing categories (billable-categories-and-tags) — REQ-BCT-001.
		if (config.register) {
			objectStore.registerObjectType('billingCategory', config.billingCategory_schema || 'billingCategory', config.register)
		}
		// BI export jobs (bi-export-and-data-warehouse-sink) — REQ-BIE-002.
		if (config.register) {
			objectStore.registerObjectType('exportJob', config.exportJob_schema || 'exportJob', config.register)
		}
		// CRM workflow automation rules (crm-workflow-automation).
		if (config.register) {
			objectStore.registerObjectType('automation', config.automation_schema || 'automation', config.register)
		}
		// Appointment booking — admin views (appointment-booking 01..11).
		if (config.register && config.service_schema) {
			objectStore.registerObjectType('service', config.service_schema, config.register)
		}
		if (config.register && config.resource_schema) {
			objectStore.registerObjectType('resource', config.resource_schema, config.register)
		}
		if (config.register && config.booking_schema) {
			objectStore.registerObjectType('booking', config.booking_schema, config.register)
		}
		if (config.register && config.walkInTicket_schema) {
			objectStore.registerObjectType('walkInTicket', config.walkInTicket_schema, config.register)
		}
		// Project / WBS hierarchy (project-task-hierarchy):
		// project → projectPhase → projectTask → projectActivity.
		// `project` and `projectPhase` are also used by the Shillinq
		// project-ledger integration; registering them here exposes the
		// stores to the Projecten UI without affecting the listener-side
		// reads (REQ-PTH-001..004).
		if (config.register && config.project_schema) {
			objectStore.registerObjectType('project', config.project_schema, config.register)
		}
		if (config.register && config.projectPhase_schema) {
			objectStore.registerObjectType('projectPhase', config.projectPhase_schema, config.register)
		}
		if (config.register && config.projectTask_schema) {
			objectStore.registerObjectType('projectTask', config.projectTask_schema, config.register)
		}
		if (config.register && config.projectActivity_schema) {
			objectStore.registerObjectType('projectActivity', config.projectActivity_schema, config.register)
		}
	}

	return { settingsStore, objectStore }
}

export { useObjectStore, useSettingsStore }
