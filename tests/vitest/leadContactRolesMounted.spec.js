// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contact roles on a lead as the screen shows them: one row per role,
 * decision maker first, the contact by name and linked, the role labelled,
 * and the add dialog and the remove button wired to the store.
 *
 * @spec openspec/specs/lead-management/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({ fetchCollection: vi.fn(), fetchObject: vi.fn(), saveObject: vi.fn(), deleteObject: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' },
	NcLoadingIcon: { template: '<i class="loading" />' },
}))
vi.mock('../../src/dialogs/AddContactRoleDialog.vue', () => ({
	default: { name: 'AddContactRoleDialog', props: ['contactOptions', 'roleOptions'], emits: ['submit', 'cancel', 'searchContacts'], template: '<div class="add-dialog" />' },
}))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))

globalThis.t = (_app, text) => text
const { default: LeadContactRoles } = await import('../../src/components/LeadContactRoles.vue')

const ROLES = [
	{ id: 'r1', toContact: 'c-eva', type: 'evaluator', notes: '' },
	{ id: 'r2', toContact: 'c-bas', type: 'beslisser', notes: 'Signs the order' },
]

async function roles() {
	const w = mount(LeadContactRoles, {
		props: { leadId: 'lead-1' },
		global: {
			mocks: { t: globalThis.t },
			stubs: { 'router-link': { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } },
		},
	})
	await flushPromises()
	return w
}

describe('LeadContactRoles on screen', () => {
	beforeEach(() => {
		Object.values(store).forEach((fn) => fn.mockReset())
		Object.values(dialogs).forEach((fn) => fn.mockReset())
		store.fetchCollection.mockResolvedValue(ROLES)
		store.fetchObject.mockImplementation(async (_type, id) => ({ 'c-eva': null, 'c-bas': { name: 'Bas Jansen' } }[id] ?? null))
	})

	it('lists the roles decision maker first, the contact by name and linked', async () => {
		const w = await roles()
		const rows = w.findAll('tr.viewTableRow').map((row) => row.findAll('td').map((cell) => cell.text()))

		expect(store.fetchCollection).toHaveBeenCalledWith('relationship', { _limit: 50, fromContact: 'lead-1', category: 'CRM Rol' })
		expect(rows).toEqual([
			['Bas Jansen', 'Decision maker', 'Signs the order', 'Remove'],
			['[Deleted]', 'Evaluator', '-', 'Remove'],
		])
		expect(JSON.parse(w.find('a.contact-link').attributes('data-to'))).toEqual({ name: 'ContactDetail', params: { id: 'c-bas' } })
		expect(w.find('.role-badge--beslisser').exists()).toBe(true)
	})

	it('says so when the lead has no roles', async () => {
		store.fetchCollection.mockResolvedValue([])
		const w = await roles()

		expect(w.text()).toContain('No contact roles assigned.')
		expect(w.find('table').exists()).toBe(false)
	})

	it('opens the add dialog with the role options, adds the role, and cancels', async () => {
		store.saveObject.mockResolvedValue({ id: 'r3' })
		const w = await roles()
		const add = w.findAll('button').find((button) => button.text() === 'Add contact role')

		await add.trigger('click')
		const dialog = w.findComponent({ name: 'AddContactRoleDialog' })
		expect(dialog.props('roleOptions').map((option) => option.value)).toContain('beslisser')

		dialog.vm.$emit('submit', { toContact: 'c-new', type: 'kampioen', notes: '' })
		await flushPromises()
		expect(store.saveObject).toHaveBeenCalledWith('relationship', expect.objectContaining({ fromContact: 'lead-1', toContact: 'c-new', type: 'kampioen', category: 'CRM Rol' }))
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Contact role added')
		expect(w.findComponent({ name: 'AddContactRoleDialog' }).exists()).toBe(false)

		await add.trigger('click')
		await w.findComponent({ name: 'AddContactRoleDialog' }).vm.$emit('cancel')
		expect(w.findComponent({ name: 'AddContactRoleDialog' }).exists()).toBe(false)
	})

	it('removes a role from its row once confirmed', async () => {
		const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
		store.deleteObject.mockResolvedValue(true)
		const w = await roles()

		await w.findAll('tr.viewTableRow')[0].find('button').trigger('click')
		await flushPromises()

		expect(confirm).toHaveBeenCalledWith('Remove this contact role from the lead?')
		expect(store.deleteObject).toHaveBeenCalledWith('relationship', 'r2')
		expect(store.fetchCollection).toHaveBeenCalledTimes(2)
		confirm.mockRestore()
	})
})
