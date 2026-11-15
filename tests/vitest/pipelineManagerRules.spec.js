// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pipeline manager, mounted with the object store doubled: how pipelines
 * are described, the default pipeline rules on save and delete, and how many
 * items a deletion affects.
 *
 * @spec openspec/specs/pipeline/spec.md
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	globalThis.n = (app, one, many, count, vars = {}) => globalThis.t(app, count === 1 ? one : many, vars).replace('%n', String(count))
})

const stub = (name) => ({ name, render: () => null })
const toasts = []
vi.mock('@conduction/nextcloud-vue', () => ({ CnSettingsSection: stub('CnSettingsSection') }))
vi.mock('@nextcloud/vue', () => ({ NcButton: stub('NcButton'), NcEmptyContent: stub('NcEmptyContent'), NcLoadingIcon: stub('NcLoadingIcon') }))
vi.mock('@nextcloud/dialogs', () => ({ showError: (m) => toasts.push(m) }))
vi.mock('../../src/dialogs/DeletePipelineDialog.vue', () => ({ default: stub('DeletePipelineDialog') }))
vi.mock('../../src/dialogs/PipelineFormDialog.vue', () => ({ default: stub('PipelineFormDialog') }))
const store = {
	collections: { pipeline: [] },
	loading: {},
	objectTypeRegistry: { lead: { register: 'r', schema: 'lead' }, ticket: { register: 'r', schema: 'ticket' } },
	fetchCollection: vi.fn(),
	saveObject: vi.fn(),
	deleteObject: vi.fn(),
}
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))

const { default: PipelineManager } = await import('../../src/views/settings/PipelineManager.vue')

/**
 * Mount the manager over the given pipelines.
 *
 * @param {Array} pipelines The pipelines in the store.
 * @return {Promise<object>} The wrapper.
 */
async function mountManager(pipelines) {
	store.collections = { pipeline: pipelines }
	const w = shallowMount(PipelineManager, { global: { mocks: { t: globalThis.t, n: globalThis.n } } })
	await flushPromises()
	return w
}

beforeEach(() => {
	toasts.length = 0
	vi.clearAllMocks()
	globalThis.OC = { requestToken: 'tok' }
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('describing pipelines', () => {
	it('names the schemas, counts the stages and previews them in order', async () => {
		const w = await mountManager([])
		expect(store.fetchCollection).toHaveBeenCalledWith('pipeline', { _limit: 100 })
		expect(w.vm.schemaLabel({ propertyMappings: [{ schemaSlug: 'lead' }, { schemaSlug: 'request' }] })).toBe('lead, request')
		expect([w.vm.schemaLabel({ entityType: 'both' }), w.vm.schemaLabel({ entityType: 'odd' }), w.vm.schemaLabel({})]).toEqual(['Leads & Requests', 'odd', ''])
		expect([w.vm.stageCount({ stages: [{}] }), w.vm.stageCount({})]).toEqual(['1 stage', '0 stages'])
		expect(w.vm.stagePreview({})).toBe('No stages')
		expect(w.vm.stagePreview({ stages: [{ name: 'B', order: 2 }, { name: 'A', order: 1 }] })).toBe('A → B')
		const many = { stages: 'ABCDEF'.split('').map((name, order) => ({ name, order })) }
		expect(w.vm.stagePreview(many)).toBe('A → B → ... → E → F')
	})

	it('opens the form for a new or an existing pipeline, and closes it', async () => {
		const w = await mountManager([])
		w.vm.onEdit({ id: 'p1' })
		expect([w.vm.editingPipeline, w.vm.showForm]).toEqual([{ id: 'p1' }, true])
		w.vm.onCreate()
		expect(w.vm.editingPipeline).toBeNull()
		w.vm.onFormCancel()
		expect(w.vm.showForm).toBe(false)
	})
})

describe('the default pipeline', () => {
	it('makes the first pipeline the default', async () => {
		const w = await mountManager([])
		const data = { title: 'Sales' }
		await w.vm.onSave(data)
		expect(store.saveObject).toHaveBeenCalledWith('pipeline', { title: 'Sales', isDefault: true })
		expect(w.vm.showForm).toBe(false)
	})

	it('moves the default to the saved pipeline, turning the others off', async () => {
		const w = await mountManager([{ id: 'p1', isDefault: true }, { id: 'p2' }])
		await w.vm.onSave({ id: 'p2', isDefault: true })
		expect(store.saveObject.mock.calls).toEqual([
			['pipeline', { id: 'p1', isDefault: false }],
			['pipeline', { id: 'p2', isDefault: true }],
		])
	})

	it('keeps the only default a default, and says why', async () => {
		const w = await mountManager([{ id: 'p1', isDefault: true }, { id: 'p2' }])
		await w.vm.onSave({ id: 'p1', isDefault: false })
		expect(toasts).toEqual(['At least one pipeline must be set as default'])
		expect(store.saveObject).toHaveBeenCalledWith('pipeline', { id: 'p1', isDefault: true })
	})

	it('refuses to delete the default pipeline', async () => {
		const w = await mountManager([{ id: 'p1', isDefault: true }])
		await w.vm.onDeleteClick({ id: 'p1', isDefault: true })
		expect([toasts, w.vm.deletingPipeline]).toEqual([['Cannot delete the default pipeline. Set another pipeline as default first.'], null])
	})
})

describe('deleting', () => {
	it('counts the leads and requests on the pipeline before asking, then deletes it', async () => {
		const fetchSpy = vi.fn(async () => ({ ok: true, json: async () => ({ total: 3 }) }))
		vi.stubGlobal('fetch', fetchSpy)
		const w = await mountManager([{ id: 'p2' }])
		await w.vm.onDeleteClick({ id: 'p2' })
		expect(fetchSpy.mock.calls.map((c) => c[0])).toEqual([
			expect.stringContaining('/objects/r/lead?pipeline=p2&_limit=1'),
			expect.stringContaining('/objects/r/ticket?ticketType=request&pipeline=p2&_limit=1'),
		])
		expect([w.vm.deleteAffectedCount, w.vm.deletingPipeline]).toEqual([6, { id: 'p2' }])
		await w.vm.onDeleteConfirm()
		expect(store.deleteObject).toHaveBeenCalledWith('pipeline', 'p2')
		expect([w.vm.deletingPipeline, w.vm.deleteAffectedCount]).toEqual([null, 0])
	})

	it('counts only the mapped schemas it knows, and zero when a count fails', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false })))
		const w = await mountManager([{ id: 'p3', propertyMappings: [{ schemaSlug: 'lead' }, { schemaSlug: 'unknown' }] }])
		expect(await w.vm.countAffectedItems('p3')).toBe(0)
		expect(fetch).toHaveBeenCalledTimes(1)
		vi.stubGlobal('fetch', vi.fn(async () => { throw new Error('x') }))
		expect(await w.vm.countAffectedItems('p3')).toBe(0)
		expect(w.vm.pipelineCountPath('unknown', 'p3')).toBeNull()
	})
})
