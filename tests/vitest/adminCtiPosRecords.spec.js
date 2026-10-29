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
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosMock = vi.hoisted(() => ({ get: vi.fn(), delete: vi.fn() }))
const ctiMock = vi.hoisted(() => ({ getEventLog: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@conduction/nextcloud-vue', async () => ({ CnDataTable: (await import('./support/cnDataTableStub.js')).CnDataTableStub }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('../../src/services/ctiApi.js', () => ctiMock)
vi.mock('../../src/dialogs/CtiPayloadDialog.vue', () => ({
	default: { name: 'CtiPayloadDialog', render: () => h('div') },
}))
vi.mock('../../src/dialogs/ConfirmDialog.vue', () => ({
	default: { name: 'ConfirmDialog', render: () => h('div') },
}))
vi.mock('../../src/modals/PosTenderTypeFormDialog.vue', () => ({
	default: { name: 'PosTenderTypeFormDialog', render: () => h('div') },
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
const { default: PosTenderTypeList } =
	await import('../../src/views/pos/PosTenderTypeList.vue')

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

describe('POS tender types', () => {
	it('lists a tender type as a table row: name, code, GL account, flags, state and actions', async () => {
		axiosMock.get.mockResolvedValue({
			data: {
				results: [
					{
						id: 'tt1',
						name: 'Pin',
						code: 'PIN',
						glAccount: '1100',
						requiresPin: true,
						allowsChange: false,
						isActive: true,
						sortOrder: 2,
					},
				],
			},
		})
		const wrapper = mount(PosTenderTypeList, mountOptions)
		await flushPromises()

		// One table row per tender type, as the board draws it (pl2 PqBeheerKassa),
		// sorted and filtered from its own heads.
		const table = wrapper.get('[data-stub="CnDataTable"]')
		expect(table.attributes('data-filterable')).toBe('true')
		expect(table.attributes('data-local-headers')).toBe('true')
		const type = wrapper.get('.pos-tender-type-list__row')
		expect(type.element.tagName).toBe('TR')
		expect(type.findAll('td').map((c) => c.text()).slice(0, 5)).toEqual(['Pin', 'PIN', '1100', 'PIN', 'Active'])
		const actions = type
			.findAll('.pos-tender-type-list__row-actions button')
			.map((b) => b.text())
		expect(actions).toEqual(['Edit', 'Delete'])
	})
})

describe('POS roles and staff', () => {
	it('lists the POS roles as a table of their rights, as the board draws it', () => {
		const source = readFileSync(
			resolve(__dirname, '../../src/views/pos/PosRoleList.vue'),
			'utf8',
		)
		expect(source).toContain('<table v-else-if="objects.length" class="pos-role-list__table">')
		expect(source).toContain("t('pipelinq', 'May open the drawer')")
		expect(source).not.toContain('CnIndexPage')
	})

	it('adds a tender type, role or staff member from the section title row', () => {
		for (const file of ['PosTenderTypeManager', 'PosRoleManager', 'PosStaffManager']) {
			const source = readFileSync(resolve(__dirname, `../../src/views/settings/${file}.vue`), 'utf8')
			expect(source).toContain('<template #actions>')
			expect(source).toContain('<CnSettingsSection')
		}
	})

	it('lets the POS staff headers wrap and drops the doubled padding', () => {
		const source = readFileSync(
			resolve(__dirname, '../../src/views/pos/PosStaffList.vue'),
			'utf8',
		)
		const root = source.match(/\.pos-staff-list \{[^}]*\}/)[0]
		expect(root).not.toMatch(/padding/)
		expect(source).toMatch(/white-space: normal/)
	})
})
