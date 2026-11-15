/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The form dialog of an admin record section.
 *
 * @spec openspec/specs/nav-ia-cleanup/spec.md#requirement-admin-configuration-lives-on-the-admin-page
 */
import { describe, expect, it } from 'vitest'
import mixin from '../../src/mixins/recordDialogMixin.js'

/**
 * @return {object} A host with the mixin's data and methods bound.
 */
function host () {
	const vm = { ...mixin.data() }
	for (const [name, fn] of Object.entries(mixin.methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

describe('recordDialogMixin', () => {
	it('starts closed with no record', () => {
		expect(mixin.data()).toEqual({ dialogOpen: false, editingId: '', reloadKey: 0 })
	})

	it('opens an existing record', () => {
		const vm = host()
		vm.openRecord('r-1')
		expect(vm.dialogOpen).toBe(true)
		expect(vm.editingId).toBe('r-1')
		expect(vm.reloadKey).toBe(0)
	})

	it('opens an empty form, dropping a record opened before', () => {
		const vm = host()
		vm.openRecord('r-1')
		vm.openNew()
		expect(vm.dialogOpen).toBe(true)
		expect(vm.editingId).toBe('')
	})

	it('closes and bumps the reload key on every close', () => {
		const vm = host()
		vm.openRecord('r-1')
		vm.closeDialog()
		expect(vm).toMatchObject({ dialogOpen: false, editingId: '', reloadKey: 1 })
		vm.openNew()
		vm.closeDialog()
		expect(vm.reloadKey).toBe(2)
	})
})
