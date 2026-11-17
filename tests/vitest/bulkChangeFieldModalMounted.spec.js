// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Change a field dialog mounted for real, for what its method tests do
 * not reach: what the template wires. The picked property decides which value
 * input is shown, Preview stays disabled until a field is picked, the preview
 * counts become badges, Confirm replaces Preview and commits, a refusal shows
 * in a note with Preview back, and closing or Cancel tell the parent to close.
 * HTTP, toasts and the event bus are mocked.
 *
 * @spec openspec/changes/clients-bulk-edit-and-undo/specs/client-bulk-changes/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({
	get: vi.fn(),
	post: vi.fn(),
}))
const bus = vi.hoisted(() => ({ emit: vi.fn() }))
const toasts = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
const schemaFetch = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/dialogs', () => toasts)
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('../../src/services/viewService.js', () => ({ getSchemaProperties: (...a) => schemaFetch(...a) }))
vi.mock('vue-material-design-icons/Check.vue', async () => {
	const { defineComponent, h } = await import('vue')
	return { default: defineComponent({ name: 'Check', render: () => h('i') }) }
})

/**
 * A stand-in component: a div named after the component that keeps its string
 * attributes, renders every slot, emits `click` unless disabled and emits a
 * picked value through `pick()`.
 *
 * @param {string} name Component name.
 * @return {Promise<object>} The component.
 */
async function standIn(name) {
	const { defineComponent, h } = await import('vue')
	return defineComponent({
		name,
		inheritAttrs: false,
		props: { disabled: { type: Boolean, default: false } },
		emits: ['click', 'closing', 'update:modelValue'],
		methods: {
			pick(value) {
				this.$emit('update:modelValue', value)
			},
		},
		render() {
			const attrs = { class: name, onClick: () => !this.disabled && this.$emit('click') }
			for (const [key, value] of Object.entries(this.$attrs)) {
				if (typeof value === 'string') {
					attrs[key] = value
				}
			}
			return h('div', attrs, Object.values(this.$slots).map((slot) => slot()))
		},
	})
}

vi.mock('@nextcloud/vue', async () => ({
	NcButton: await standIn('NcButton'),
	NcCheckboxRadioSwitch: await standIn('NcCheckboxRadioSwitch'),
	NcLoadingIcon: await standIn('NcLoadingIcon'),
	NcNoteCard: await standIn('NcNoteCard'),
	NcSelect: await standIn('NcSelect'),
	NcTextField: await standIn('NcTextField'),
}))
vi.mock('@conduction/nextcloud-vue', async () => ({
	CnDialog: await standIn('CnDialog'),
	CnStatusBadge: await standIn('CnStatusBadge'),
}))

const BulkChangeFieldModal = (await import('../../src/modals/BulkChangeFieldModal.vue')).default

const SCHEMA = {
	properties: {
		name: { type: 'string', title: 'Name' },
		industry: { type: 'string', title: 'Industry', enum: ['Healthcare', 'Retail'] },
		active: { type: 'boolean', title: 'Active' },
		employees: { type: 'integer', title: 'Employees' },
	},
}

const t = (app, text) => text
const n = (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count))

/**
 * Mount the dialog and let its schema load settle.
 *
 * @param {object} [props] Props over the defaults.
 * @return {Promise<object>} The wrapper.
 */
async function open(props = {}) {
	const wrapper = mount(BulkChangeFieldModal, {
		props: { selectedIds: ['a', 'b'], register: 'pipelinq', schema: 'client', schemaObject: SCHEMA, ...props },
		global: { mocks: { t, n } },
	})
	await flushPromises()
	return wrapper
}

/**
 * The stand-in with the given test id.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} name The stand-in's component name.
 * @param {string} testId Its data-testid.
 * @return {object|undefined} The component wrapper.
 */
function byTestId(wrapper, name, testId) {
	return wrapper.findAllComponents({ name }).find((c) => c.attributes('data-testid') === testId)
}

/**
 * Pick a property in the field select.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} id The property id.
 * @return {Promise<void>}
 */
async function pickField(wrapper, id) {
	byTestId(wrapper, 'NcSelect', 'bulk-change-property').vm.pick({ id })
	await flushPromises()
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('the field and value inputs', () => {
	it('shows a loading icon while the schema is fetched, then the field select', async () => {
		let resolve
		schemaFetch.mockReturnValue(new Promise((r) => { resolve = r }))
		const wrapper = mount(BulkChangeFieldModal, {
			props: { selectedIds: ['a'], schema: 'client' },
			global: { mocks: { t, n } },
		})
		await flushPromises()
		expect(wrapper.findAllComponents({ name: 'NcLoadingIcon' })).toHaveLength(1)
		expect(byTestId(wrapper, 'NcSelect', 'bulk-change-property')).toBeUndefined()

		resolve(SCHEMA)
		await flushPromises()

		expect(schemaFetch).toHaveBeenCalledWith('client')
		expect(byTestId(wrapper, 'NcSelect', 'bulk-change-property').attributes('inputlabel')).toBe('Field to change')
		expect(wrapper.findComponent({ name: 'CnDialog' }).attributes('eyebrow')).toBe('1 record selected')
	})

	it('offers the writable fields, sorted by label', async () => {
		const wrapper = await open()
		const select = byTestId(wrapper, 'NcSelect', 'bulk-change-property')
		expect(select.vm.$attrs.options).toEqual([
			{ id: 'active', label: 'Active' },
			{ id: 'employees', label: 'Employees' },
			{ id: 'industry', label: 'Industry' },
			{ id: 'name', label: 'Name' },
		])
		expect(select.vm.$attrs.modelValue).toBeNull()
		expect(wrapper.findComponent({ name: 'CnDialog' }).attributes('eyebrow')).toBe('2 records selected')
	})

	it('shows a text field until a field is picked, then the input that fits the field', async () => {
		const wrapper = await open()
		expect(byTestId(wrapper, 'NcTextField', 'bulk-change-value-text').attributes('type')).toBe('text')

		await pickField(wrapper, 'active')
		const toggle = byTestId(wrapper, 'NcCheckboxRadioSwitch', 'bulk-change-value-boolean')
		expect(toggle.text()).toBe('Active')
		expect(toggle.vm.$attrs.modelValue).toBe(false)
		expect(byTestId(wrapper, 'NcSelect', 'bulk-change-property').vm.$attrs.modelValue).toEqual({ id: 'active', label: 'Active' })

		await pickField(wrapper, 'industry')
		const enumSelect = byTestId(wrapper, 'NcSelect', 'bulk-change-value-enum')
		expect(enumSelect.vm.$attrs.options).toEqual(['Healthcare', 'Retail'])
		expect(enumSelect.attributes('inputlabel')).toBe('Industry')

		await pickField(wrapper, 'employees')
		const number = byTestId(wrapper, 'NcTextField', 'bulk-change-value-text')
		expect(number.attributes('type')).toBe('number')
		expect(number.attributes('label')).toBe('Employees')
	})

	it('keeps the value each input reports', async () => {
		const wrapper = await open()
		await pickField(wrapper, 'active')
		byTestId(wrapper, 'NcCheckboxRadioSwitch', 'bulk-change-value-boolean').vm.pick(true)
		await flushPromises()
		expect(wrapper.vm.value).toBe(true)

		await pickField(wrapper, 'industry')
		byTestId(wrapper, 'NcSelect', 'bulk-change-value-enum').vm.pick('Retail')
		await flushPromises()
		expect(wrapper.vm.value).toBe('Retail')
		expect(byTestId(wrapper, 'NcSelect', 'bulk-change-value-enum').vm.$attrs.modelValue).toBe('Retail')

		await pickField(wrapper, 'employees')
		byTestId(wrapper, 'NcTextField', 'bulk-change-value-text').vm.pick('12')
		await flushPromises()
		expect(byTestId(wrapper, 'NcTextField', 'bulk-change-value-text').vm.$attrs.modelValue).toBe('12')
	})

	it('clears the field when the select reports nothing', async () => {
		const wrapper = await open()
		await pickField(wrapper, 'name')
		byTestId(wrapper, 'NcSelect', 'bulk-change-property').vm.pick(null)
		await flushPromises()
		expect(wrapper.vm.property).toBe('')
	})
})

describe('preview and confirm', () => {
	it('keeps Preview disabled until a field is picked', async () => {
		const wrapper = await open()
		const preview = byTestId(wrapper, 'NcButton', 'bulk-change-preview')
		expect(preview.props('disabled')).toBe(true)
		await preview.trigger('click')
		expect(http.post).not.toHaveBeenCalled()

		await pickField(wrapper, 'name')
		expect(byTestId(wrapper, 'NcButton', 'bulk-change-preview').props('disabled')).toBe(false)
	})

	it('shows the counts after Preview and commits on Confirm', async () => {
		http.get.mockResolvedValue({ data: { '@self': { register: 3, schema: 4 } } })
		http.post
			.mockResolvedValueOnce({ data: { id: 11, processed: { counts: { applied: 1, skipped: 1 } } } })
			.mockResolvedValueOnce({ data: { id: 11, state: 'applied' } })
		const wrapper = await open()
		await pickField(wrapper, 'employees')
		byTestId(wrapper, 'NcTextField', 'bulk-change-value-text').vm.pick('7')

		await byTestId(wrapper, 'NcButton', 'bulk-change-preview').trigger('click')
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith('/apps/openregister/api/bulk-jobs', expect.objectContaining({
			parameters: { properties: { employees: 7 } },
			selection: { ids: ['a', 'b'] },
			register: 3,
			schema: 4,
		}))
		const badges = wrapper.findAllComponents({ name: 'CnStatusBadge' }).map((b) => b.attributes('label'))
		expect(badges).toEqual(['1 record will change', '1 already has this value'])
		expect(byTestId(wrapper, 'NcButton', 'bulk-change-preview')).toBeUndefined()
		const confirm = byTestId(wrapper, 'NcButton', 'bulk-change-confirm')
		expect(confirm.text()).toContain('Confirm')
		expect(confirm.props('disabled')).toBe(false)

		await confirm.trigger('click')
		await flushPromises()

		expect(http.post).toHaveBeenLastCalledWith('/apps/openregister/api/bulk-jobs/11/commit')
		expect(toasts.showSuccess).toHaveBeenCalledWith('1 record changed.')
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('hides the skipped badge and disables Confirm when nothing would change', async () => {
		http.get.mockResolvedValue({ data: { '@self': { register: 3, schema: 4 } } })
		http.post.mockResolvedValue({ data: { id: 12, processed: { counts: { applied: 0, skipped: 0 } } } })
		const wrapper = await open()
		await pickField(wrapper, 'name')

		await byTestId(wrapper, 'NcButton', 'bulk-change-preview').trigger('click')
		await flushPromises()

		const badges = wrapper.findAllComponents({ name: 'CnStatusBadge' }).map((b) => b.attributes('label'))
		expect(badges).toEqual(['0 records will change'])
		expect(byTestId(wrapper, 'NcButton', 'bulk-change-confirm').props('disabled')).toBe(true)
	})

	it('shows a refusal in a note and offers Preview again', async () => {
		http.get.mockResolvedValue({ data: { '@self': { register: 3, schema: 4 } } })
		http.post.mockRejectedValue({ response: { data: { message: 'Not allowed' } } })
		const wrapper = await open()
		await pickField(wrapper, 'name')

		await byTestId(wrapper, 'NcButton', 'bulk-change-preview').trigger('click')
		await flushPromises()

		expect(byTestId(wrapper, 'NcNoteCard', 'bulk-change-refusal').text()).toBe('Not allowed')
		expect(wrapper.find('[data-testid="bulk-change-summary"]').exists()).toBe(false)
		expect(byTestId(wrapper, 'NcButton', 'bulk-change-preview')).toBeDefined()
		expect(byTestId(wrapper, 'NcButton', 'bulk-change-confirm')).toBeUndefined()
	})
})

describe('closing', () => {
	it('tells the parent to close when the dialog closes or Cancel is clicked', async () => {
		const wrapper = await open()
		wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		const cancel = wrapper.findAllComponents({ name: 'NcButton' }).find((b) => b.text().includes('Cancel'))
		await cancel.trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})
