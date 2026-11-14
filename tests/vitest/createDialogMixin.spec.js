/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the create dialogs share: validity, saving and opening the created record.
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
 */
import { describe, expect, it, vi } from 'vitest'
import mixin from '../../src/mixins/createDialogMixin.js'

/**
 * @param {object|undefined} router The host's router.
 * @return {object} A host with the mixin bound and a spied `$emit`.
 */
function host (router) {
	const vm = { ...mixin.data(), $emit: vi.fn(), $router: router }
	vm.goToDetail = mixin.methods.goToDetail.bind(vm)
	return vm
}

describe('createDialogMixin', () => {
	it('starts invalid and not saving', () => {
		expect(mixin.data()).toEqual({ valid: false, saving: false })
	})

	it('closes and routes to the created record', () => {
		const router = { push: vi.fn(() => Promise.resolve()) }
		const vm = host(router)
		vm.goToDetail('LeadDetail', 'l-1')
		expect(vm.$emit).toHaveBeenCalledWith('close')
		expect(router.push).toHaveBeenCalledWith({ name: 'LeadDetail', params: { id: 'l-1' } })
	})

	it('swallows a rejected navigation', async () => {
		const rejected = Promise.reject(new Error('duplicated'))
		const catcher = vi.spyOn(rejected, 'catch')
		const router = { push: vi.fn(() => rejected) }
		host(router).goToDetail('LeadDetail', 'l-1')
		expect(catcher).toHaveBeenCalledTimes(1)
		await expect(catcher.mock.results[0].value).resolves.toBeUndefined()
	})

	it('only closes without an id', () => {
		const router = { push: vi.fn() }
		const vm = host(router)
		vm.goToDetail('LeadDetail', '')
		expect(vm.$emit).toHaveBeenCalledWith('close')
		expect(router.push).not.toHaveBeenCalled()
	})

	it('only closes without a router', () => {
		const vm = host(undefined)
		expect(() => vm.goToDetail('LeadDetail', 'l-1')).not.toThrow()
		expect(vm.$emit).toHaveBeenCalledWith('close')
	})
})
