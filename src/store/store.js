import { useObjectStore } from './modules/object.js'
import { useSettingsStore } from './modules/settings.js'

export async function initializeStores() {
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
		if (config.register && config.relationship_schema) {
			objectStore.registerObjectType('relationship', config.relationship_schema, config.register)
		if (config.register && config.queue_schema) {
			objectStore.registerObjectType('queue', config.queue_schema, config.register)
		}
		if (config.register && config.skill_schema) {
			objectStore.registerObjectType('skill', config.skill_schema, config.register)
		}
		if (config.register && config.agentProfile_schema) {
			objectStore.registerObjectType('agentProfile', config.agentProfile_schema, config.register)
		if (config.register && config.task_schema) {
			objectStore.registerObjectType('task', config.task_schema, config.register)
		}
		if (config.register && config.kennisartikel_schema) {
			objectStore.registerObjectType('kennisartikel', config.kennisartikel_schema, config.register)
		}
		if (config.register && config.kenniscategorie_schema) {
			objectStore.registerObjectType('kenniscategorie', config.kenniscategorie_schema, config.register)
		}
		if (config.register && config.kennisfeedback_schema) {
			objectStore.registerObjectType('kennisfeedback', config.kennisfeedback_schema, config.register)
		}
		if (config.register && config.contactmoment_schema) {
			objectStore.registerObjectType('contactmoment', config.contactmoment_schema, config.register)
		}
		if (config.register && config.survey_schema) {
			objectStore.registerObjectType('survey', config.survey_schema, config.register)
		}
		if (config.register && config.surveyResponse_schema) {
			objectStore.registerObjectType('surveyResponse', config.surveyResponse_schema, config.register)
		if (config.register && config.complaint_schema) {
			objectStore.registerObjectType('complaint', config.complaint_schema, config.register)
		}
	}

	return { settingsStore, objectStore }
}

export { useObjectStore, useSettingsStore }
