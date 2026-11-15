// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The POS role list: the list view it is built on, the rights table it draws,
 * and what its row actions emit.
 */
import { mount } from '@vue/test-utils'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'

const listView = vi.hoisted(() => ({ state: null, useListView: vi.fn() }))
const objectStore = vi.hoisted(() => ({ name: 'objectStore' }))

vi.mock('@conduction/nextcloud-vue', () => ({ useListView: (...args) => listView.useListView(...args) }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { emits: ['click'], template: '<button class="edit" @click="$emit(\'click\')"><slot /></button>' },
	NcLoadingIcon: { template: '<i class="loading" />' },
}))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => objectStore }))

let PosRoleList

beforeAll(async () => {
	globalThis.t = (app, text) => text
	PosRoleList = (await import('../../src/views/pos/PosRoleList.vue')).default
}, 60000)

beforeEach(() => {
	listView.state = reactive({ objects: [], loading: false })
	listView.useListView.mockReset()
	listView.useListView.mockImplementation(() => listView.state)
})

/**
 * Mount with a sidebar provided.
 *
 * @return {object} The wrapper.
 */
function list() {
	return mount(PosRoleList, { global: { provide: { sidebarState: { open: false } }, mocks: { t: globalThis.t } } })
}

describe('PosRoleList', () => {
	it('builds on the posRole list view with the injected sidebar and the object store', () => {
		list()
		expect(listView.useListView).toHaveBeenCalledWith('posRole', { sidebarState: { open: false }, objectStore })
	})

	it('draws one row per role with its rights and discount', () => {
		listView.state.objects = [
			{ id: 'r-1', name: 'Kassier', canVoid: true, canRefund: false, maxDiscountPercent: '10' },
			{ id: 'r-2', name: 'Stagiair', canNoSale: 'true' },
		]
		const rows = list().findAll('[data-testid="pos-role"]').map((row) => row.findAll('td').slice(0, 5).map((cell) => cell.text()))

		expect(rows).toEqual([
			['Kassier', 'Yes', 'No', 'No', '10%'],
			['Stagiair', 'No', 'No', 'No', '0%'],
		])
	})

	it('shows loading only while nothing is listed, and an empty line otherwise', async () => {
		listView.state.loading = true
		const w = list()
		expect(w.find('.loading').exists()).toBe(true)

		listView.state.loading = false
		await w.vm.$nextTick()
		expect(w.find('.pos-role-list__empty').text()).toBe('No POS roles defined yet')
	})

	it('emits edit with the row id, and create for a new role', async () => {
		listView.state.objects = [{ id: 'r-1', name: 'Kassier' }]
		const w = list()

		await w.find('button.edit').trigger('click')
		expect(w.emitted('edit')).toEqual([['r-1']])

		w.vm.createNew()
		expect(w.emitted('create')).toHaveLength(1)
	})
})
