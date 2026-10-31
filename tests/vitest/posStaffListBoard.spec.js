// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The till staff table as the board draws it: a sort and a filter in every
 * head, the last login as a readable moment, and the PIN rule under the table.
 *
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8.3
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosGet = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: { get: axiosGet } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('@conduction/nextcloud-vue', async () => ({ CnDataTable: (await import('./support/cnDataTableStub.js')).CnDataTableStub }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', render() { return h('button', this.$slots.default?.()) } },
	NcLoadingIcon: { name: 'NcLoadingIcon', render() { return h('span') } },
}))

globalThis.t = (app, text, vars = {}) =>
	String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)

const { default: PosStaffList } = await import('../../src/views/pos/PosStaffList.vue')

describe('the till staff table', () => {
	it('reads the last login as a moment and states the PIN rule', async () => {
		axiosGet.mockImplementation(async (url) => ({
			data: url.includes('/roles')
				? { roles: [{ id: 'r1', name: 'Kassamedewerker' }] }
				: { staff: [{ id: 's1', displayName: 'Fatma Yildiz', posRole: 'r1', isActive: true, lastLoginAt: '2026-10-06T09:00:00' }] },
		}))
		const wrapper = mount(PosStaffList, { global: { mocks: { t: globalThis.t } } })
		await flushPromises()

		const table = wrapper.get('[data-stub="CnDataTable"]')
		expect(table.attributes('data-filterable')).toBe('true')
		expect(table.attributes('data-local-headers')).toBe('true')
		expect(wrapper.findAll('th').map((th) => th.text()).slice(0, 4)).toEqual(['Display name', 'Role', 'Active', 'Last login'])

		const cells = wrapper.findAll('.pos-staff-list__row td').map((td) => td.text())
		expect(cells[0]).toBe('Fatma Yildiz')
		expect(cells[1]).toBe('Kassamedewerker')
		expect(cells[2]).toBe('Yes')
		expect(cells[3]).not.toContain('2026-10-06T')
		expect(cells[3]).toMatch(/09[:.]00/)
		expect(cells[4]).toBe('Edit')
		await wrapper.get('.pos-staff-list__row button').trigger('click')
		expect(wrapper.emitted('edit')).toEqual([['s1']])
		expect(wrapper.text()).toContain('Every staff member signs in at the till with a PIN of 4 to 6 digits.')
	})
})
