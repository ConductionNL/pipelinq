// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lead list takes its stages from the default LEAD pipeline. A request
 * board marked default (Vragen en meldingen) is not a lead pipeline, so the
 * lead list must not read its stages.
 *
 * @spec openspec/specs/lead-management/spec.md
 */

import { beforeAll, describe, expect, it, vi } from 'vitest'
import { defaultPipelineFor } from '../../src/services/pipelineUtils.js'

vi.mock('@conduction/nextcloud-vue', () => ({ CnIndexPage: {}, openRowTarget: vi.fn() }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: () => ({}) }))

const requests = { id: 'p-req', title: 'Vragen en meldingen', isDefault: true, propertyMappings: [{ schemaSlug: 'request' }], stages: [{ name: 'Nieuw' }] }
const sales = { id: 'p-sales', title: 'Verkoop', isDefault: false, propertyMappings: [{ schemaSlug: 'lead' }], stages: [{ name: 'Gekwalificeerd' }] }
const salesDefault = { ...sales, id: 'p-sales-2', isDefault: true }
const unscoped = { id: 'p-any', title: 'Algemeen', isDefault: false, stages: [] }

let list

beforeAll(async () => {
	globalThis.t = (app, text) => text
	list = (await import('../../src/views/leads/LeadList.vue')).default
})

describe('the default lead pipeline', () => {
	it('is the default among lead pipelines, else the first lead pipeline', () => {
		expect(defaultPipelineFor([requests, sales], 'lead')).toBe(sales)
		expect(defaultPipelineFor([requests, sales, salesDefault], 'lead')).toBe(salesDefault)
		expect(defaultPipelineFor([requests, unscoped], 'lead')).toBe(unscoped)
		expect(defaultPipelineFor([requests], 'lead')).toBeNull()
		expect(defaultPipelineFor('nope', 'lead')).toBeNull()
	})

	it('gives the lead list the lead pipeline\'s stages, not the request board\'s', async () => {
		const ctx = {
			objectStore: { fetchCollection: vi.fn().mockResolvedValue([requests, sales]) },
			defaultPipelineId: null,
			stages: [],
		}
		await list.methods.loadDefaultPipeline.call(ctx)
		expect(ctx.defaultPipelineId).toBe('p-sales')
		expect(ctx.stages).toEqual(sales.stages)
	})
})
