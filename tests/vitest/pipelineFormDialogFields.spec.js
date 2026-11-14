// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pipeline form as an admin fills it in: every field writes back to the
 * pipeline it saves, stage rows move and go, mappings come and go, and both
 * Cancel and closing the dialog cancel.
 *
 * Only the view service and the component libraries are replaced; the stubs
 * render one native element per field so the form can be driven as a user does.
 *
 * @spec openspec/specs/pipeline/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) =>
		String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})

vi.mock('@conduction/nextcloud-vue', async () => {
	const { h } = await import('vue')
	return {
		CnDialog: {
			name: 'CnDialog',
			emits: ['closing'],
			render() {
				return h('div', [
					h('button', { 'data-testid': 'dialog-close', onClick: () => this.$emit('closing') }),
					this.$slots.default?.(),
					this.$slots.actions?.(),
				])
			},
		},
	}
})
vi.mock('@nextcloud/vue', async () => {
	const { h } = await import('vue')
	return {
		NcButton: {
			name: 'NcButton',
			props: ['disabled', 'variant', 'ariaLabel'],
			emits: ['click'],
			render() {
				return h(
					'button',
					{ 'aria-label': this.ariaLabel, disabled: this.disabled, onClick: () => this.$emit('click') },
					this.$slots.default?.(),
				)
			},
		},
		NcCheckboxRadioSwitch: {
			name: 'NcCheckboxRadioSwitch',
			props: ['modelValue', 'disabled'],
			emits: ['update:modelValue'],
			render() {
				return h('label', [
					h('input', {
						type: 'checkbox',
						checked: Boolean(this.modelValue),
						disabled: this.disabled,
						onChange: (e) => this.$emit('update:modelValue', e.target.checked),
					}),
					this.$slots.default?.(),
				])
			},
		},
		NcSelect: { name: 'NcSelect', props: ['modelValue', 'options'], render: () => h('div') },
		NcTextField: {
			name: 'NcTextField',
			props: ['modelValue', 'label', 'helperText'],
			emits: ['update:modelValue'],
			render() {
				return h('input', {
					'data-label': this.label,
					'data-helper': this.helperText,
					value: this.modelValue,
					onInput: (e) => this.$emit('update:modelValue', e.target.value),
				})
			},
		},
	}
})
// vuedraggable v4: render the item slot once per element of the bound list.
vi.mock('vuedraggable', async () => {
	const { h } = await import('vue')
	return {
		default: {
			name: 'Draggable',
			props: ['modelValue'],
			render() {
				return h(
					'div',
					(this.modelValue || []).map((element, index) =>
						h('div', { class: 'stage-item' }, this.$slots.item?.({ element, index })),
					),
				)
			},
		},
	}
})
vi.mock('../../src/services/viewService.js', () => ({ getViews: vi.fn(() => Promise.resolve([])) }))

const { default: PipelineFormDialog } = await import('../../src/dialogs/PipelineFormDialog.vue')

const PIPELINE = {
	id: 'p1',
	title: 'Sales',
	stages: [
		{ name: 'New', order: 0, probability: 10 },
		{ name: 'Proposal', order: 1, probability: 50 },
		{ name: 'Won', order: 2, isClosed: true, isWon: true, probability: 100 },
	],
	propertyMappings: [{ schemaSlug: 'lead', columnProperty: 'stage', totalsProperty: 'value' }],
}

/**
 * Mount the form.
 *
 * @param {object|null} pipeline The pipeline to edit, or null for a new one.
 * @return {Promise<object>} The wrapper.
 */
async function mountForm(pipeline = null) {
	const wrapper = mount(PipelineFormDialog, {
		props: { pipeline: pipeline ? structuredClone(pipeline) : null },
		global: { mocks: { t: globalThis.t } },
	})
	await flushPromises()
	return wrapper
}

/**
 * The text fields with this label.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} label The label.
 * @return {Array<object>} The inputs.
 */
function fields(wrapper, label) {
	return wrapper.findAll(`input[data-label="${label}"]`)
}

/**
 * The button with this accessible name or text.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} name The name.
 * @return {object} The button.
 */
function button(wrapper, name) {
	return wrapper.findAll('button').find((b) => b.attributes('aria-label') === name || b.text() === name)
}

/**
 * Save and return the pipeline the form emits.
 *
 * @param {object} wrapper The wrapper.
 * @return {Promise<object>} The saved pipeline.
 */
async function saved(wrapper) {
	await button(wrapper, 'Save').trigger('click')
	const emitted = wrapper.emitted('save')
	expect(emitted).toHaveLength(1)
	return emitted[0][0]
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('PipelineFormDialog fields', () => {
	it('saves the title, description and totals label the admin typed', async () => {
		const wrapper = await mountForm(PIPELINE)

		await fields(wrapper, 'Title')[0].setValue('Enterprise sales')
		await fields(wrapper, 'Description')[0].setValue('Big deals')
		await fields(wrapper, 'Totals label')[0].setValue('EUR')

		const pipeline = await saved(wrapper)
		expect(pipeline.title).toBe('Enterprise sales')
		expect(pipeline.description).toBe('Big deals')
		expect(pipeline.totalsLabel).toBe('EUR')
	})

	it('shows the missing title on the field and refuses to save', async () => {
		const wrapper = await mountForm(PIPELINE)

		await fields(wrapper, 'Title')[0].setValue('  ')

		expect(fields(wrapper, 'Title')[0].attributes('data-helper')).toBe('Pipeline title is required')
		expect(button(wrapper, 'Save').attributes('disabled')).toBeDefined()
	})

	it('edits, adds and removes property mappings', async () => {
		const wrapper = await mountForm(PIPELINE)
		expect(wrapper.text()).not.toContain('No mappings yet')

		await fields(wrapper, 'Schema slug')[0].setValue('request')
		await fields(wrapper, 'Column property')[0].setValue('status')
		await fields(wrapper, 'Totals property')[0].setValue('')
		await button(wrapper, 'Add mapping').trigger('click')
		await fields(wrapper, 'Schema slug')[1].setValue('lead')

		let pipeline = await saved(wrapper)
		expect(pipeline.propertyMappings.map((m) => m.schemaSlug)).toEqual(['request', 'lead'])
		expect(pipeline.propertyMappings[0].columnProperty).toBe('status')
		expect(pipeline.propertyMappings[0]).not.toHaveProperty('totalsProperty', 'value')

		await button(wrapper, 'Remove this property mapping').trigger('click')
		await button(wrapper, 'Remove this property mapping').trigger('click')
		expect(wrapper.text()).toContain('No mappings yet')
		await button(wrapper, 'Save').trigger('click')
		pipeline = wrapper.emitted('save')[1][0]
		expect(pipeline.propertyMappings).toEqual([])
	})
})

describe('PipelineFormDialog stages', () => {
	it('saves the name, probability, limit and colour of a stage', async () => {
		const wrapper = await mountForm(PIPELINE)

		await fields(wrapper, 'Stage name')[0].setValue('Lead in')
		await fields(wrapper, 'Probability %')[0].setValue('20')
		await fields(wrapper, 'Probability %')[1].setValue('')
		await fields(wrapper, 'Limit')[0].setValue('5')
		await wrapper.find('#stage-color-0').setValue('#ff0000')

		const [first, second] = (await saved(wrapper)).stages
		expect(first).toMatchObject({ name: 'Lead in', probability: 20, maxItems: 5, color: '#ff0000' })
		expect(second.name).toBe('Proposal')
		expect(second.probability ?? null).toBeNull()
	})

	it('offers a limit on open stages only', async () => {
		const wrapper = await mountForm(PIPELINE)

		expect(fields(wrapper, 'Limit')).toHaveLength(2)
	})

	it('shows a stage error on its own row', async () => {
		const wrapper = await mountForm(PIPELINE)

		await fields(wrapper, 'Probability %')[1].setValue('150')

		expect(fields(wrapper, 'Probability %')[1].attributes('data-helper')).toBe('Probability must be between 0 and 100')
		expect(fields(wrapper, 'Probability %')[0].attributes('data-helper')).toBe('')
		expect(button(wrapper, 'Save').attributes('disabled')).toBeDefined()
	})

	it('moves a stage up and down with its buttons and renumbers them', async () => {
		const wrapper = await mountForm(PIPELINE)
		expect(button(wrapper, 'Move stage New up').attributes('disabled')).toBeDefined()
		expect(button(wrapper, 'Move stage Won down').attributes('disabled')).toBeDefined()

		await button(wrapper, 'Move stage New down').trigger('click')
		await button(wrapper, 'Move stage Won up').trigger('click')

		const stages = (await saved(wrapper)).stages
		expect(stages.map((s) => s.name)).toEqual(['Proposal', 'Won', 'New'])
		expect(stages.map((s) => s.order)).toEqual([0, 1, 2])
	})

	it('closes a stage with its switch, which drops its limit field', async () => {
		const wrapper = await mountForm(PIPELINE)
		const closedSwitch = wrapper.findAll('.stage-item')[1].findAll('input[type="checkbox"]')[0]

		await closedSwitch.setValue(true)

		expect(fields(wrapper, 'Limit')).toHaveLength(1)
		expect((await saved(wrapper)).stages[1].isClosed).toBe(true)
	})

	it('removes a stage and refuses a pipeline whose stages are all closed', async () => {
		const wrapper = await mountForm(PIPELINE)

		await button(wrapper, 'Remove stage New').trigger('click')
		await button(wrapper, 'Remove stage Proposal').trigger('click')

		expect(wrapper.find('.error-text').text()).toBe('Pipeline must have at least one non-closed stage')
		expect(button(wrapper, 'Save').attributes('disabled')).toBeDefined()
	})

	it('starts a new pipeline without stages and adds one with its button', async () => {
		const wrapper = await mountForm()
		expect(wrapper.text()).toContain('No stages yet. Add at least one stage.')

		await button(wrapper, 'Add stage').trigger('click')
		await fields(wrapper, 'Stage name')[0].setValue('New')
		await fields(wrapper, 'Title')[0].setValue('Support')

		await button(wrapper, 'Create').trigger('click')
		expect(wrapper.emitted('save')[0][0].stages.map((s) => s.name)).toEqual(['New'])
	})
})

describe('PipelineFormDialog cancelling', () => {
	it('cancels from the Cancel button and from closing the dialog', async () => {
		const wrapper = await mountForm(PIPELINE)

		await button(wrapper, 'Cancel').trigger('click')
		await wrapper.find('[data-testid="dialog-close"]').trigger('click')

		expect(wrapper.emitted('cancel')).toHaveLength(2)
		expect(wrapper.emitted('save')).toBeUndefined()
	})
})
