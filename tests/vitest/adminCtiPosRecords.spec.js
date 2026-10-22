// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The CTI event log and the POS sections of the admin page fit their card:
 * the event log and the tender types list each record as a row of labelled
 * values instead of a wide table, the POS roles open as cards, and the POS
 * staff table lets its headers wrap. Every value a column showed is kept.
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-5.6
 * @spec openspec/changes/pos-split-tender/tasks.md#8.1
 * @spec openspec/changes/nav-ia-cleanup/specs/nav-ia-cleanup/spec.md#requirement-admin-configuration-lives-on-the-admin-page
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosMock = vi.hoisted(() => ({ get: vi.fn(), delete: vi.fn() }))
const ctiMock = vi.hoisted(() => ({ getEventLog: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('../../src/services/ctiApi.js', () => ctiMock)
vi.mock('../../src/dialogs/CtiPayloadDialog.vue', () => ({
	default: { name: 'CtiPayloadDialog', render: () => h('div') },
}))
vi.mock('../../src/dialogs/ConfirmDialog.vue', () => ({
	default: { name: 'ConfirmDialog', render: () => h('div') },
}))

function stub(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['modelValue', 'options', 'name'],
		emits: ['update:modelValue', 'click'],
		render() {
			return h(tag, { 'data-stub': name }, [
				this.name,
				this.$slots.default?.(),
			])
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', 'button'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcSelect: stub('NcSelect'),
	NcSettingsSection: stub('NcSettingsSection', 'section'),
}))

globalThis.t = (app, text, vars = {}) =>
	String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)

const mountOptions = { global: { mocks: { t: globalThis.t } } }

const { default: CtiEventLog } =
	await import('../../src/views/settings/CtiEventLog.vue')
const { adminLists } = await import('../../src/views/settings/adminLists.js')

/**
 * The facts of one record as {label: text}.
 *
 * @param {object} record The record wrapper.
 * @return {object} Each dt's text mapped to its dd's text.
 */
function factsOf(record) {
	const facts = {}
	for (const pair of record.findAll('dl > div')) {
		facts[pair.find('dt').text()] = pair.find('dd').text()
	}
	return facts
}

describe('CTI event log', () => {
	it('lists an event with every value its table row showed', async () => {
		ctiMock.getEventLog.mockResolvedValue({
			events: [
				{
					id: 'e1',
					received_at: '2026-03-03T09:00:00+00:00',
					platform: 'asterisk',
					event_type: 'ended',
					external_call_id: 'call-3',
					signature_valid: false,
					processing_error: 'No contact for the number',
				},
			],
		})
		const wrapper = mount(CtiEventLog, mountOptions)
		await flushPromises()

		expect(wrapper.find('table').exists()).toBe(false)
		const event = wrapper.get('[data-testid="cti-event"]')
		expect(factsOf(event)).toEqual({
			'Received at': '2026-03-03T09:00:00+00:00',
			Platform: 'Asterisk',
			'Event type': 'Ended',
			'Call ID': 'call-3',
			Signature: '✗',
			Error: 'No contact for the number',
		})
		expect(event.get('button').text()).toBe('View payload')
	})

	it('says so when there are no events', async () => {
		ctiMock.getEventLog.mockResolvedValue({ events: [] })
		const wrapper = mount(CtiEventLog, mountOptions)
		await flushPromises()

		expect(wrapper.find('[data-testid="cti-event"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('No webhook events in the selected range.')
	})
})

describe('POS tender types, roles and staff', () => {
	const lists = adminLists((app, text) => text, (slug) => ({ register: 'pipelinq', schema: slug }), vi.fn())

	it('lists the tender types from their endpoint as cards, opening one in its form, with a delete that asks', () => {
		const list = lists.posTenderType
		expect(list).toMatchObject({ embedded: true, viewMode: 'cards', entitySource: 'endpoint', rowModal: 'PosTenderTypeFormDialog', createModal: 'PosTenderTypeFormDialog' })
		expect(list.sourceConfig).toEqual({ url: '/apps/pipelinq/api/pos/tender-types', rowsPath: 'results' })
		expect(list.columns.map((column) => column.label)).toEqual(['Name', 'Code', 'GL account', 'Active', 'Sort'])
		expect(list.actions.map((action) => action.label)).toEqual(['Delete'])
	})

	it('deletes a tender type through a dialog that names it and calls the endpoint', () => {
		const openModal = vi.fn()
		const withModal = adminLists((app, text) => text, () => undefined, openModal)
		withModal.posTenderType.actions[0].handler({ id: 'tt1', name: 'Pin', code: 'PIN' })
		const [name, props] = openModal.mock.calls[0]
		expect(name).toBe('CnActionDialog')
		expect(props.objectId).toBe('tt1')
		expect(props.config.summary.title).toBe('{name}')
		expect(props.config.confirm.request).toEqual({ method: 'DELETE', url: '/apps/pipelinq/api/pos/tender-types/@objectId' })
	})

	it('opens the POS roles as cards', () => {
		expect(lists.posRole).toMatchObject({ viewMode: 'cards', rowModal: 'PosRoleFormDialog', sourceConfig: { url: '/apps/pipelinq/api/pos/roles', rowsPath: 'roles' } })
	})

	it('names the POS role of a staff member, not its id', () => {
		const role = lists.posStaff.columns.find((column) => column.key === 'posRole')
		expect(role).toMatchObject({ widget: 'fkResolve', widgetProps: { register: 'pipelinq', schema: 'posRole', labelField: 'name' } })
		expect(lists.posStaff.rowModal).toBe('PosStaffFormDialog')
	})
})
