// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The export job form's schedule dialog: delivery by mail to picked users and
 * groups, and the schedule in words with who it goes to. The object store,
 * export API, sharee search and toasts are mocked.
 *
 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md#REQ-RPD-003
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const store = vi.hoisted(() => ({
	fetchObject: vi.fn(),
	saveObject: vi.fn(),
	fetchCollection: vi.fn(),
	getCollection: vi.fn(),
}))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
const exportApi = vi.hoisted(() => ({ describeSchedule: vi.fn() }))
const searchAssignees = vi.hoisted(() => vi.fn())

vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('../../src/services/exportApi.js', () => ({ exportApi }))
vi.mock('../../src/services/taskUtils.js', () => ({ searchAssignees }))
vi.mock('../../src/modals/ExportTestRunModal.vue', () => ({
	default: { name: 'ExportTestRunModal', render: () => h('div') },
}))
vi.mock('@conduction/nextcloud-vue', () => {
	const stub = (name) => ({ name, inheritAttrs: false, render() { return h('div', { 'data-name': name }, this.$slots.default?.()) } })
	return { CnDetailCard: stub('CnDetailCard'), CnDetailPage: stub('CnDetailPage') }
})
vi.mock('@nextcloud/vue', () => {
	const stub = (name) => ({ name, inheritAttrs: false, render() { return h('div', { 'data-name': name }, this.$slots.default?.()) } })
	return { NcButton: stub('NcButton'), NcSelect: stub('NcSelect'), NcTextField: stub('NcTextField') }
})

const { default: ExportJobForm } = await import('../../src/views/export/ExportJobForm.vue')

const translate = (_app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_m, key) => vars[key] ?? '')

/**
 * Mount the form with a router mock.
 *
 * @param {object} params The route params.
 * @return {{wrapper: object, push: Function}} The wrapper and router push.
 */
function mountForm(params = {}) {
	const push = vi.fn()
	const wrapper = mount(ExportJobForm, {
		global: { mocks: { t: translate, $router: { push }, $route: { params } } },
	})
	return { wrapper, push }
}

const TEAM_LEADS = { id: 'teamleads', type: 'group', label: 'Team leads', icon: 'icon-group' }

beforeEach(() => {
	vi.useFakeTimers()
	for (const fn of [...Object.values(store), ...Object.values(dialogs), exportApi.describeSchedule, searchAssignees]) {
		fn.mockReset()
	}
	store.fetchCollection.mockResolvedValue(undefined)
	store.getCollection.mockReturnValue([])
	store.saveObject.mockResolvedValue({ id: 'saved-1' })
	exportApi.describeSchedule.mockResolvedValue({ expression: '0 7 * * 1', description: 'Every Monday at 07:00', valid: true })
})

afterEach(() => {
	vi.useRealTimers()
})

describe('ExportJobForm mail delivery', () => {
	it('shows the schedule in words once typing stops', async () => {
		const { wrapper } = mountForm()
		await flushPromises()
		wrapper.vm.model.scheduleCron = '0 7 * * 1'
		await wrapper.vm.$nextTick()
		expect(exportApi.describeSchedule).not.toHaveBeenCalledWith('0 7 * * 1')
		vi.advanceTimersByTime(300)
		await flushPromises()
		expect(exportApi.describeSchedule).toHaveBeenLastCalledWith('0 7 * * 1')
		expect(wrapper.vm.scheduleSummary).toBe('Every Monday at 07:00')
	})

	it('keeps the expression when it has no words', async () => {
		exportApi.describeSchedule.mockResolvedValue({ expression: 'x', description: '', valid: false })
		const { wrapper } = mountForm()
		vi.advanceTimersByTime(300)
		await flushPromises()
		expect(wrapper.vm.scheduleSummary).toBe('0 2 * * *')
	})

	it('reads the schedule with its recipients for a mailed report', async () => {
		searchAssignees.mockResolvedValue([TEAM_LEADS])
		const { wrapper } = mountForm()
		await flushPromises()
		wrapper.vm.model.scheduleCron = '0 7 * * 1'
		await wrapper.vm.$nextTick()
		vi.advanceTimersByTime(300)
		await flushPromises()
		wrapper.vm.delivery = 'mail'
		await wrapper.vm.onRecipientSearch('team')
		expect(searchAssignees).toHaveBeenCalledWith('team')
		expect(wrapper.vm.recipientOptions.map((o) => o.key)).toEqual(['group:teamleads'])
		wrapper.vm.onRecipientsSelect(wrapper.vm.recipientOptions)
		expect(wrapper.vm.recipients).toEqual([{ type: 'group', id: 'teamleads' }])
		expect(wrapper.vm.scheduleSummary).toBe('Every Monday at 07:00 to Team leads')
	})

	it('refuses to save a mailed report without recipients', async () => {
		const { wrapper } = mountForm()
		await flushPromises()
		wrapper.vm.model.name = 'Open tickets'
		wrapper.vm.delivery = 'mail'
		await wrapper.vm.save()
		expect(dialogs.showError).toHaveBeenCalledWith('Choose at least one recipient')
		expect(store.saveObject).not.toHaveBeenCalled()
	})

	it('saves a mail destination with the recipients and an enabled job that sends through it', async () => {
		const { wrapper, push } = mountForm()
		await flushPromises()
		wrapper.vm.model.name = 'Open tickets'
		wrapper.vm.delivery = 'mail'
		wrapper.vm.onRecipientsSelect([TEAM_LEADS])
		await wrapper.vm.save()
		expect(store.saveObject).toHaveBeenNthCalledWith(1, 'exportDestination', {
			name: 'Mail: Open tickets',
			type: 'mail',
			recipients: [{ type: 'group', id: 'teamleads' }],
		})
		const [schema, job] = store.saveObject.mock.calls[1]
		expect(schema).toBe('exportJob')
		expect(job.destinationId).toBe('saved-1')
		expect(job.enabled).toBe(true)
		expect(push).toHaveBeenCalledWith({ name: 'ExportJobs' })
	})

	it('opens an existing mailed job with its recipients and updates its destination', async () => {
		store.getCollection.mockReturnValue([
			{ id: 'w-1', name: 'Warehouse', type: 's3', validationStatus: 'valid' },
			{ id: 'm-1', name: 'Mail: Open tickets', type: 'mail', recipients: [{ type: 'user', id: 'anna' }] },
		])
		store.fetchObject.mockResolvedValue({ name: 'Open tickets', destinationId: 'm-1', enabled: false })
		const { wrapper } = mountForm({ id: 'job-1' })
		await flushPromises()
		expect(wrapper.vm.delivery).toBe('mail')
		expect(wrapper.vm.recipients).toEqual([{ type: 'user', id: 'anna' }])
		expect(wrapper.vm.destinationOptions.map((o) => o.id)).toEqual(['w-1'])
		await wrapper.vm.save()
		expect(store.saveObject.mock.calls[0][1]).toMatchObject({ id: 'm-1', type: 'mail' })
		const job = store.saveObject.mock.calls[1][1]
		expect(job.id).toBe('job-1')
		expect(job.destinationId).toBe('m-1')
		expect(job.enabled).toBe(false)
	})
})
