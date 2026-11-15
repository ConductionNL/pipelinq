// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The resource page, mounted with the library's page parts drawing their
 * slots: the facts card, the working-hours and vacation tables or their empty
 * notes, the new-resource form, the delete dialog and the edit dialog with its
 * hours and vacation editors (store and axios doubled).
 *
 * @spec openspec/changes/archive/2026-06-14-appointment-booking-11-admin-ui/tasks.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn() }))
const store = vi.hoisted(() => ({ value: null }))
const toasts = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => toasts)
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, params = {}) => path.replace(/\{(\w+)\}/g, (m, k) => params[k] ?? m) }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (w, k) => vars[k] ?? w) }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store.value }))

const named = vi.hoisted(() => (name, tag = 'div') => async () => {
	const { h } = await import('vue')
	return {
		default: {
			name,
			inheritAttrs: false,
			props: ['modelValue', 'error', 'name', 'resource', 'title', 'label', 'value', 'caption'],
			emits: ['update:modelValue', 'confirm', 'cancel', 'save'],
			render() {
				return h(tag, { 'data-stub': name, 'data-name': this.name, 'data-rows': Array.isArray(this.modelValue) ? this.modelValue.length : undefined, 'data-value': this.value, 'data-caption': this.caption })
			},
		},
	}
})
vi.mock('../../src/components/bookings/ResourceHoursEditor.vue', named('ResourceHoursEditor'))
vi.mock('../../src/components/bookings/ResourceVacationsEditor.vue', named('ResourceVacationsEditor'))
vi.mock('../../src/components/KpiFigure.vue', named('KpiFigure'))
vi.mock('../../src/dialogs/DeleteResourceDialog.vue', named('DeleteResourceDialog'))
vi.mock('../../src/views/bookings/ResourceForm.vue', named('ResourceForm', 'form'))
vi.mock('vue-material-design-icons/Pencil.vue', named('Pencil', 'i'))

vi.mock('@nextcloud/vue', async () => {
	const { h } = await import('vue')
	return { NcButton: { name: 'NcButton', emits: ['click'], render() { return h('button', { onClick: () => this.$emit('click') }, [this.$slots.icon?.(), this.$slots.default?.()]) } } }
})
vi.mock('@conduction/nextcloud-vue', async () => {
	const { h } = await import('vue')
	/** A part that draws every slot it is given, the form dialog's field slots with a value. */
	const part = (name) => ({
		name,
		inheritAttrs: false,
		props: ['title', 'label', 'item'],
		emits: ['confirm', 'close'],
		render() {
			return h('div', { 'data-part': name, 'data-title': this.title }, Object.entries(this.$slots).map(([slot, fn]) => h('div', { 'data-slot': slot }, fn({
				value: slot === 'field-workingHours' ? this.item?.workingHours : undefined,
				error: slot === 'field-vacations' ? 'bad' : '',
				updateField: vi.fn(),
			}))))
		},
	})
	return {
		...Object.fromEntries(['CnAuditTrailCard', 'CnDetailCard', 'CnDetailPage', 'CnFormDialog', 'CnStatusBadge', 'CnTab', 'CnTabs'].map((n) => [n, part(n)])),
		useObjectSubscription: () => {},
	}
})

const translate = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (w, k) => vars[k] ?? w)
const { default: ResourceDetail } = await import('../../src/views/bookings/ResourceDetail.vue')

/**
 * A store holding one resource.
 *
 * @param {object|null} resource The stored resource.
 * @param {object|null} schema The resource schema.
 * @return {object} The store.
 */
function objectStore(resource, schema = null) {
	return {
		objectTypeRegistry: { resource: { register: 'pipelinq', schema: 'appointmentResource' } },
		loading: {},
		getObject: vi.fn(() => resource),
		fetchObject: vi.fn(async () => resource),
		getSchema: vi.fn(() => schema),
		fetchSchema: vi.fn(async () => schema),
	}
}

/**
 * Mount the page for an id.
 *
 * @param {string} id The resource id.
 * @return {Promise<object>} The wrapper.
 */
async function page(id) {
	const wrapper = mount(ResourceDetail, { props: { id }, global: { mocks: { t: translate } } })
	await flushPromises()
	return wrapper
}

const RESOURCE = {
	name: 'Desk 1',
	type: 'staff',
	status: 'active',
	bookable: true,
	maxConcurrent: 2,
	skills: ['woo', 'bezwaar'],
	userId: 'ann',
	calendarSyncId: 'cal-1',
	workingHours: [{ day: 'monday', openTime: '09:00', closeTime: '17:00' }, { day: '', openTime: '', closeTime: '' }],
	vacations: [{ startDate: '2026-12-24', endDate: 'soon', label: 'Christmas' }, { startDate: '', endDate: '', label: '' }],
}

beforeEach(() => {
	http.get.mockReset()
	toasts.showError.mockReset()
})

describe('the resource page, mounted', () => {
	it('draws the new-resource form for id "new" and reads nothing', async () => {
		store.value = objectStore(null)

		const wrapper = await page('new')

		expect(wrapper.find('h2').text()).toBe('New resource')
		expect(wrapper.find('[data-stub="ResourceForm"]').exists()).toBe(true)
		expect(wrapper.find('[data-part="CnDetailPage"]').exists()).toBe(false)
		expect(store.value.fetchObject).not.toHaveBeenCalled()
	})

	it('draws the facts, the hours and the vacations of a stored resource', async () => {
		store.value = objectStore(RESOURCE)
		http.get.mockImplementation(async (url) => (url.includes('/profile')
			? { data: { function: 'Adviseur', department: 'Woo' } }
			: { data: { bookingsThisWeek: 4, bookedShare: 0.5 } }))

		const wrapper = await page('r-1')

		const facts = wrapper.findAll('.resource-facts__value').map((v) => v.text())
		expect(facts).toEqual(['Desk 1', 'Staff', '', 'Yes', '2', 'woo, bezwaar', 'ann', 'cal-1'])
		const tables = wrapper.findAll('table.viewTable')
		expect(tables).toHaveLength(2)
		expect(tables[0].findAll('tbody tr').map((tr) => tr.findAll('td').map((td) => td.text()))).toEqual([['Monday', '09:00', '17:00'], ['-', '-', '-']])
		const vacationRows = tables[1].findAll('tbody tr').map((tr) => tr.findAll('td').map((td) => td.text()))
		expect(vacationRows[0][1]).toBe('soon')
		expect(vacationRows[0][2]).toBe('Christmas')
		expect(vacationRows[1]).toEqual(['-', '-', '-'])
		expect(wrapper.find('[data-stub="KpiFigure"]').attributes('data-value')).toBe('4')
	})

	it('says when a resource has no hours or vacations, and draws its defaults', async () => {
		store.value = objectStore({ name: '', status: 'inactive', bookable: false })
		http.get.mockResolvedValue({ data: null })

		const wrapper = await page('r-2')

		const empty = wrapper.findAll('.section-empty').map((e) => e.text())
		expect(empty).toEqual(['No working hours configured.', 'No vacations recorded.'])
		const facts = wrapper.findAll('.resource-facts__value').map((v) => v.text())
		expect(facts).toEqual(['-', '-', '', 'No', '1', '-', '-', '-'])
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('opens the delete dialog from the header menu', async () => {
		store.value = objectStore(RESOURCE)
		http.get.mockResolvedValue({ data: {} })

		const wrapper = await page('r-1')
		expect(wrapper.find('[data-stub="DeleteResourceDialog"]').exists()).toBe(false)

		wrapper.vm.headerActions[0].onSelect()
		await flushPromises()

		expect(wrapper.find('[data-stub="DeleteResourceDialog"]').attributes('data-name')).toBe('Desk 1')
	})

	it('opens the edit dialog with the hours and vacation editors once the schema loads', async () => {
		store.value = objectStore(RESOURCE, { properties: {} })
		http.get.mockResolvedValue({ data: {} })

		const wrapper = await page('r-1')
		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(store.value.fetchSchema).toHaveBeenCalledWith('appointmentResource')
		expect(wrapper.find('[data-part="CnFormDialog"]').exists()).toBe(true)
		expect(wrapper.find('[data-stub="ResourceHoursEditor"]').attributes('data-rows')).toBe('2')
		expect(wrapper.find('[data-stub="ResourceVacationsEditor"]').attributes('data-rows')).toBe('0')
	})

	it('opens no edit dialog and says so when the schema cannot be loaded', async () => {
		store.value = objectStore(RESOURCE, null)
		http.get.mockResolvedValue({ data: {} })

		const wrapper = await page('r-1')
		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(toasts.showError).toHaveBeenCalledWith('Could not load the resource form.')
		expect(wrapper.find('[data-part="CnFormDialog"]').exists()).toBe(false)
	})
})
