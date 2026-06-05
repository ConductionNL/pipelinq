import { useObjectStore } from './modules/object.js'
import { useSettingsStore } from './modules/settings.js'
import { objectTypes, objectTypeGroupsByKey } from '../config/objectTypes.js'

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

/**
 * Register every object type the app uses against the shared object store.
 *
 * Registration is fully static: types are addressed by SLUG (schema slug =
 * type slug, register slug from the group), so the store builds slug-based API
 * URLs — e.g. /objects/pipelinq/posTransaction — matching the manifest-driven
 * index/detail pages. Because it needs no app config, it can run synchronously
 * at bootstrap, before any view mounts. That closes a race where a list view's
 * onMounted fetchSchema() ran before the (previously settings-dependent, async)
 * registration completed — leaving the page blank until you navigated away and
 * back. Idempotent: safe to call more than once.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-60
 */
export function registerObjectTypes() {
	const objectStore = useObjectStore()
	const groups = objectTypeGroupsByKey()
	for (const { slug, group } of objectTypes()) {
		const groupDef = groups[group]
		if (groupDef) {
			objectStore.registerObjectType(slug, slug, groupDef.registerSlug)
		}
	}
}

async function doInitializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()

	registerObjectTypes()
	// Load app settings into the store for the rest of the app (isConfigured,
	// admin flags, the register/schema id maps the settings UI maps, …).
	await settingsStore.fetchSettings()

	return { settingsStore, objectStore }
}

export { useObjectStore, useSettingsStore }
