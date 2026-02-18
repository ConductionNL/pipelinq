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
	}

	return { settingsStore, objectStore }
}

export { useObjectStore, useSettingsStore }
