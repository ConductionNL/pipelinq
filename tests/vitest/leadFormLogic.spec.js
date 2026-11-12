// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lead form's own logic: field validation, source options, the close
 * date round trip, stage placement and the save payload.
 *
 * @spec openspec/specs/lead-management/spec.md
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/initial-state', () => ({
	loadState: () => ({ currency: 'EUR' }),
}))
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({ fetchCollection: vi.fn(async () => []), collections: { pipeline: [] }, objects: {} }),
}))
vi.mock('../../src/store/modules/leadSources.js', () => ({
	useLeadSourcesStore: () => ({ fetchSources: vi.fn(async () => []), sourceNames: [] }),
}))
vi.mock('../../src/dialogs/ClientCreateDialog.vue', () => ({
	default: { name: 'ClientCreateDialog', render: () => null },
}))
vi.mock('../../src/dialogs/ContactCreateDialog.vue', () => ({
	default: { name: 'ContactCreateDialog', render: () => null },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnResourceSelect: { name: 'CnResourceSelect', render: () => null },
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', render: () => null },
	NcDateTimePickerNative: { name: 'NcDateTimePickerNative', render: () => null },
	NcSelect: { name: 'NcSelect', render: () => null },
	NcTextField: { name: 'NcTextField', render: () => null },
}))

globalThis.t = (_app, text) => text
globalThis.n = (_app, s) => s

const { default: LeadForm } = await import('../../src/views/leads/LeadForm.vue')

const errorsOf = (form) => LeadForm.computed.errors.call({ form })

const validForm = { title: 'Deal', value: null, currency: 'EUR', pipeline: 'p-1' }

describe('errors', () => {
	it('accepts a titled lead in a pipeline', () => {
		expect(errorsOf(validForm)).toEqual({})
		expect(errorsOf({ ...validForm, value: 0, currency: '' })).toEqual({})
	})

	it('requires a non-blank title and a pipeline', () => {
		expect(errorsOf({ ...validForm, title: '   ', pipeline: null })).toEqual({
			title: 'Title is required',
			pipeline: 'Pipeline is required',
		})
	})

	it('refuses a negative value', () => {
		expect(errorsOf({ ...validForm, value: -1 })).toEqual({ value: 'Value must be non-negative' })
	})

	it('refuses a currency that is not a three-letter code', () => {
		expect(errorsOf({ ...validForm, currency: 'EURO' })).toEqual({
			currency: 'Use a three-letter currency code, such as EUR or USD',
		})
	})
})

describe('computed helpers', () => {
	it('labels each lead source by its id', () => {
		const options = LeadForm.computed.sourceOptions.call({ leadSourcesStore: { sourceNames: ['website', 'custom-x'] } })
		expect(options.map((o) => o.id)).toEqual(['website', 'custom-x'])
		expect(options.every((o) => typeof o.label === 'string' && o.label.length > 0)).toBe(true)
	})

	it('stores the picked close date as a local YYYY-MM-DD and reads it back', () => {
		const ctx = { form: { expectedCloseDate: null } }
		LeadForm.computed.expectedCloseDateObj.set.call(ctx, new Date(2026, 6, 9))
		expect(ctx.form.expectedCloseDate).toBe('2026-07-09')

		const back = LeadForm.computed.expectedCloseDateObj.get.call(ctx)
		expect([back.getFullYear(), back.getMonth(), back.getDate()]).toEqual([2026, 6, 9])

		LeadForm.computed.expectedCloseDateObj.set.call(ctx, null)
		expect(ctx.form.expectedCloseDate).toBeNull()
	})

	it('offers only pipelines that apply to leads', () => {
		const pipelines = [
			{ id: 'p-lead', title: 'Sales', propertyMappings: [{ schemaSlug: 'lead' }] },
			{ id: 'p-req', title: 'Requests', propertyMappings: [{ schemaSlug: 'request' }] },
		]
		const leadPipelines = LeadForm.computed.leadPipelines.call({ pipelines })
		expect(LeadForm.computed.pipelineOptions.call({ leadPipelines })).toEqual([{ value: 'p-lead', label: 'Sales' }])
	})
})

describe('stagePlacement', () => {
	const pipeline = { stages: [{ name: 'New', order: 0 }, { name: 'Won', order: '3' }, { name: 'Loose' }] }

	it('places a new stage with its order and an entry time', () => {
		const placement = LeadForm.methods.stagePlacement.call({ selectedPipeline: pipeline, lead: null }, 'Won')
		expect(placement.stageOrder).toBe(3)
		expect(Number.isNaN(Date.parse(placement.stageEnteredAt))).toBe(false)
	})

	it('keeps the entry time when the stage is unchanged and skips a missing order', () => {
		expect(LeadForm.methods.stagePlacement.call({ selectedPipeline: pipeline, lead: { stage: 'New' } }, 'New')).toEqual({ stageOrder: 0 })
		const loose = LeadForm.methods.stagePlacement.call({ selectedPipeline: pipeline, lead: { stage: 'New' } }, 'Loose')
		expect(Object.keys(loose)).toEqual(['stageEnteredAt'])
	})

	it('places nothing without a stage', () => {
		expect(LeadForm.methods.stagePlacement.call({ selectedPipeline: pipeline }, null)).toEqual({})
	})
})

describe('onSave', () => {
	/**
	 * A save context with the given form and validity.
	 *
	 * @param {object} form The form.
	 * @param {boolean} isValid Validity.
	 * @return {object}
	 */
	function saveContext(form, isValid = true) {
		return {
			form,
			isValid,
			lead: { stage: 'New' },
			selectedPipeline: { stages: [{ name: 'New', order: 1 }] },
			markSaveAttempted: vi.fn(),
			$emit: vi.fn(),
			stagePlacement(stage) {
				return LeadForm.methods.stagePlacement.call(this, stage)
			},
		}
	}

	it('marks the attempt and emits nothing when invalid', () => {
		const ctx = saveContext({ title: '' }, false)
		LeadForm.methods.onSave.call(ctx)
		expect(ctx.markSaveAttempted).toHaveBeenCalledTimes(1)
		expect(ctx.$emit).not.toHaveBeenCalled()
	})

	it('drops null and empty fields and adds the stage placement', () => {
		const form = {
			title: 'Deal',
			value: null,
			probability: null,
			currency: '',
			source: null,
			category: '',
			expectedCloseDate: null,
			client: null,
			contact: 'c-1',
			pipeline: 'p-1',
			stage: 'New',
			labels: [],
		}
		const ctx = saveContext(form)

		LeadForm.methods.onSave.call(ctx)

		expect(ctx.$emit).toHaveBeenCalledWith('save', {
			title: 'Deal',
			contact: 'c-1',
			pipeline: 'p-1',
			stage: 'New',
			labels: [],
			stageOrder: 1,
		})
		expect(form.value).toBeNull()
	})

	it('keeps a zero value and a stored probability', () => {
		const ctx = saveContext({ title: 'Deal', value: 0, probability: 40, pipeline: 'p-1', stage: null })
		LeadForm.methods.onSave.call(ctx)
		expect(ctx.$emit).toHaveBeenCalledWith('save', { title: 'Deal', value: 0, probability: 40, pipeline: 'p-1' })
	})
})
