/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The client → contact cascade: a client change invalidates the contact, and
 * the inline-create promise a picker awaits settles exactly once.
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
 */
import { describe, expect, it } from 'vitest'
import mixin from '../../src/mixins/linkedPartyCascadeMixin.js'

/**
 * A host with the mixin's data, methods and computed bound to one object.
 *
 * @param {object} form The host's form.
 * @return {object} The host.
 */
function host (form = { client: null, contact: null }) {
	const vm = { ...mixin.data(), form }
	for (const [name, fn] of Object.entries(mixin.methods)) {
		vm[name] = fn.bind(vm)
	}
	Object.defineProperty(vm, 'contactFilters', { get: () => mixin.computed.contactFilters.call(vm) })
	return vm
}

describe('linkedPartyCascadeMixin', () => {
	it('starts with both dialogs closed and nothing pending', () => {
		expect(mixin.data()).toEqual({ clientDialogOpen: false, contactDialogOpen: false, pendingName: '', resolveCreate: null })
	})

	it('scopes the contact picker to the chosen client', () => {
		const vm = host({ client: 'c-1', contact: null })
		expect(vm.contactFilters).toEqual({ client: 'c-1' })
		vm.form.client = null
		expect(vm.contactFilters).toEqual({ client: null })
	})

	it('clears the contact when a different client is chosen', () => {
		const vm = host({ client: 'c-1', contact: 'p-1' })
		vm.onClientChange('c-2')
		expect(vm.form).toEqual({ client: 'c-2', contact: null })
	})

	it('keeps the contact when the same client is chosen again', () => {
		const vm = host({ client: 'c-1', contact: 'p-1' })
		vm.onClientChange('c-1')
		expect(vm.form).toEqual({ client: 'c-1', contact: 'p-1' })
	})

	it('stores a cleared client as null and drops the contact', () => {
		const vm = host({ client: 'c-1', contact: 'p-1' })
		vm.onClientChange('')
		expect(vm.form).toEqual({ client: null, contact: null })
	})

	it('opens the client dialog with the typed name and resolves with the created client', async () => {
		const vm = host({ client: 'old', contact: 'p-1' })
		const pending = vm.createClient('Acme')
		expect(vm.clientDialogOpen).toBe(true)
		expect(vm.pendingName).toBe('Acme')

		vm.onClientCreated('c-9', { name: 'Acme', id: 'ignored' })

		await expect(pending).resolves.toEqual({ name: 'Acme', id: 'c-9' })
		expect(vm.clientDialogOpen).toBe(false)
		expect(vm.form.contact).toBeNull()
		expect(vm.pendingName).toBe('')
		expect(vm.resolveCreate).toBeNull()
	})

	it('resolves with just the id when the client dialog hands no object over', async () => {
		const vm = host()
		const pending = vm.createClient(undefined)
		expect(vm.pendingName).toBe('')
		vm.onClientCreated('c-3')
		await expect(pending).resolves.toEqual({ id: 'c-3' })
	})

	it('resolves with null when the client dialog emits no id', async () => {
		const vm = host()
		const pending = vm.createClient('x')
		vm.onClientCreated('')
		await expect(pending).resolves.toBeNull()
	})

	it('resolves with null when the client dialog is closed', async () => {
		const vm = host()
		const pending = vm.createClient('x')
		vm.closeClientDialog()
		await expect(pending).resolves.toBeNull()
		expect(vm.clientDialogOpen).toBe(false)
	})

	it('opens the contact dialog and resolves with the created contact', async () => {
		const vm = host({ client: 'c-1', contact: null })
		const pending = vm.createContact('Jan')
		expect(vm.contactDialogOpen).toBe(true)
		expect(vm.pendingName).toBe('Jan')
		vm.onContactCreated({ id: 'p-2' })
		await expect(pending).resolves.toEqual({ id: 'p-2' })
		expect(vm.contactDialogOpen).toBe(false)
		expect(vm.form.client).toBe('c-1')
	})

	it('resolves with null when the contact dialog returns nothing or is closed', async () => {
		const vm = host()
		const first = vm.createContact('a')
		vm.onContactCreated(undefined)
		await expect(first).resolves.toBeNull()

		const second = vm.createContact('b')
		vm.closeContactDialog()
		await expect(second).resolves.toBeNull()
		expect(vm.contactDialogOpen).toBe(false)
	})

	it('settles the awaiting picker only once', async () => {
		const vm = host()
		const pending = vm.createContact('a')
		vm.settleCreate({ id: 'first' })
		vm.settleCreate({ id: 'second' })
		await expect(pending).resolves.toEqual({ id: 'first' })
		expect(vm.resolveCreate).toBeNull()
	})

	it('does nothing but reset when no picker is waiting', () => {
		const vm = host()
		vm.pendingName = 'left over'
		expect(() => vm.settleCreate(null)).not.toThrow()
		expect(vm.pendingName).toBe('')
	})
})
