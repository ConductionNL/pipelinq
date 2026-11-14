// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The POS role and staff admin surfaces as a user drives them: typing into a
 * form's fields fills the record it saves, cancelling a delete closes the
 * confirmation, and a manager section opens its form dialog for a new or an
 * existing record and refreshes its list when the dialog is done.
 *
 * @spec openspec/changes/archive/2026-06-14-pos-staff-pin-permissions/tasks.md#8.2
 * @spec openspec/specs/nav-ia-cleanup/spec.md#requirement-admin-configuration-lives-on-the-admin-page
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const axios = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (m, k) => params[k]) }))

/**
 * A stand-in for a Nextcloud input that reports a new value the way the real one does.
 *
 * @param {string} name The component name.
 * @return {object} The component.
 */
function field (name) {
  return {
	name,
	props: ['modelValue', 'label', 'reduce'],
	emits: ['update:modelValue'],
	template: '<div><slot /></div>',
}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' },
	NcCheckboxRadioSwitch: field('NcCheckboxRadioSwitch'),
	NcLoadingIcon: { template: '<i />' },
	NcSelect: field('NcSelect'),
	NcTextField: field('NcTextField'),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnSettingsSection: { name: 'CnSettingsSection', template: '<section><slot name="actions" /><slot /></section>' },
}))
vi.mock('vue-material-design-icons/Plus.vue', () => ({ default: { template: '<i />' } }))
vi.mock('../../src/dialogs/ConfirmDialog.vue', () => ({ default: { name: 'ConfirmDialog', emits: ['confirm', 'cancel'], template: '<div />' } }))
vi.mock('../../src/dialogs/PosRoleFormDialog.vue', () => ({ default: { name: 'PosRoleFormDialog', props: ['roleId'], emits: ['done'], template: '<div />' } }))
vi.mock('../../src/dialogs/PosStaffFormDialog.vue', () => ({ default: { name: 'PosStaffFormDialog', props: ['staffId'], emits: ['done'], template: '<div />' } }))
vi.mock('../../src/views/pos/PosRoleList.vue', () => ({ default: { name: 'PosRoleList', emits: ['edit', 'create'], template: '<div />' } }))
vi.mock('../../src/views/pos/PosStaffList.vue', () => ({ default: { name: 'PosStaffList', emits: ['edit', 'create'], template: '<div />' } }))

globalThis.t = (app, text) => text
const global = { mocks: { t: globalThis.t } }

let PosRoleForm
let PosStaffForm
let PosRoleManager
let PosStaffManager

beforeAll(async () => {
	PosRoleForm = (await import('../../src/views/pos/PosRoleForm.vue')).default
	PosStaffForm = (await import('../../src/views/pos/PosStaffForm.vue')).default
	PosRoleManager = (await import('../../src/views/settings/PosRoleManager.vue')).default
	PosStaffManager = (await import('../../src/views/settings/PosStaffManager.vue')).default
}, 60000)

beforeEach(() => {
	for (const fn of Object.values(axios)) fn.mockReset()
	axios.get.mockResolvedValue({ data: {} })
})

/**
 * Report a value from the input whose label is given.
 *
 * @param {object} wrapper The mounted form.
 * @param {string} name    The input component name.
 * @param {number} index   Which of those inputs.
 * @param {*}      value   The value typed or chosen.
 * @return {Promise<void>}
 */
async function enter(wrapper, name, index, value) {
	await wrapper.findAllComponents({ name }).at(index).vm.$emit('update:modelValue', value)
}

describe('PosRoleForm fields', () => {
	it('fills the role it saves from what is entered', async () => {
		const wrapper = mount(PosRoleForm, { global })
		await flushPromises()
		await enter(wrapper, 'NcTextField', 0, 'Supervisor')
		await enter(wrapper, 'NcTextField', 1, 'Shift lead')
		await enter(wrapper, 'NcCheckboxRadioSwitch', 0, true)
		await enter(wrapper, 'NcCheckboxRadioSwitch', 1, true)
		await enter(wrapper, 'NcCheckboxRadioSwitch', 2, true)
		await wrapper.find('input[type="range"]').setValue('25')

		expect(wrapper.vm.form).toEqual({ name: 'Supervisor', description: 'Shift lead', canVoid: true, canRefund: true, canNoSale: true, maxDiscountPercent: 25 })
		await wrapper.vm.save()
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/pos/roles', { name: 'Supervisor', description: 'Shift lead', canVoid: true, maxDiscountPercent: 25, canRefund: true, canNoSale: true })
	})

	it('closes the delete confirmation on cancel without deleting', async () => {
		axios.get.mockResolvedValue({ data: { role: { name: 'Kassa' } } })
		const wrapper = mount(PosRoleForm, { props: { id: 'r1' }, global })
		await flushPromises()
		wrapper.vm.confirmDelete()
		await wrapper.vm.$nextTick()
		await wrapper.findComponent({ name: 'ConfirmDialog' }).vm.$emit('cancel')

		expect(wrapper.vm.showDeleteConfirm).toBe(false)
		expect(wrapper.findComponent({ name: 'ConfirmDialog' }).exists()).toBe(false)
		expect(axios.delete).not.toHaveBeenCalled()
	})
})

describe('PosStaffForm fields', () => {
	it('fills the staff member it saves, with the chosen role reduced to its id', async () => {
		axios.get.mockResolvedValue({ data: { roles: [{ id: 'r1', name: 'Kassa' }] } })
		const wrapper = mount(PosStaffForm, { global })
		await flushPromises()
		await enter(wrapper, 'NcTextField', 0, 'Anna de Vries')
		await enter(wrapper, 'NcTextField', 1, 'anna.devries')
		await enter(wrapper, 'NcSelect', 0, 'r1')
		await enter(wrapper, 'NcTextField', 2, '1234')
		await enter(wrapper, 'NcCheckboxRadioSwitch', 0, false)

		const select = wrapper.findComponent({ name: 'NcSelect' })
		expect(select.props('reduce')({ value: 'r1', label: 'Kassa' })).toBe('r1')
		expect(wrapper.vm.form).toEqual({ displayName: 'Anna de Vries', userId: 'anna.devries', posRole: 'r1', pin: '1234', isActive: false })
		expect(wrapper.vm.canSave).toBe(true)
	})

	it('closes the delete confirmation on cancel without deleting', async () => {
		axios.get.mockResolvedValue({ data: { staff: { displayName: 'Anna' }, roles: [] } })
		const wrapper = mount(PosStaffForm, { props: { id: 's1' }, global })
		await flushPromises()
		wrapper.vm.confirmDelete()
		await wrapper.vm.$nextTick()
		await wrapper.findComponent({ name: 'ConfirmDialog' }).vm.$emit('cancel')

		expect(wrapper.vm.showDeleteConfirm).toBe(false)
		expect(axios.delete).not.toHaveBeenCalled()
	})
})

describe.each([
	['PosRoleManager', () => PosRoleManager, 'PosRoleList', 'PosRoleFormDialog', 'roleId'],
	['PosStaffManager', () => PosStaffManager, 'PosStaffList', 'PosStaffFormDialog', 'staffId'],
])('%s', (label, component, list, dialog, idProp) => {
	it('opens an empty form from the title-row button', async () => {
		const wrapper = mount(component(), { global })
		expect(wrapper.findComponent({ name: dialog }).exists()).toBe(false)
		await wrapper.find('button').trigger('click')

		expect(wrapper.findComponent({ name: dialog }).props(idProp)).toBe('')
	})

	it('opens a record the list asks for, and refreshes the list when the dialog is done', async () => {
		const wrapper = mount(component(), { global })
		await wrapper.findComponent({ name: list }).vm.$emit('edit', 'rec-7')
		expect(wrapper.findComponent({ name: dialog }).props(idProp)).toBe('rec-7')

		await wrapper.findComponent({ name: dialog }).vm.$emit('done')
		expect(wrapper.findComponent({ name: dialog }).exists()).toBe(false)
		expect(wrapper.vm.reloadKey).toBe(1)

		await wrapper.findComponent({ name: list }).vm.$emit('create')
		expect(wrapper.findComponent({ name: dialog }).props(idProp)).toBe('')
	})
})
