// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pipeline form driven through its rendered controls rather than its
 * methods: the title, description, view, default and totals fields, each
 * property mapping, each stage's fields, reorder and remove buttons, the drag
 * end, and the Cancel / Save actions.
 *
 * The Nextcloud controls and the draggable list are replaced by small
 * stand-ins that render their slots and emit the same events.
 *
 * @spec openspec/specs/pipeline/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})

/**
 * A stand-in that renders its slots and can emit a new value.
 *
 * @param {string} name The component name.
 * @param {string} tag The element to render.
 * @return {object} The component.
 */
function standIn(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		emits: ['update:modelValue', 'click', 'closing', 'end'],
		methods: {
			pick(value) {
				this.$emit('update:modelValue', value)
			},
		},
		render() {
			return h(tag, { 'data-label': this.$attrs.label, 'aria-label': this.$attrs['aria-label'] }, [
				this.$slots.icon?.(),
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	}
}

vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: standIn('CnDialog') }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: standIn('NcButton', 'button'),
	NcCheckboxRadioSwitch: standIn('NcCheckboxRadioSwitch'),
	NcSelect: standIn('NcSelect'),
	NcTextField: standIn('NcTextField'),
}))
vi.mock('vuedraggable', () => ({
	default: {
		name: 'Draggable',
		props: ['modelValue'],
		emits: ['end', 'update:modelValue'],
		render() {
			return h('div', (this.modelValue || []).map((element, index) => this.$slots.item({ element, index })))
		},
	},
}))
vi.mock('../../src/services/viewService.js', () => ({ getViews: vi.fn() }))

const views = await import('../../src/services/viewService.js')
const { default: PipelineFormDialog } = await import('../../src/dialogs/PipelineFormDialog.vue')

/**
 * Mount the form and let it load its views.
 *
 * @param {object|null} pipeline The pipeline being edited.
 * @return {Promise<object>} The wrapper.
 */
async function mountForm(pipeline = null) {
	const w = mount(PipelineFormDialog, { props: { pipeline }, global: { mocks: { t: globalThis.t } } })
	await flushPromises()
	return w
}

/**
 * The stand-ins of a kind with the given label, in render order.
 *
 * @param {object} w The wrapper.
 * @param {string} name The component name.
 * @param {string} label The label.
 * @return {Array<object>} The stand-ins.
 */
function labelled(w, name, label) {
	return w.findAllComponents({ name }).filter((c) => c.vm.$attrs.label === label)
}

/**
 * The button with the given text or aria-label.
 *
 * @param {object} w The wrapper.
 * @param {string} text The text or aria-label.
 * @return {object} The button stand-in.
 */
function button(w, text) {
	const found = w.findAllComponents({ name: 'NcButton' }).find((b) => b.text().trim() === text || b.vm.$attrs['aria-label'] === text)
	if (!found) {
		throw new Error(`no button ${text}`)
	}
	return found
}

beforeEach(() => {
	vi.clearAllMocks()
	views.getViews.mockResolvedValue([{ id: 'v1', name: 'Open leads' }])
})

describe('PipelineFormDialog controls', () => {
	it('writes the pipeline fields and a property mapping from the rendered controls', async () => {
		const w = await mountForm()

		labelled(w, 'NcTextField', 'Title')[0].vm.pick('Sales')
		labelled(w, 'NcTextField', 'Description')[0].vm.pick('Inbound deals')
		w.findComponent({ name: 'NcSelect' }).vm.pick('v1')
		w.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.pick(true)
		labelled(w, 'NcTextField', 'Totals label')[0].vm.pick('Value')
		button(w, 'Add mapping').vm.$emit('click')
		await flushPromises()
		labelled(w, 'NcTextField', 'Schema slug')[0].vm.pick('lead')
		labelled(w, 'NcTextField', 'Column property')[0].vm.pick('phase')
		labelled(w, 'NcTextField', 'Totals property')[0].vm.pick('')
		labelled(w, 'NcTextField', 'Totals property')[0].vm.pick('amount')

		expect(w.vm.form).toMatchObject({ title: 'Sales', description: 'Inbound deals', viewId: 'v1', isDefault: true, totalsLabel: 'Value' })
		expect(w.vm.form.propertyMappings).toEqual([{ schemaSlug: 'lead', columnProperty: 'phase', totalsProperty: 'amount' }])

		button(w, 'Remove this property mapping').vm.$emit('click')
		await flushPromises()
		expect(w.vm.form.propertyMappings).toEqual([])
	})

	it('writes each stage field from its row', async () => {
		const w = await mountForm()
		button(w, 'Add stage').vm.$emit('click')
		await flushPromises()

		labelled(w, 'NcTextField', 'Stage name')[0].vm.pick('Qualified')
		labelled(w, 'NcTextField', 'Probability %')[0].vm.pick('40')
		labelled(w, 'NcTextField', 'Limit')[0].vm.pick('5')
		const color = w.find('input[type="color"]')
		color.element.value = '#ff0000'
		await color.trigger('input')
		const [closed, won] = w.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).slice(1)
		closed.vm.pick(true)
		won.vm.pick(true)
		await flushPromises()

		expect(w.vm.form.stages[0]).toMatchObject({ name: 'Qualified', probability: 40, maxItems: 5, color: '#ff0000', isClosed: true, isWon: true })
		expect(labelled(w, 'NcTextField', 'Limit')).toHaveLength(0)

		labelled(w, 'NcTextField', 'Probability %')[0].vm.pick('')
		closed.vm.pick(false)
		await flushPromises()
		labelled(w, 'NcTextField', 'Limit')[0].vm.pick('')
		expect([w.vm.form.stages[0].probability, w.vm.form.stages[0].maxItems]).toEqual([null, null])
		expect(w.find('.error-text').text()).toBe('A Won stage must also be marked as Closed')
	})

	it('moves, drags and removes stages from their buttons and the drag end', async () => {
		const w = await mountForm({ id: 'p1', title: 'Sales', stages: [{ name: 'A', order: 0 }, { name: 'B', order: 1 }, { name: 'C', order: 2 }] })

		button(w, 'Move stage C up').vm.$emit('click')
		await flushPromises()
		expect(w.vm.form.stages.map((s) => s.name)).toEqual(['A', 'C', 'B'])
		button(w, 'Move stage A down').vm.$emit('click')
		await flushPromises()
		expect(w.vm.form.stages.map((s) => s.name)).toEqual(['C', 'A', 'B'])

		w.vm.form.stages.reverse()
		w.findComponent({ name: 'Draggable' }).vm.$emit('end')
		await flushPromises()
		expect(w.vm.form.stages.map((s) => [s.name, s.order])).toEqual([['B', 0], ['A', 1], ['C', 2]])

		button(w, 'Remove stage A').vm.$emit('click')
		await flushPromises()
		expect(w.vm.form.stages.map((s) => s.name)).toEqual(['B', 'C'])
	})

	it('saves from the Save button and cancels from Cancel and the dialog closing', async () => {
		const w = await mountForm({ id: 'p1', title: 'Sales', stages: [{ name: 'Open', order: 0 }] })

		button(w, 'Save').vm.$emit('click')
		button(w, 'Cancel').vm.$emit('click')
		w.findComponent({ name: 'CnDialog' }).vm.$emit('closing')

		expect(w.emitted('save')).toHaveLength(1)
		expect(w.emitted('save')[0][0]).toMatchObject({ title: 'Sales' })
		expect(w.emitted('cancel')).toHaveLength(2)
	})
})
