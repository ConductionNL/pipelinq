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
		// fetchSettings() only flips `initialized` on success; on a failed load
		// it stays false. Drop the cached promise then so the next caller retries.
		if (!result.settingsStore.isInitialized) {
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
 * Register every object type against the shared object store, addressed by
 * slug. Static (needs no app config) so it can run synchronously at bootstrap,
 * before any view's onMounted fetchSchema() — closing the race that left
 * list pages blank. Idempotent.
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

	// registerObjectTypes() is also called eagerly in main.js, but entry points
	// without that bootstrap (settings.js, dashboard widgets) rely on this call.
	//
	// This registry-driven registration (PR #262) supersedes the earlier inline
	// per-type block: objectTypes.js now registers EVERY type — including the POS
	// types posTransaction/posTransactionLine/product/productCategory/
	// billingCategory — by canonical slug against the 'pipelinq' register slug.
	// OpenRegister resolves slugs in the {register}/{schema} object path, so this
	// is the same slug-fallback the inline block used for those POS types, applied
	// universally and statically (no dependency on numeric *_schema config), which
	// fixes the "Object type X is not registered" failures on POS checkout, the
	// Returns / Billing categories / BI export pages regardless of config linkage.
	registerObjectTypes()
	await settingsStore.fetchSettings()

	return { settingsStore, objectStore }
}

export { useObjectStore, useSettingsStore }
