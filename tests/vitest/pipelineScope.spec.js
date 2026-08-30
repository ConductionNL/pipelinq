// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Which pipelines a create form may offer.
 *
 * The lead and request forms filtered on `pipeline.entityType`, a field the
 * pipeline schema does not define, the seed data does not write, and the
 * pipeline editor cannot set. Every pipeline therefore failed the filter: the
 * dropdown listed nothing, and `autoAssignDefaultPipeline()` had no default to
 * find. The live model is `propertyMappings[].schemaSlug`; `entityType` survives
 * only as a fallback for pipelines stored before it.
 *
 * The load-bearing case is the last one — a pipeline declaring NEITHER shape is
 * unscoped, not inapplicable. Reading it as inapplicable is exactly the bug.
 */

import { describe, expect, it } from 'vitest'
import {
	pipelineAppliesTo,
	pipelineEntitySlugs,
} from '../../src/services/pipelineUtils.js'

describe('pipelineEntitySlugs', () => {
	it('reads the slugs from propertyMappings', () => {
		expect(
			pipelineEntitySlugs({
				propertyMappings: [{ schemaSlug: 'lead' }, { schemaSlug: 'request' }],
			}),
		).toEqual(['lead', 'request'])
	})

	it('expands the legacy entityType "both"', () => {
		expect(pipelineEntitySlugs({ entityType: 'both' })).toEqual([
			'lead',
			'request',
		])
	})

	it('wraps a single legacy entityType', () => {
		expect(pipelineEntitySlugs({ entityType: 'lead' })).toEqual(['lead'])
	})

	it('prefers propertyMappings over a stale entityType', () => {
		expect(
			pipelineEntitySlugs({
				entityType: 'lead',
				propertyMappings: [{ schemaSlug: 'request' }],
			}),
		).toEqual(['request'])
	})

	it('returns null when the pipeline declares no scope at all', () => {
		expect(pipelineEntitySlugs({ title: 'Sales' })).toBeNull()
	})

	it('treats an empty or slug-less propertyMappings as undeclared', () => {
		expect(pipelineEntitySlugs({ propertyMappings: [] })).toBeNull()
		expect(pipelineEntitySlugs({ propertyMappings: [{}] })).toBeNull()
	})
})

describe('pipelineAppliesTo', () => {
	it('matches a mapped slug and rejects an unmapped one', () => {
		const pipeline = { propertyMappings: [{ schemaSlug: 'lead' }] }
		expect(pipelineAppliesTo(pipeline, 'lead')).toBe(true)
		expect(pipelineAppliesTo(pipeline, 'request')).toBe(false)
	})

	it('honours the legacy entityType on both sides', () => {
		expect(pipelineAppliesTo({ entityType: 'both' }, 'request')).toBe(true)
		expect(pipelineAppliesTo({ entityType: 'lead' }, 'request')).toBe(false)
	})

	it('offers an unscoped pipeline to every entity — the regression', () => {
		// The exact shape the seeded demo pipelines had: a title, stages, an
		// isDefault flag, and no scope. Filtering these OUT is what emptied the
		// dropdown, so this case must stay true.
		const unscoped = {
			title: '[Demo] Sales pipeline',
			isDefault: true,
			stages: [{ name: 'New', order: 1 }],
		}
		expect(pipelineAppliesTo(unscoped, 'lead')).toBe(true)
		expect(pipelineAppliesTo(unscoped, 'request')).toBe(true)
	})

	it('is safe on a null pipeline', () => {
		expect(pipelineAppliesTo(null, 'lead')).toBe(true)
	})
})
