/**
 * Object store for Pipelinq — powered by @conduction/nextcloud-vue.
 *
 * Uses createObjectStore('object') to maintain the same Pinia store ID
 * that all existing views reference. The full implementation (CRUD,
 * pagination, caching, resolveReferences) lives in the shared library.
 *
 * Plugins add sub-resource support for files, audit trails, and relations.
 */
import { createObjectStore, filesPlugin, auditTrailsPlugin, relationsPlugin, registerMappingPlugin } from '@conduction/nextcloud-vue'

export const useObjectStore = createObjectStore('object', {
	plugins: [
		filesPlugin(),
		auditTrailsPlugin(),
		relationsPlugin(),
		registerMappingPlugin(),
	],
})
