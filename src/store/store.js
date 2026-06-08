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
		if (config.register && config.posRefund_schema) {
			objectStore.registerObjectType('posRefund', config.posRefund_schema, config.register)
		}
		if (config.register && config.posRefundLine_schema) {
			objectStore.registerObjectType('posRefundLine', config.posRefundLine_schema, config.register)
		}
		// Billing categories (billable-categories-and-tags) — REQ-BCT-001.
		if (config.register && config.billingCategory_schema) {
			objectStore.registerObjectType('billingCategory', config.billingCategory_schema, config.register)
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
	}

	return { settingsStore, objectStore }
}

export { useObjectStore, useSettingsStore }
