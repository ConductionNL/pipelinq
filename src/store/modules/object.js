/**
 * Object store for Pipelinq — powered by @conduction/nextcloud-vue.
 *
 * Uses createObjectStore('object') to maintain the same Pinia store ID
 * that all existing views reference. The full implementation (CRUD,
 * pagination, caching, resolveReferences) lives in the shared library.
 *
 * Plugins add sub-resource support for files, audit trails, and relations.
 *
 * Multi-tenancy (ADR-025, task 9.1): useTenantContext is imported from
 * @conduction/nextcloud-vue and passed explicitly to the factory once the
 * nc-vue multi-tenancy-context change ships. The factory already derives
 * tenant context implicitly; this import formalises that dependency so
 * Hydra coordination can trace which apps consume multi-tenancy-context.
 *
 * To activate when the library ships:
 *   1. Add useTenantContext to the named import below.
 *   2. Pass tenantContext: useTenantContext to the createObjectStore options.
 */
import { createObjectStore, filesPlugin, auditTrailsPlugin, relationsPlugin, registerMappingPlugin } from '@conduction/nextcloud-vue'

export const useObjectStore = createObjectStore('object', {
	plugins: [
		filesPlugin(),
		auditTrailsPlugin(),
		relationsPlugin(),
		registerMappingPlugin(),
	],
	// tenantContext: useTenantContext, // Activate once nc-vue multi-tenancy-context ships.
})
