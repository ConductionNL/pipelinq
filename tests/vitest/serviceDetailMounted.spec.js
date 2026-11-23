// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The service page, mounted with the library's page parts drawing their
 * slots: the details and rules cards, the steps table or its one-step note,
 * the inline steps editor, the new-service form, and the delete and edit
 * dialogs (the object store doubled).
 *
 * @spec openspec/changes/archive/2026-06-14-appointment-booking-11-admin-ui/tasks.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({ value: null }))
const toasts = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/dialogs', () => toasts)
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (w, k) => vars[k] ?? w) }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store.value }))

const named = vi.hoisted(() => (name, tag = 'div') => async () => {
	const { h } = await import('vue')
	return {
		default: {
			name,
			inheritAttrs: false,
			props: ['modelValue', 'durationMinutes', 'name', 'service'],
			emits: ['update:modelValue', 'confirm', 'cancel', 'save', 'catalogue'],
			render() {
				return h(tag, { 'data-stub': name, 'data-name': this.name, 'data-duration': this.durationMinutes, 'data-rows': Array.isArray(this.modelValue) ? this.modelValue.length : undefined })
			},
		},
	}
})
vi.mock('../../src/components/bookings/ServiceStepsEditor.vue', named('ServiceStepsEditor'))
vi.mock('../../src/dialogs/DeleteServiceDialog.vue', named('DeleteServiceDialog'))
vi.mock('../../src/views/bookings/ServiceForm.vue', named('ServiceForm', 'form'))
vi.mock('vue-material-design-icons/Pencil.vue', named('Pencil', 'i'))
vi.mock('vue-material-design-icons/PencilOutline.vue', named('PencilOutline', 'i'))

vi.mock('@nextcloud/vue', async () => {
	const { h } = await import('vue')
	return {
		NcButton: {
			name: 'NcButton',
			props: ['disabled'],
			emits: ['click'],
			render() {
				return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, [this.$slots.icon?.(), this.$slots.default?.()])
			},
		},
	}
})
vi.mock('@conduction/nextcloud-vue', async () => {
	const { h } = await import('vue')
	/** A part that draws every slot it is given, the form dialog's field slot with the item's steps. */
	const part = (name) => ({
		name,
		props: ['title', 'label', 'item', 'colorKey'],
		emits: ['confirm', 'close', 'refresh'],
		render() {
			return h('div', { 'data-part': name, 'data-title': this.title, 'data-label': this.label }, Object.entries(this.$slots).map(([slot, fn]) => h('div', { 'data-slot': slot }, fn({
				value: this.item?.multiStep,
				updateField: vi.fn(),
			}))))
		},
	})
	return {
		...Object.fromEntries(['CnActionsMenu', 'CnAuditTrailCard', 'CnDetailCard', 'CnDetailPage', 'CnFormDialog', 'CnStatusBadge', 'CnTab', 'CnTabs'].map((n) => [n, part(n)])),
		useObjectSubscription: () => {},
	}
})

const translate = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (w, k) => vars[k] ?? w)
globalThis.t = translate
const { default: ServiceDetail } = await import('../../src/views/bookings/ServiceDetail.vue')

/**
 * A store holding one service and its step products.
 *
 * @param {object|null} service The stored service.
 * @param {object|null} schema The service schema.
 * @return {object} The store.
 */
function objectStore(service, schema = null) {
	return {
		objectTypeRegistry: { service: { register: 'pipelinq', schema: 'appointmentService' } },
		loading: {},
		getObject: vi.fn(() => service),
		fetchObject: vi.fn(async (type, id) => (type === 'product' ? { id, name: 'Product ' + id } : service)),
		getSchema: vi.fn(() => schema),
		fetchSchema: vi.fn(async () => schema),
		saveObject: vi.fn(async () => ({ id: 's-1' })),
		getError: vi.fn(() => null),
	}
}

/**
 * Mount the page for an id.
 *
 * @param {string} id The service id.
 * @return {Promise<object>} The wrapper.
 */
async function page(id) {
	const wrapper = mount(ServiceDetail, { props: { id }, global: { mocks: { t: translate } } })
	await flushPromises()
	return wrapper
}

const SERVICE = {
	name: 'Passport',
	status: 'active',
	durationMinutes: 90,
	price: 75,
	currency: 'EUR',
	bufferBeforeMinutes: 5,
	bufferAfterMinutes: 10,
	bookableOnline: true,
	description: 'Apply for a passport.',
	requiresDeposit: true,
	depositAmount: 10,
	noShowFee: 15,
	cancellationPolicy: 'charge-deposit',
	cancellationHoursBefore: 24,
	requiredSkills: ['id', 'photo'],
	multiStep: [
		{ productId: 'p1', quantity: 2, durationMinutes: 30, resourceType: 'staff', skillRequired: 'id', allowGap: true },
		{ productId: '', durationMinutes: 60, resourceType: '', skillRequired: '', allowGap: false },
	],
}

/**
 * The text of each info field's value inside a card.
 *
 * @param {object} card The card wrapper.
 * @return {Array<string>} The values.
 */
function values(card) {
	return card.findAll('.info-grid .info-field').map((field) => field.find('span').exists() ? field.find('span').text().replace(/\s+/g, ' ') : '')
}

beforeEach(() => {
	toasts.showError.mockReset()
	toasts.showSuccess.mockReset()
})

describe('the service page, mounted', () => {
	it('draws the new-service form for id "new" and reads nothing', async () => {
		store.value = objectStore(null)

		const wrapper = await page('new')

		expect(wrapper.find('h2').text()).toBe('New service')
		expect(wrapper.find('[data-stub="ServiceForm"]').exists()).toBe(true)
		expect(store.value.fetchObject).not.toHaveBeenCalled()
	})

	it('draws the details, the rules and the steps with their product names', async () => {
		store.value = objectStore(SERVICE)

		const wrapper = await page('s-1')
		const cards = wrapper.findAll('[data-part="CnDetailCard"]')

		const details = values(cards[0])
		expect(details[0]).toBe('Passport')
		expect(details[2]).toBe('1h 30min')
		expect(details[4]).toBe('5 / 10 min')
		expect(details[5]).toBe('Yes')
		expect(cards[0].find('.info-field--full p').text()).toBe('Apply for a passport.')
		const rules = values(cards[1])
		expect(rules[0]).toBe('Yes')
		expect(rules[3]).toBe('Charge deposit')
		expect(rules[4]).toBe('24 hours')
		expect(rules[5]).toBe('id, photo')
		expect(wrapper.find('[data-part="CnStatusBadge"]').attributes('data-label')).toBe('Active')

		const rows = wrapper.findAll('[data-testid="service-composition"] tbody tr').map((tr) => tr.findAll('td').map((td) => td.text()))
		expect(rows[0]).toEqual(['1', 'Product p1', '2', '30 min', 'Staff', 'id', 'Yes'])
		expect(rows[1]).toEqual(['2', '-', '-', '60 min', '-', '-', 'No'])
		expect(store.value.fetchObject).toHaveBeenCalledWith('product', 'p1')
	})

	it('draws a plain service: no description, defaults, and the one-step note', async () => {
		store.value = objectStore({ name: '', status: 'retired', durationMinutes: 60, bookableOnline: false })

		const wrapper = await page('s-2')
		const cards = wrapper.findAll('[data-part="CnDetailCard"]')

		const details = values(cards[0])
		expect(details[0]).toBe('-')
		expect(details[2]).toBe('1h')
		expect(details[4]).toBe('0 / 0 min')
		expect(details[5]).toBe('No')
		expect(cards[0].find('.info-field--full').exists()).toBe(false)
		const rules = values(cards[1])
		expect(rules[0]).toBe('No')
		expect(rules[1]).toBe('not set')
		expect(rules[3]).toBe('Free')
		expect(rules[5]).toBe('-')
		expect(wrapper.find('[data-part="CnStatusBadge"]').attributes('data-label')).toBe('retired')
		expect(wrapper.find('[data-testid="service-composition"] .section-empty').text()).toBe('This service has one step.')
	})

	it('edits the steps inline: the editor replaces the table, cancel brings it back', async () => {
		store.value = objectStore(SERVICE)

		const wrapper = await page('s-1')
		const composition = () => wrapper.find('[data-testid="service-composition"]')
		await composition().find('button').trigger('click')

		expect(composition().find('[data-stub="ServiceStepsEditor"]').attributes('data-rows')).toBe('2')
		expect(composition().find('[data-stub="ServiceStepsEditor"]').attributes('data-duration')).toBe('90')
		expect(composition().find('table').exists()).toBe(false)

		const [cancel] = composition().findAll('button')
		expect(cancel.text()).toBe('Cancel')
		await cancel.trigger('click')
		expect(composition().find('table').exists()).toBe(true)
	})

	it('saves the edited steps and leaves the editor', async () => {
		store.value = objectStore(SERVICE)

		const wrapper = await page('s-1')
		const composition = () => wrapper.find('[data-testid="service-composition"]')
		await composition().find('button').trigger('click')
		await composition().findAll('button')[1].trigger('click')
		await flushPromises()

		expect(store.value.saveObject).toHaveBeenCalledWith('appointmentService', expect.objectContaining({ id: 's-1', multiStep: SERVICE.multiStep }))
		expect(toasts.showSuccess).toHaveBeenCalledWith('Steps saved.')
		expect(composition().find('[data-stub="ServiceStepsEditor"]').exists()).toBe(false)
	})

	it('opens the delete dialog from the header menu', async () => {
		store.value = objectStore(SERVICE)

		const wrapper = await page('s-1')
		wrapper.vm.headerActions[0].onSelect()
		await flushPromises()

		expect(wrapper.find('[data-stub="DeleteServiceDialog"]').attributes('data-name')).toBe('Passport')
	})

	it('opens the edit dialog with the steps editor once the schema loads, and says so when it does not', async () => {
		store.value = objectStore(SERVICE, { properties: {} })
		let wrapper = await page('s-1')
		await wrapper.find('[data-slot="actions"] button').trigger('click')
		await flushPromises()

		const dialog = wrapper.find('[data-part="CnFormDialog"]')
		expect(dialog.exists()).toBe(true)
		expect(dialog.find('[data-stub="ServiceStepsEditor"]').attributes('data-rows')).toBe('2')
		expect(dialog.find('[data-stub="ServiceStepsEditor"]').attributes('data-duration')).toBe('90')

		store.value = objectStore(SERVICE, null)
		wrapper = await page('s-1')
		await wrapper.find('[data-slot="actions"] button').trigger('click')
		await flushPromises()

		expect(toasts.showError).toHaveBeenCalledWith('Could not load the service form.')
		expect(wrapper.find('[data-part="CnFormDialog"]').exists()).toBe(false)
	})
})
