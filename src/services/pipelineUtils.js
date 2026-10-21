/**
 * Shared pipeline utilities: record kinds, pipeline scope and the stale threshold.
 */

/**
 * Logical entity types that were folded into the unified `ticket` schema by
 * unify-ticket-supertype. They survive as `ticketType` discriminator values —
 * and as `schemaSlug` values inside stored pipeline `propertyMappings` — so
 * anything that turns a logical slug into an OpenRegister object type must
 * route through resolveObjectType().
 */
const TICKET_SUBTYPES = ['request', 'complaint', 'interaction']

/**
 * Map a logical entity slug onto the OpenRegister object type it now lives in.
 *
 * The former `request` / `complaint` / `contactmoment` schemas are one `ticket`
 * schema discriminated by `ticketType`; every other slug maps to itself.
 *
 * @param {string} schemaSlug Logical slug ('lead', 'request', 'ticket', …).
 * @return {{objectType: string, ticketType: (string|null)}} Registered object
 *   type plus the `ticketType` filter/field to narrow it, or null when the type
 *   needs no discriminator.
 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-unified-tickets-workspace
 */
export function resolveObjectType(schemaSlug) {
	if (TICKET_SUBTYPES.includes(schemaSlug)) {
		return { objectType: 'ticket', ticketType: schemaSlug }
	}
	return { objectType: schemaSlug, ticketType: null }
}

/**
 * The logical entity slugs a pipeline applies to.
 *
 * A pipeline declares this through `propertyMappings[].schemaSlug`. The older
 * `entityType` field ('lead' | 'request' | 'both') is still read as a fallback
 * for pipelines stored before the mapping shape landed.
 *
 * @param {object} pipeline The stored pipeline object.
 * @return {Array<string>|null} The slugs it applies to, or null when it
 *   declares NEITHER shape — meaning "unscoped", not "applies to nothing".
 * @spec openspec/specs/lead-management/spec.md
 */
export function pipelineEntitySlugs(pipeline) {
	if (!pipeline) return null
	const mappings = pipeline.propertyMappings
	if (Array.isArray(mappings) && mappings.length > 0) {
		const slugs = mappings.map((m) => m && m.schemaSlug).filter(Boolean)
		if (slugs.length > 0) return slugs
	}
	if (pipeline.entityType === 'both') return ['lead', 'request']
	if (pipeline.entityType) return [pipeline.entityType]
	return null
}

/**
 * Whether a pipeline can be chosen for `slug`.
 *
 * A pipeline that declares no scope at all is applicable to everything. That
 * default is load-bearing rather than lenient: filtering an undeclared pipeline
 * OUT is what emptied the pipeline dropdown on the lead and request forms, so
 * every pipeline in the system — including the seeded demo ones — was
 * unselectable and no default could be auto-assigned.
 *
 * @param {object} pipeline The stored pipeline object.
 * @param {string} slug The logical entity slug ('lead', 'request', …).
 * @return {boolean}
 * @spec openspec/specs/lead-management/spec.md
 */
export function pipelineAppliesTo(pipeline, slug) {
	const slugs = pipelineEntitySlugs(pipeline)
	if (slugs === null) return true
	return slugs.includes(slug)
}

/**
 * Resolve the stale-threshold (days) from the Pipelinq settings config.
 * Falls back to 14 when the value is missing or unparseable so consumers
 * never need to special-case an empty store.
 *
 * @param {object|null} config Pipelinq settings config (settingsStore.config).
 * @return {number}
 * @spec openspec/specs/lead-management/spec.md
 */
export function getStaleThreshold(config) {
	const raw = config && config.lead_stale_threshold_days
	const parsed = parseInt(raw, 10)
	if (Number.isFinite(parsed) && parsed > 0) {
		return parsed
	}
	return 14
}

