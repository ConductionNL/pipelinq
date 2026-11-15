/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pipeline and stage fields the lead and request forms share.
 *
 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-leads-ui/tasks.md#task-49
 */
import { describe, expect, it } from 'vitest'
import mixin from '../../src/mixins/pipelineStageMixin.js'

const sales = {
	id: 'p-1',
	stages: [
		{ name: 'Won', order: 3, isClosed: true },
		{ name: 'Qualified', order: 2, isClosed: false },
		{ name: 'New', order: 1, isClosed: true },
	],
}

/**
 * @param {object} form The host's form.
 * @param {Array} pipelines The stored pipelines.
 * @return {object} A host with the mixin bound.
 */
function host (form, pipelines = [sales]) {
	const vm = { form, pipelines }
	for (const [name, fn] of Object.entries(mixin.methods)) {
		vm[name] = fn.bind(vm)
	}
	for (const [name, fn] of Object.entries(mixin.computed)) {
		Object.defineProperty(vm, name, { get: () => fn.call(vm) })
	}
	return vm
}

describe('pipelineStageMixin', () => {
	it('finds the chosen pipeline, or null when none or an unknown one is chosen', () => {
		expect(host({ pipeline: 'p-1' }).selectedPipeline).toBe(sales)
		expect(host({ pipeline: null }).selectedPipeline).toBeNull()
		expect(host({ pipeline: 'gone' }).selectedPipeline).toBeNull()
	})

	it('lists the stage names in stage order without reordering the pipeline', () => {
		expect(host({ pipeline: 'p-1' }).stageOptions).toEqual(['New', 'Qualified', 'Won'])
		expect(sales.stages.map((s) => s.name)).toEqual(['Won', 'Qualified', 'New'])
	})

	it('has no stage options without a pipeline or without stages', () => {
		expect(host({ pipeline: null }).stageOptions).toEqual([])
		expect(host({ pipeline: 'p-2' }, [{ id: 'p-2' }]).stageOptions).toEqual([])
	})

	it('moves the form to the first open stage when the pipeline changes', () => {
		const vm = host({ pipeline: 'p-1', stage: 'Old' })
		vm.onPipelineChange()
		expect(vm.form.stage).toBe('Qualified')
	})

	it('clears the stage when the pipeline is cleared', () => {
		const vm = host({ pipeline: null, stage: 'Old' })
		vm.onPipelineChange()
		expect(vm.form.stage).toBeNull()
	})

	it('leaves the stage empty when every stage is closed', () => {
		const closed = { id: 'p-3', stages: [{ name: 'Done', order: 1, isClosed: true }] }
		const vm = host({ pipeline: 'p-3', stage: 'Old' }, [closed])
		vm.onPipelineChange()
		expect(vm.form.stage).toBeNull()
	})

	it('handles a pipeline without stages', () => {
		const vm = host({ pipeline: null, stage: 'Keep' })
		vm.placeOnFirstOpenStage({ id: 'p-4' })
		expect(vm.form.stage).toBe('Keep')
	})
})
