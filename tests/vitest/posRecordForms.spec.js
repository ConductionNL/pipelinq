// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The POS role and staff forms on the admin page: what they load, what they
 * save and delete, and what they say when a request fails.
 *
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8.2
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const axios = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (m, k) => params[k]) }))
vi.mock('@nextcloud/vue', () => ({ NcButton: { template: '<button><slot /></button>' }, NcCheckboxRadioSwitch: { template: '<div><slot /></div>' }, NcLoadingIcon: { template: '<i />' }, NcSelect: { template: '<div />' }, NcTextField: { template: '<input />' } }))
vi.mock('../../src/dialogs/ConfirmDialog.vue', () => ({ default: { template: '<div />' } }))

globalThis.t = (app, text) => text
const global = { mocks: { t: globalThis.t } }

let PosRoleForm
let PosStaffForm

beforeAll(async () => {
	PosRoleForm = (await import('../../src/views/pos/PosRoleForm.vue')).default
	PosStaffForm = (await import('../../src/views/pos/PosStaffForm.vue')).default
}, 60000)

beforeEach(() => {
	for (const fn of Object.values(axios)) fn.mockReset()
})

describe('PosRoleForm', () => {
	it('loads an existing role into the form', async () => {
		axios.get.mockResolvedValue({ data: { role: { name: 'Kassa', canVoid: 1, maxDiscountPercent: '15', canNoSale: true } } })
		const wrapper = mount(PosRoleForm, { props: { id: 'r1' }, global })
		await flushPromises()
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/pos/roles/r1')
		expect(wrapper.vm.form).toEqual({ name: 'Kassa', description: '', canVoid: true, maxDiscountPercent: 15, canRefund: false, canNoSale: true })
		expect(wrapper.vm.loading).toBe(false)
	})

	it('loads nothing for a new role, and says why a load failed', async () => {
		mount(PosRoleForm, { global })
		await flushPromises()
		expect(axios.get).not.toHaveBeenCalled()
		axios.get.mockRejectedValueOnce({ response: { data: { error: 'Gone' } } })
		const failed = mount(PosRoleForm, { props: { id: 'r1' }, global })
		await flushPromises()
		expect(failed.vm.errorMessage).toBe('Gone')
		axios.get.mockResolvedValueOnce({})
		const empty = mount(PosRoleForm, { props: { id: 'r1' }, global })
		await flushPromises()
		expect(empty.vm.form.name).toBe('')
		axios.get.mockRejectedValueOnce(new Error('x'))
		const bare = mount(PosRoleForm, { props: { id: 'r1' }, global })
		await flushPromises()
		expect(bare.vm.errorMessage).toBe('Failed to load role')
	})

	it('posts a new role and puts an existing one, then is done', async () => {
		const created = mount(PosRoleForm, { global })
		created.vm.form.name = 'New'
		await created.vm.save()
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/pos/roles', { name: 'New', description: '', canVoid: false, maxDiscountPercent: 0, canRefund: false, canNoSale: false })
		expect(created.emitted('done')).toHaveLength(1)
		axios.get.mockResolvedValue({ data: { role: { name: 'Old' } } })
		const edited = mount(PosRoleForm, { props: { id: 'r2' }, global })
		await flushPromises()
		edited.vm.errorMessage = 'stale'
		await edited.vm.save()
		expect(axios.put).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/pos/roles/r2', expect.objectContaining({ name: 'Old' }))
		expect(edited.vm.errorMessage).toBe('')
		expect(edited.vm.saving).toBe(false)
	})

	it('says why a save or delete failed and stays', async () => {
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'Taken' } } }).mockRejectedValueOnce(new Error('x'))
		const wrapper = mount(PosRoleForm, { global })
		await wrapper.vm.save()
		expect(wrapper.vm.errorMessage).toBe('Taken')
		await wrapper.vm.save()
		expect(wrapper.vm.errorMessage).toBe('Failed to save role')
		expect(wrapper.emitted('done')).toBeUndefined()
		axios.get.mockResolvedValue({ data: {} })
		const existing = mount(PosRoleForm, { props: { id: 'r3' }, global })
		await flushPromises()
		axios.delete.mockRejectedValueOnce({ response: { data: { error: 'In use' } } }).mockRejectedValueOnce(new Error('x')).mockResolvedValueOnce({})
		existing.vm.confirmDelete()
		expect(existing.vm.showDeleteConfirm).toBe(true)
		await existing.vm.performDelete()
		expect(existing.vm.showDeleteConfirm).toBe(false)
		expect(existing.vm.errorMessage).toBe('In use')
		await existing.vm.performDelete()
		expect(existing.vm.errorMessage).toBe('Failed to delete role')
		await existing.vm.performDelete()
		expect(axios.delete).toHaveBeenLastCalledWith('/index.php/apps/pipelinq/api/pos/roles/r3')
		expect(existing.vm.errorMessage).toBe('')
		expect(existing.emitted('done')).toHaveLength(1)
		existing.vm.goBack()
		expect(existing.emitted('done')).toHaveLength(2)
	})
})

describe('PosStaffForm', () => {
	it('loads the roles, then an existing staff member without the PIN', async () => {
		axios.get.mockImplementation(async (url) => (url.endsWith('/roles')
			? { data: { roles: [{ id: 'r1', name: 'Kassa' }, { id: 'r2' }] } }
			: { data: { staff: { displayName: 'Anna', posRole: 'r1', isActive: false, pin: '1234' } } }))
		const wrapper = mount(PosStaffForm, { props: { id: 's1' }, global })
		await flushPromises()
		expect(axios.get.mock.calls).toEqual([['/index.php/apps/pipelinq/api/pos/roles'], ['/index.php/apps/pipelinq/api/pos/staff/s1']])
		expect(wrapper.vm.roleOptions).toEqual([{ value: 'r1', label: 'Kassa' }, { value: 'r2', label: 'r2' }])
		expect(wrapper.vm.form).toEqual({ displayName: 'Anna', userId: '', posRole: 'r1', pin: '', isActive: false })
	})

	it('reads no roles on a failure, and says why a load failed', async () => {
		axios.get.mockRejectedValueOnce(new Error('x')).mockRejectedValueOnce(new Error('y'))
		const wrapper = mount(PosStaffForm, { props: { id: 's1' }, global })
		await flushPromises()
		expect(wrapper.vm.roles).toEqual([])
		expect(wrapper.vm.errorMessage).toBe('Failed to load staff')
		expect(wrapper.vm.loading).toBe(false)
	})

	it('saves the staff member with the PIN only when one is typed', async () => {
		axios.get.mockResolvedValue({ data: {} })
		const created = mount(PosStaffForm, { global })
		await flushPromises()
		Object.assign(created.vm.form, { displayName: 'Bob', posRole: 'r1', pin: '1234' })
		await created.vm.save()
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/pos/staff', { displayName: 'Bob', userId: '', posRole: 'r1', isActive: true, pin: '1234' })
		const edited = mount(PosStaffForm, { props: { id: 's2' }, global })
		await flushPromises()
		Object.assign(edited.vm.form, { displayName: 'Bob', posRole: 'r1' })
		await edited.vm.save()
		expect(axios.put).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/pos/staff/s2', { displayName: 'Bob', userId: '', posRole: 'r1', isActive: true })
		expect(edited.emitted('done')).toHaveLength(1)
		axios.put.mockRejectedValueOnce(new Error('x'))
		await edited.vm.save()
		expect(edited.vm.errorMessage).toBe('Failed to save staff')
		axios.delete.mockRejectedValueOnce(new Error('x'))
		await edited.vm.performDelete()
		expect(edited.vm.errorMessage).toBe('Failed to delete staff')
		axios.delete.mockResolvedValueOnce({})
		await edited.vm.performDelete()
		expect(axios.delete).toHaveBeenLastCalledWith('/index.php/apps/pipelinq/api/pos/staff/s2')
		expect(edited.emitted('done')).toHaveLength(2)
	})
})
