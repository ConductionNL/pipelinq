import { useObjectStore } from './modules/object.js'
import { useSettingsStore } from './modules/settings.js'
import { objectTypes, registerConfigKeyByGroup } from '../config/objectTypes.js'

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

	// fetchSettings() returns the raw /api/settings response wrapper
	// ({ config, isAdmin, … }); the flat schema/register map lives under
	// getConfig. Reading the wrapper directly (config.register) silently
	// yielded undefined and registered nothing — read getConfig instead.
	await settingsStore.fetchSettings()
	const config = settingsStore.getConfig

	if (config) {
		const registerKeyByGroup = registerConfigKeyByGroup()
		for (const { slug, group } of objectTypes()) {
			const registerId = config[registerKeyByGroup[group] ?? 'register']
			const schemaId = config[`${slug}_schema`]
			if (registerId && schemaId) {
				objectStore.registerObjectType(slug, schemaId, registerId)
			}
		}
	}

	return { settingsStore, objectStore }
}

export { useObjectStore, useSettingsStore }
